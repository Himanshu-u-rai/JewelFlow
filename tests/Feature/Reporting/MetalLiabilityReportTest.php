<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\User;
use App\Reporting\ReceivablesService;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\MetalLiabilityDataset;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 3 Accounting — Metal Liability (final report). Customer-advance gold owed
 * vs gold on hand. Wraps ReceivablesService::metalLiability() verbatim; the
 * on-hand figure uses the same source as vault:reconcile
 * (SUM(metal_lots.fine_weight_remaining)).
 */
class MetalLiabilityReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function goldTxn(int $shopId, int $customerId, string $type, float $fineGold): void
    {
        DB::table('customer_gold_transactions')->insert([
            'shop_id' => $shopId, 'customer_id' => $customerId, 'fine_gold' => $fineGold,
            'gross_weight' => $fineGold, 'purity' => 24, 'type' => $type,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function lot(int $shopId, string $source, float $remaining): void
    {
        DB::table('metal_lots')->insert([
            'shop_id' => $shopId, 'source' => $source, 'purity' => 24,
            'fine_weight_total' => $remaining, 'fine_weight_remaining' => $remaining,
            'cost_per_fine_gram' => 6000, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Liability 30 (customer_advance lot) · deposited 35 (20+5 c1, 10 c2) ·
     * old-gold 8 · on-hand 130 (30 advance + 100 purchase).
     */
    private function seedShop(int $shopId): void
    {
        $c1 = $this->createCustomer($shopId)->id;
        $c2 = $this->createCustomer($shopId)->id;
        $this->goldTxn($shopId, $c1, 'advance', 20.0);
        $this->goldTxn($shopId, $c1, 'advance', 5.0);
        $this->goldTxn($shopId, $c2, 'advance', 10.0);
        $this->goldTxn($shopId, $c1, 'old_metal_in', 8.0);
        $this->lot($shopId, 'customer_advance', 30.0);
        $this->lot($shopId, 'purchase', 100.0);
    }

    private function request(int $shopId, F $format, bool $includeSensitive = true): ReportRequest
    {
        $def = app(ReportRegistry::class)->definition(MetalLiabilityDataset::KEY);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(true);
        $keys = app(ColumnPolicy::class)->resolve($def, P::Detailed, $user, includeSensitive: $includeSensitive)->columnKeys;

        return new ReportRequest(
            definition: $def, shopId: $shopId, userId: 1, userName: 'Manager', profile: P::Detailed,
            format: $format, filters: [], columnKeys: $keys, includeSensitive: $includeSensitive,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: $r->definition->key, reportVersion: $r->definition->version, title: $r->definition->title,
            profileLabel: 'Detailed', format: $r->format->value, filtersApplied: [],
            periodLabel: 'As on date', shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null,
            shopStateCode: null, generatedByName: 'Manager', generatedAt: now(), generatorTag: 'test',
        );
    }

    private function build(int $shopId, bool $includeSensitive = true)
    {
        $r = $this->request($shopId, F::Csv, $includeSensitive);

        return TenantContext::runFor($shopId, fn () => app(ReportRegistry::class)->datasetService(MetalLiabilityDataset::KEY)->build($r, $this->meta($r)));
    }

    private function grams(array $rows, string $label): float
    {
        foreach ($rows as $row) {
            if ($row['particular'] === $label) {
                return (float) $row['grams'];
            }
        }
        return PHP_FLOAT_MAX;
    }

    // ---- LIABILITY + BREAKDOWN + VAULT RECONCILIATION -------------------

    public function test_liability_breakdown_and_vault_reconciliation(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        $ds = $this->build($shop->id);
        $summary = $ds->section('summary')->rows;
        $customers = $ds->section('customers');

        // Summary == canonical service.
        $this->assertEqualsWithDelta(30.0, $this->grams($summary, 'Gold You Owe Customers'), 0.001);
        $this->assertEqualsWithDelta(35.0, $this->grams($summary, 'Total Gold Deposited'), 0.001);
        $this->assertEqualsWithDelta(8.0, $this->grams($summary, 'Old Gold Taken In'), 0.001);
        $this->assertEqualsWithDelta(130.0, $this->grams($summary, 'Gold On Hand (Vault)'), 0.001);

        $svc = TenantContext::runFor($shop->id, fn () => app(ReceivablesService::class)->metalLiability($shop->id));

        // METAL-1 — total liability == independent customer_advance lot remaining.
        $rawLiability = (float) DB::table('metal_lots')->where('shop_id', $shop->id)
            ->where('source', 'customer_advance')->sum('fine_weight_remaining');
        $this->assertEqualsWithDelta($rawLiability, $this->grams($summary, 'Gold You Owe Customers'), 0.001, 'liability == raw');
        $this->assertEqualsWithDelta($svc->totalAdvanceLiability, $rawLiability, 0.001);

        // METAL-2 — customer breakdown sums to total deposited.
        $breakdownSum = (float) collect($customers->rows)->sum('deposited');
        $this->assertEqualsWithDelta(35.0, $breakdownSum, 0.001);
        $this->assertEqualsWithDelta($svc->totalDeposited, $breakdownSum, 0.001);

        // METAL-3 — on-hand == vault:reconcile source (Σ all lots) & liability <= on-hand.
        $rawOnHand = (float) DB::table('metal_lots')->where('shop_id', $shop->id)->sum('fine_weight_remaining');
        $this->assertEqualsWithDelta($rawOnHand, $this->grams($summary, 'Gold On Hand (Vault)'), 0.001, 'on-hand == vault source');
        $this->assertLessThanOrEqual($rawOnHand + 0.001, $rawLiability, 'liability covered by on-hand');

        // METAL-4 — grand total == breakdown total == service total.
        $this->assertEqualsWithDelta($svc->totalDeposited, (float) $customers->totals['deposited'], 0.001);
        $this->assertEqualsWithDelta($breakdownSum, (float) $customers->totals['deposited'], 0.001);
        $this->assertCount(2, $customers->rows);
    }

    // ---- VALIDATOR (reports:validate METAL-1/2/3/4) ---------------------

    public function test_reports_validate_metal_liability_path_passes(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        $this->artisan('reports:validate', ['--shop' => $shop->id])
            ->expectsOutputToContain('METAL-1 total liability == customer_advance lot remaining (independent)')
            ->expectsOutputToContain('METAL-2 customer breakdown sums to total deposited')
            ->expectsOutputToContain('METAL-3 on-hand == vault:reconcile source & liability <= on-hand')
            ->expectsOutputToContain('METAL-4 grand total == breakdown total == service total')
            ->assertExitCode(0);
    }

    // ---- EXPORTS / PARITY -----------------------------------------------

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

    // ---- PERMISSIONS (reports.view gate + customer is sensitive) --------

    public function test_customer_column_is_sensitive(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        $plain = $this->build($shop->id, includeSensitive: false);
        $this->assertNotContains('customer', $plain->section('customers')->columnKeys());

        $withPii = $this->build($shop->id, includeSensitive: true);
        $this->assertContains('customer', $withPii->section('customers')->columnKeys());
    }

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
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/metal-liability'))->assertForbidden();

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/metal-liability'))
            ->assertOk()->assertSee('Metal Liability');
    }

    // ---- TENANT ISOLATION + PERFORMANCE ---------------------------------

    public function test_tenant_isolation_and_query_bounded(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $this->seedShop($shopA->id);
        // B noise: a huge advance lot that must never reach A's liability/on-hand.
        $this->lot($shopB->id, 'customer_advance', 999999.0);

        DB::enableQueryLog();
        $ds = $this->build($shopA->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $summary = $ds->section('summary')->rows;
        $this->assertEqualsWithDelta(30.0, $this->grams($summary, 'Gold You Owe Customers'), 0.001, 'A must not see B');
        $this->assertEqualsWithDelta(130.0, $this->grams($summary, 'Gold On Hand (Vault)'), 0.001, 'A on-hand excludes B');
        $this->assertLessThanOrEqual(10, $queries, "Expected bounded queries, got {$queries}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
