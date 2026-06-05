<?php

namespace Tests\Feature\Reporting;

use App\Models\MetalLot;
use App\Models\User;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\MetalMovementLedgerDataset;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 3 Accounting — Metal Movement Ledger, proven on the gating dimensions:
 * reconciliation (vs vault:reconcile, by construction), parity/exports,
 * permissions, performance.
 */
class MetalMovementLedgerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function lot(int $shopId, int $number, float $balance): MetalLot
    {
        $lot = $this->createMetalLot($shopId, $balance);
        DB::table('metal_lots')->where('id', $lot->id)->update(['lot_number' => $number, 'metal_type' => 'gold']);

        return $lot->refresh();
    }

    private function movement(int $shopId, ?int $from, ?int $to, float $fine, string $type, int $userId): void
    {
        DB::table('metal_movements')->insert([
            'shop_id' => $shopId, 'from_lot_id' => $from, 'to_lot_id' => $to,
            'fine_weight' => $fine, 'type' => $type, 'metal_type' => 'gold', 'user_id' => $userId,
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
        ]);
    }

    private function period(): array
    {
        return ['period' => ['from' => CarbonImmutable::parse('2026-03-01'), 'to' => CarbonImmutable::parse('2026-03-31')]];
    }

    private function keysFor(bool $sensitive): array
    {
        $def = app(ReportRegistry::class)->definition(MetalMovementLedgerDataset::KEY);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn($sensitive);

        return app(ColumnPolicy::class)->resolve($def, P::Detailed, $user, includeSensitive: $sensitive)->columnKeys;
    }

    private function request(int $shopId, F $format, bool $sensitive): ReportRequest
    {
        return new ReportRequest(
            definition: app(ReportRegistry::class)->definition(MetalMovementLedgerDataset::KEY),
            shopId: $shopId, userId: 1, userName: 'Auditor', profile: P::Detailed, format: $format,
            filters: $this->period(), columnKeys: $this->keysFor($sensitive), includeSensitive: $sensitive,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: MetalMovementLedgerDataset::KEY, reportVersion: MetalMovementLedgerDataset::VERSION,
            title: 'Metal Movement Ledger', profileLabel: 'Detailed', format: $r->format->value,
            filtersApplied: ['Period' => 'March 2026'], periodLabel: 'March 2026',
            shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null, shopStateCode: null,
            generatedByName: 'Auditor', generatedAt: now(), generatorTag: 'test',
        );
    }

    private function build(int $shopId, ReportRequest $r)
    {
        return TenantContext::runFor($shopId, fn () => app(MetalMovementLedgerDataset::class)->build($r, $this->meta($r)));
    }

    // ---- RECONCILIATION (by construction, vs vault:reconcile) -------------

    public function test_net_per_lot_reconciles_to_lot_balance_and_vault_reconcile(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $lot = $this->lot($shop->id, 5001, 60.0);                // balance 60
        $lotNo = $lot->lot_number;
        $this->movement($shop->id, null, $lot->id, 100.0, 'purchase', $owner->id);   // +100 in
        $this->movement($shop->id, $lot->id, null, 40.0, 'job_issue', $owner->id);   // -40 out  → net 60

        $dataset = $this->build($shop->id, $this->request($shop->id, F::Csv, sensitive: true));
        $rows = $dataset->section('movements')->rows;

        $this->assertSame(2, $dataset->totalRowCount());

        // Net for the lot derived FROM THE LEDGER ROWS == stored lot balance (the vault invariant).
        $net = 0.0;
        foreach ($rows as $row) {
            if ((string) ($row['to_lot'] ?? '') === (string) $lotNo) {
                $net += (float) $row['fine_weight'];
            }
            if ((string) ($row['from_lot'] ?? '') === (string) $lotNo) {
                $net -= (float) $row['fine_weight'];
            }
        }
        $this->assertEqualsWithDelta(60.0, $net, 0.0001);
        $this->assertEqualsWithDelta((float) $lot->fresh()->fine_weight_remaining, $net, 0.0001);

        // The vault reconciler agrees (clean) for the same shop.
        $this->artisan('vault:reconcile', ['--shop' => $shop->id])->assertExitCode(0);
    }

    // ---- PARITY / EXPORTS -----------------------------------------------

    public function test_all_file_formats_render(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $lot = $this->lot($shop->id, 5001, 100.0);
        $this->movement($shop->id, null, $lot->id, 100.0, 'purchase', $owner->id);

        foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
            $request = $this->request($shop->id, $format, sensitive: true);
            $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));
            $this->assertSame(1, $result->rowCount, $format->value);
            $this->assertGreaterThan(0, $result->output->byteSize(), $format->value);
        }
    }

    // ---- PERMISSIONS -----------------------------------------------------

    public function test_operator_column_is_sensitive_gated(): void
    {
        $this->assertNotContains('operator', $this->keysFor(sensitive: false));
        $this->assertContains('operator', $this->keysFor(sensitive: true));
    }

    // ---- PERFORMANCE (no N+1) -------------------------------------------

    public function test_build_is_query_bounded(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $lot = $this->lot($shop->id, 5001, 0.0);
        for ($i = 0; $i < 15; $i++) {
            $this->movement($shop->id, null, $lot->id, 1.0, 'purchase', $owner->id);
        }

        $request = $this->request($shop->id, F::Csv, sensitive: true);

        DB::enableQueryLog();
        $dataset = $this->build($shop->id, $request);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(15, $dataset->totalRowCount());
        $this->assertLessThanOrEqual(5, $queries, "Expected O(1) via eager loading, got {$queries}");
    }

    // ---- SCREEN (ledger.index repointed to the spine) -------------------

    public function test_ledger_index_route_serves_the_spine_screen(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $lot = $this->lot($shop->id, 5001, 100.0);
        $this->movement($shop->id, null, $lot->id, 100.0, 'purchase', $owner->id);

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(\App\Models\Permission::whereIn('name', ['reports.view'])->pluck('id'));
        });
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)
            ->get('/ledger?date_preset=custom&date_from=2026-03-01&date_to=2026-03-31'));

        $response->assertOk();
        $response->assertSee('Metal Movement Ledger');
        $response->assertDontSee('window.print');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
