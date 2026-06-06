<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\User;
use App\Services\BullionVaultService;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportAuditService;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\GoldBalancesDataset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 4 Owner — Gold Balances (final Phase 4 report). Vault fine-weight holdings
 * by metal/purity. Wraps the canonical BullionVaultService::vaultBalances() — no
 * second balance engine — so the grand total equals SUM(metal_lots.fine_weight_remaining),
 * the authoritative figure vault:reconcile certifies.
 */
class GoldBalancesReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function lot(int $shopId, string $metalType, float $purity, float $remaining): void
    {
        DB::table('metal_lots')->insert([
            'shop_id' => $shopId, 'source' => 'purchase', 'metal_type' => $metalType, 'purity' => $purity,
            'fine_weight_total' => $remaining, 'fine_weight_remaining' => $remaining,
            'cost_per_fine_gram' => 6000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** gold 22k: 50+30=80 · gold 18k: 20 · silver 92.5: 100 → grand total 200. */
    private function seedShop(int $shopId): void
    {
        $this->lot($shopId, 'gold', 22.0, 50.0);
        $this->lot($shopId, 'gold', 22.0, 30.0);
        $this->lot($shopId, 'gold', 18.0, 20.0);
        $this->lot($shopId, 'silver', 92.5, 100.0);
    }

    private function request(int $shopId, F $format, int $userId = 1): ReportRequest
    {
        $def = app(ReportRegistry::class)->definition(GoldBalancesDataset::KEY);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(true);
        $keys = app(ColumnPolicy::class)->resolve($def, P::Detailed, $user)->columnKeys;

        return new ReportRequest(
            definition: $def, shopId: $shopId, userId: $userId, userName: 'Owner', profile: P::Detailed,
            format: $format, filters: [], columnKeys: $keys,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: $r->definition->key, reportVersion: $r->definition->version, title: $r->definition->title,
            profileLabel: 'Detailed', format: $r->format->value, filtersApplied: [],
            periodLabel: 'As on date', shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null,
            shopStateCode: null, generatedByName: 'Owner', generatedAt: now(), generatorTag: 'test',
        );
    }

    private function build(int $shopId)
    {
        $r = $this->request($shopId, F::Csv);

        return TenantContext::runFor($shopId, fn () => app(ReportRegistry::class)->datasetService(GoldBalancesDataset::KEY)->build($r, $this->meta($r)));
    }

    private function onHand(array $rows, string $metal, float $purity): ?float
    {
        foreach ($rows as $row) {
            if ($row['metal'] === $metal && abs((float) $row['purity'] - $purity) < 0.001) {
                return (float) $row['on_hand'];
            }
        }
        return null;
    }

    // ---- VAULT BALANCE RECONCILIATION ----------------------------------

    public function test_reconciles_to_vault_balance_source(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        $ds = $this->build($shop->id);
        $section = $ds->section('balances');
        $rows = $section->rows;

        // Per-metal/purity rollups.
        $this->assertEqualsWithDelta(80.0, $this->onHand($rows, 'Gold', 22.0), 0.0001);
        $this->assertEqualsWithDelta(20.0, $this->onHand($rows, 'Gold', 18.0), 0.0001);
        $this->assertEqualsWithDelta(100.0, $this->onHand($rows, 'Silver', 92.5), 0.0001);

        $grandTotal = (float) $section->totals['on_hand'];

        // GOLD-1 — report total == SUM(metal_lots.fine_weight_remaining).
        $rawSum = (float) DB::table('metal_lots')->where('shop_id', $shop->id)->sum('fine_weight_remaining');
        $this->assertEqualsWithDelta(200.0, $rawSum, 0.0001);
        $this->assertEqualsWithDelta($rawSum, $grandTotal, 0.0001, 'report total == raw SUM');

        // GOLD-2 — report total == vault:reconcile source (same authoritative SUM).
        $vaultReconcile = (float) collect(DB::select(
            "SELECT COALESCE(SUM(t),0) s FROM (SELECT SUM(fine_weight_remaining) t FROM metal_lots WHERE shop_id=? GROUP BY metal_type) g",
            [$shop->id]
        ))[0]->s;
        $this->assertEqualsWithDelta($vaultReconcile, $grandTotal, 0.0001, 'report total == vault:reconcile source');

        // GOLD-3 — per-metal rollups sum to the grand total.
        $rollup = (float) collect($rows)->sum('on_hand');
        $this->assertEqualsWithDelta($grandTotal, $rollup, 0.0001, 'rollups == grand total');

        // Tie to the canonical engine directly.
        $svc = TenantContext::runFor($shop->id, fn () => app(BullionVaultService::class)->vaultBalances($shop->id));
        $this->assertEqualsWithDelta((float) $svc->sum('in_vault_fine'), $grandTotal, 0.0001);
    }

    // ---- VALIDATOR (reports:validate GOLD-1/2/3) -----------------------

    public function test_reports_validate_gold_path_passes(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        $this->artisan('reports:validate', ['--shop' => $shop->id])
            ->expectsOutputToContain('GOLD-1 report total == SUM(metal_lots.fine_weight_remaining)')
            ->expectsOutputToContain('GOLD-2 report total == vault:reconcile source total')
            ->expectsOutputToContain('GOLD-3 per-metal rollups == grand total')
            ->assertExitCode(0);
    }

    // ---- EXPORTS / PARITY + AUDIT --------------------------------------

    public function test_all_file_formats_render(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
            $request = $this->request($shop->id, $format);
            $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));
            $this->assertGreaterThan(0, $result->output->byteSize(), $format->value);
        }
    }

    public function test_export_writes_audit_row(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        $request = $this->request($shop->id, F::Csv, (int) $owner->id);
        TenantContext::runFor($shop->id, function () use ($request) {
            $result = app(ExportPipeline::class)->run($request, $this->meta($request));
            $export = app(ExportAuditService::class)->recordSync($request, sensitiveIncluded: false, rowCount: $result->rowCount);
            $this->assertSame('gold-balances', $export->report_key);
        });

        $this->assertDatabaseHas('report_exports', ['report_key' => 'gold-balances', 'shop_id' => $shop->id]);
    }

    // ---- PERMISSIONS (reports.view gate) -------------------------------

    public function test_screen_requires_reports_view_permission(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->detach(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/gold'))->assertForbidden();

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/gold'))
            ->assertOk()->assertSee('Gold Balances');
    }

    // ---- TENANT ISOLATION + PERFORMANCE --------------------------------

    public function test_tenant_isolation_and_query_bounded(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $this->seedShop($shopA->id);
        // B noise: a huge lot that must never reach A's balances.
        $this->lot($shopB->id, 'gold', 24.0, 999999.0);

        DB::enableQueryLog();
        $ds = $this->build($shopA->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEqualsWithDelta(200.0, (float) $ds->section('balances')->totals['on_hand'], 0.0001, 'A must not see B');
        $this->assertCount(3, $ds->section('balances')->rows);
        $this->assertLessThanOrEqual(8, $queries, "Expected bounded queries, got {$queries}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
