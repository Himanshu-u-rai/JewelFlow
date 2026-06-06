<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use App\Reporting\ReportPeriod;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportAuditService;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\ProfitLossDataset;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 4 Owner — Profit & Loss. Honest gross margin (revenue − COGS). Wraps
 * ProfitReportingService::summary() verbatim; revenue ties to the Sales Register
 * scope, COGS to items.cost_price, margin = revenue − COGS, and cost-unknown
 * lines are surfaced as a visible data-quality metric.
 */
class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const FROM = '2026-03-01';
    private const TO = '2026-03-31';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function insertInvoice(int $shopId, int $customerId, string $status, string $when, array $attrs = []): int
    {
        return (int) DB::table('invoices')->insertGetId(array_merge([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => 0, 'discount' => 0, 'gst' => 0, 'gst_rate' => 3, 'total' => 0,
            'status' => $status, 'created_at' => $when, 'updated_at' => $when,
            'finalized_at' => $status === Invoice::STATUS_FINALIZED ? $when : null,
        ], $attrs));
    }

    private function insertLine(int $invoiceId, int $itemId, float $lineTotal): void
    {
        DB::table('invoice_items')->insert([
            'invoice_id' => $invoiceId, 'item_id' => $itemId, 'weight' => 9.5, 'rate' => 7200,
            'making_charges' => 5000, 'stone_amount' => 2000, 'line_total' => $lineTotal,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function finalizeInvoice(int $invoiceId, string $when): void
    {
        DB::table('invoices')->where('id', $invoiceId)->update([
            'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => $when,
        ]);
    }

    /**
     * Gross sales 250000, discount 20000 → revenue 230000. COGS 60000+40000=100000
     * (third line has no cost). Gross profit 130000. 3 sold lines, 1 missing cost.
     */
    private function seedShop(int $shopId): void
    {
        $c = $this->createCustomer($shopId)->id;
        $inv = $this->insertInvoice($shopId, $c, Invoice::STATUS_DRAFT, self::FROM . ' 10:00:00', [
            'subtotal' => 250000, 'discount' => 20000, 'gst' => 7500, 'total' => 237500,
        ]);
        $i1 = $this->createItem($shopId, null, ['cost_price' => 60000]);
        $i2 = $this->createItem($shopId, null, ['cost_price' => 40000]);
        $i3 = $this->createItem($shopId, null, ['cost_price' => null]);
        $this->insertLine($inv, $i1->id, 100000);
        $this->insertLine($inv, $i2->id, 100000);
        $this->insertLine($inv, $i3->id, 50000);
        $this->finalizeInvoice($inv, self::FROM . ' 10:00:00');
    }

    private function filters(): array
    {
        return ['period' => ['from' => CarbonImmutable::parse(self::FROM), 'to' => CarbonImmutable::parse(self::TO)]];
    }

    private function request(int $shopId, F $format, int $userId = 1): ReportRequest
    {
        $def = app(ReportRegistry::class)->definition(ProfitLossDataset::KEY);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(true);
        $keys = app(ColumnPolicy::class)->resolve($def, P::Detailed, $user)->columnKeys;

        return new ReportRequest(
            definition: $def, shopId: $shopId, userId: $userId, userName: 'Owner', profile: P::Detailed,
            format: $format, filters: $this->filters(), columnKeys: $keys,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: $r->definition->key, reportVersion: $r->definition->version, title: $r->definition->title,
            profileLabel: 'Detailed', format: $r->format->value, filtersApplied: ['Period' => 'Mar 2026'],
            periodLabel: '01 Mar 2026 – 31 Mar 2026', shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null,
            shopStateCode: null, generatedByName: 'Owner', generatedAt: now(), generatorTag: 'test',
        );
    }

    private function build(int $shopId)
    {
        $r = $this->request($shopId, F::Csv);

        return TenantContext::runFor($shopId, fn () => app(ReportRegistry::class)->datasetService(ProfitLossDataset::KEY)->build($r, $this->meta($r)));
    }

    private function metric(array $rows, string $label, string $col)
    {
        foreach ($rows as $row) {
            if ($row['metric'] === $label) {
                return $row[$col];
            }
        }
        return null;
    }

    // ---- REVENUE / COGS / MARGIN / DATA-QUALITY RECONCILIATION ----------

    public function test_reconciles_revenue_cogs_margin_and_surfaces_cost_unknown(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        $ds = $this->build($shop->id);
        $pnl = $ds->section('pnl')->rows;
        $margin = $ds->section('margin')->rows;
        $dq = $ds->section('data_quality')->rows;

        // Statement values == canonical service.
        $this->assertEqualsWithDelta(250000.0, (float) $this->metric($pnl, 'Gross Sales', 'amount'), 0.01);
        $this->assertEqualsWithDelta(20000.0, (float) $this->metric($pnl, 'Discount', 'amount'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $this->metric($pnl, 'Returns', 'amount'), 0.01);
        $revenue = (float) $this->metric($pnl, 'Net Revenue', 'amount');
        $cogs = (float) $this->metric($pnl, 'Cost of Goods Sold', 'amount');
        $profit = (float) $this->metric($pnl, 'Gross Profit', 'amount');
        $this->assertEqualsWithDelta(230000.0, $revenue, 0.01);
        $this->assertEqualsWithDelta(100000.0, $cogs, 0.01);
        $this->assertEqualsWithDelta(130000.0, $profit, 0.01);

        $period = ReportPeriod::range(self::FROM, self::TO);
        [$start, $end] = $period->bounds();

        // PNL-1 — revenue == Σ salesIn subtotal − discount − returns (raw).
        $inv = Invoice::withoutTenant()->where('shop_id', $shop->id)->salesIn($period)
            ->selectRaw('COALESCE(SUM(subtotal),0) g, COALESCE(SUM(discount),0) d')->first();
        $rawReturns = (float) \App\Models\CreditNote::withoutTenant()->where('shop_id', $shop->id)
            ->whereBetween('issued_at', [$start, $end])->sum('subtotal');
        $this->assertEqualsWithDelta(round((float) $inv->g - (float) $inv->d - $rawReturns, 2), $revenue, 0.01, 'revenue == raw');

        // PNL-2 — COGS == Σ items.cost_price of sold lines (raw).
        $rawCogs = (float) DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('items', 'items.id', '=', 'invoice_items.item_id')
            ->where('invoices.shop_id', $shop->id)->where('invoices.status', Invoice::STATUS_FINALIZED)
            ->whereRaw('COALESCE(invoices.finalized_at, invoices.created_at) BETWEEN ? AND ?', [$start, $end])
            ->sum(DB::raw('COALESCE(items.cost_price, 0)'));
        $this->assertEqualsWithDelta($rawCogs, $cogs, 0.01, 'COGS == raw cost basis');

        // PNL-3 — margin == revenue − COGS.
        $this->assertEqualsWithDelta(round($revenue - $cogs, 2), $profit, 0.01);
        $this->assertEqualsWithDelta(56.52, (float) $this->metric($margin, 'Gross Margin', 'percent'), 0.01);

        // PNL-4 — cost-unknown lines surfaced as a visible metric.
        $this->assertSame(3, (int) $this->metric($dq, 'Sold Lines', 'count'));
        $this->assertSame(1, (int) $this->metric($dq, 'Lines Missing Cost', 'count'));
    }

    // ---- VALIDATOR (reports:validate PNL-1/2/3/4) ----------------------

    public function test_reports_validate_pnl_path_passes(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id);

        $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('PNL-1 revenue == Sales Register subtotal − discounts − returns (raw)')
            ->expectsOutputToContain('PNL-2 COGS == sold items.cost_price (raw)')
            ->expectsOutputToContain('PNL-3 gross profit == revenue − COGS')
            ->expectsOutputToContain('PNL-4 cost-unknown lines surfaced (1) == independent recompute')
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
            $this->assertSame('pnl', $export->report_key);
        });

        $this->assertDatabaseHas('report_exports', ['report_key' => 'pnl', 'shop_id' => $shop->id]);
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
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/pnl?date=' . self::FROM))->assertForbidden();

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/pnl?date=' . self::FROM))
            ->assertOk()->assertSee('Profit & Loss');
    }

    // ---- TENANT ISOLATION + PERFORMANCE --------------------------------

    public function test_tenant_isolation_and_query_bounded(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $this->seedShop($shopA->id);
        // B noise: a huge finalized sale that must never reach A's P&L.
        $cB = $this->createCustomer($shopB->id)->id;
        $invB = $this->insertInvoice($shopB->id, $cB, Invoice::STATUS_DRAFT, self::FROM . ' 10:00:00', [
            'subtotal' => 999999, 'discount' => 0, 'gst' => 0, 'total' => 999999,
        ]);
        $iB = $this->createItem($shopB->id, null, ['cost_price' => 1]);
        $this->insertLine($invB, $iB->id, 999999);
        $this->finalizeInvoice($invB, self::FROM . ' 10:00:00');

        DB::enableQueryLog();
        $ds = $this->build($shopA->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEqualsWithDelta(230000.0, (float) $this->metric($ds->section('pnl')->rows, 'Net Revenue', 'amount'), 0.01, 'A must not see B');
        $this->assertLessThanOrEqual(10, $queries, "Expected bounded queries, got {$queries}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
