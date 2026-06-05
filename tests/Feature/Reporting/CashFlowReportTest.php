<?php

namespace Tests\Feature\Reporting;

use App\Models\User;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\CashFlowDataset;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 3 Accounting — Cash Flow. Wraps LedgerService::cashFlow(); reconciles by
 * construction (opening + in − out = closing == cash_transactions aggregate).
 */
class CashFlowReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function cash(int $shopId, int $userId, string $type, float $amount, string $at): void
    {
        DB::table('cash_transactions')->insert([
            'shop_id' => $shopId, 'user_id' => $userId, 'type' => $type, 'amount' => $amount,
            'payment_mode' => 'cash', 'source_type' => 'manual', 'description' => 'test',
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** Opening 5000; March: +10000 +3000 −4000 → closing 14000. */
    private function seedCash(int $shopId, int $userId): void
    {
        $this->cash($shopId, $userId, 'in', 5000, '2026-02-15 10:00:00');   // opening
        $this->cash($shopId, $userId, 'in', 10000, '2026-03-10 10:00:00');
        $this->cash($shopId, $userId, 'in', 3000, '2026-03-15 10:00:00');
        $this->cash($shopId, $userId, 'out', 4000, '2026-03-20 10:00:00');
    }

    private function period(): array
    {
        return ['period' => ['from' => CarbonImmutable::parse('2026-03-01'), 'to' => CarbonImmutable::parse('2026-03-31')]];
    }

    private function keysFor(P $profile, bool $sensitive): array
    {
        $def = app(ReportRegistry::class)->definition(CashFlowDataset::KEY);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn($sensitive);

        return app(ColumnPolicy::class)->resolve($def, $profile, $user, includeSensitive: $sensitive)->columnKeys;
    }

    private function request(int $shopId, F $format, bool $sensitive): ReportRequest
    {
        return new ReportRequest(
            definition: app(ReportRegistry::class)->definition(CashFlowDataset::KEY),
            shopId: $shopId, userId: 1, userName: 'Cashier', profile: P::Detailed, format: $format,
            filters: $this->period(), columnKeys: $this->keysFor(P::Detailed, $sensitive), includeSensitive: $sensitive,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: CashFlowDataset::KEY, reportVersion: CashFlowDataset::VERSION,
            title: 'Cash Flow', profileLabel: 'Detailed', format: $r->format->value,
            filtersApplied: ['Period' => 'March 2026'], periodLabel: 'March 2026',
            shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null, shopStateCode: null,
            generatedByName: 'Cashier', generatedAt: now(), generatorTag: 'test',
        );
    }

    private function build(int $shopId, ReportRequest $r)
    {
        return TenantContext::runFor($shopId, fn () => app(CashFlowDataset::class)->build($r, $this->meta($r)));
    }

    private function summaryValue(array $rows, string $label): float
    {
        foreach ($rows as $row) {
            if ($row['particular'] === $label) {
                return (float) $row['value'];
            }
        }
        return PHP_FLOAT_MAX;
    }

    // ---- RECONCILIATION / BALANCE EQUATION ------------------------------

    public function test_balance_equation_reconciles_to_cash_transactions(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedCash($shop->id, $owner->id);

        $dataset = $this->build($shop->id, $this->request($shop->id, F::Csv, sensitive: true));
        $summary = $dataset->section('summary')->rows;
        $ledger = $dataset->section('ledger');

        $opening = $this->summaryValue($summary, 'Opening Balance');
        $in = $this->summaryValue($summary, 'Cash In');
        $out = $this->summaryValue($summary, 'Cash Out');
        $closing = $this->summaryValue($summary, 'Closing Balance');

        $this->assertEqualsWithDelta(5000.0, $opening, 0.01);
        $this->assertEqualsWithDelta(13000.0, $in, 0.01);
        $this->assertEqualsWithDelta(4000.0, $out, 0.01);

        // Balance equation.
        $this->assertEqualsWithDelta($opening + $in - $out, $closing, 0.01);
        $this->assertEqualsWithDelta(14000.0, $closing, 0.01);

        // Closing == independent cash_transactions aggregate (net up to period end).
        $independent = TenantContext::runFor($shop->id, fn () => round((float) DB::table('cash_transactions')
            ->where('shop_id', $shop->id)->where('created_at', '<=', '2026-03-31 23:59:59')
            ->selectRaw("COALESCE(SUM(CASE WHEN type='in' THEN amount ELSE -amount END),0) as net")->value('net'), 2));
        $this->assertEqualsWithDelta($independent, $closing, 0.01);

        // The ledger's last running balance == closing.
        $ledgerRows = $ledger->rows;
        $lastRow = $ledgerRows[array_key_last($ledgerRows)];
        $this->assertEqualsWithDelta($closing, (float) $lastRow['running_balance'], 0.01);
        $this->assertSame(3, $ledger->rowCount()); // 3 in-period entries
    }

    // ---- VALIDATOR (reports:validate CASH-1/2/3) ------------------------

    public function test_reports_validate_cash_path_passes(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedCash($shop->id, $owner->id);

        $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('CASH-1 opening + in − out == closing')
            ->expectsOutputToContain('CASH-2 closing == cash_transactions aggregate (independent)')
            ->assertExitCode(0);
    }

    // ---- EXPORTS / PARITY -----------------------------------------------

    public function test_all_file_formats_render(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedCash($shop->id, $owner->id);

        foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
            $request = $this->request($shop->id, $format, sensitive: true);
            $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));
            $this->assertGreaterThan(0, $result->output->byteSize(), $format->value);
        }
    }

    // ---- PERMISSIONS -----------------------------------------------------

    public function test_operator_is_sensitive_gated(): void
    {
        $this->assertNotContains('operator', $this->keysFor(P::Detailed, sensitive: false));
        $this->assertContains('operator', $this->keysFor(P::Detailed, sensitive: true));
    }

    // ---- TENANT ISOLATION -----------------------------------------------

    public function test_cash_flow_is_tenant_isolated(): void
    {
        [$ownerA, $shopA] = $this->createManufacturerTenant();
        [$ownerB, $shopB] = $this->createManufacturerTenant();
        $this->seedCash($shopA->id, $ownerA->id);              // A → closing 14000
        $this->cash($shopB->id, $ownerB->id, 'in', 999999, '2026-03-10 10:00:00'); // B noise

        $cfA = TenantContext::runFor($shopA->id, fn () => app(LedgerService::class)->cashFlow($shopA->id, ReportPeriod::range('2026-03-01', '2026-03-31')));
        $this->assertEqualsWithDelta(14000.0, $cfA->closing, 0.01, 'A must not see B cash');
    }

    // ---- PERFORMANCE -----------------------------------------------------

    public function test_build_is_query_bounded(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        for ($i = 0; $i < 25; $i++) {
            $this->cash($shop->id, $owner->id, $i % 2 === 0 ? 'in' : 'out', 100, '2026-03-1' . ($i % 9) . ' 10:00:00');
        }

        $request = $this->request($shop->id, F::Csv, sensitive: true);

        DB::enableQueryLog();
        $this->build($shop->id, $request);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // cashFlow runs a fixed pair of queries (opening + period entries).
        $this->assertLessThanOrEqual(4, $queries, "Expected O(1), got {$queries}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
