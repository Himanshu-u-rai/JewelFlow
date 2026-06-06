<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\Permission;
use App\Models\User;
use App\Reporting\ReportPeriod;
use App\Reporting\SalesService;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportProfile as P;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Reports\PaymentReconciliationDataset;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 3 Accounting — Payment Reconciliation, the financial-integrity report.
 * Wraps the canonical SalesService::paymentReconciliation(): per invoice billed
 * (invoices.total) vs collected (Σ invoice_payments), outstanding = billed −
 * collected. Reconciles to the raw source tables by construction.
 */
class PaymentReconciliationReportTest extends TestCase
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

    private function finalizedInvoice(int $shopId, int $customerId, int $userId, string $number, float $subtotal, string $at): int
    {
        $id = (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId, 'user_id' => $userId,
            'invoice_number' => $number, 'gold_rate' => 7200,
            'subtotal' => $subtotal, 'discount' => 0, 'gst' => 0, 'gst_rate' => 0, 'total' => $subtotal,
            'cgst_amount' => 0, 'sgst_amount' => 0, 'igst_amount' => 0,
            'status' => Invoice::STATUS_DRAFT, 'finalized_at' => null,
            'created_at' => $at, 'updated_at' => $at,
        ]);
        $item = $this->createItem($shopId);
        DB::table('invoice_items')->insert([
            'invoice_id' => $id, 'item_id' => $item->id, 'weight' => 10, 'rate' => $subtotal / 10,
            'making_charges' => 0, 'stone_amount' => 0, 'line_total' => $subtotal, 'gst_rate' => 0,
            'gst_amount' => 0, 'metal_type' => 'gold', 'hsn_code' => '7113',
            'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('invoices')->where('id', $id)->update(['status' => Invoice::STATUS_FINALIZED, 'finalized_at' => $at]);

        return $id;
    }

    private function pay(int $shopId, int $invoiceId, float $amount, string $at, string $mode = 'cash'): void
    {
        DB::table('invoice_payments')->insert([
            'shop_id' => $shopId, 'invoice_id' => $invoiceId, 'mode' => $mode, 'amount' => $amount,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /**
     * Bill 1: 100000 fully paid · Bill 2: 50000 part paid (30000) · Bill 3: 20000 unpaid.
     * Σ total 170000 · Σ collected 130000 · Σ outstanding 40000.
     */
    private function seedShop(int $shopId, int $userId): void
    {
        $c = $this->createCustomer($shopId)->id;
        $i1 = $this->finalizedInvoice($shopId, $c, $userId, 'INV-PAY-1', 100000, self::FROM . ' 10:00:00');
        $i2 = $this->finalizedInvoice($shopId, $c, $userId, 'INV-PAY-2', 50000, self::FROM . ' 11:00:00');
        $this->finalizedInvoice($shopId, $c, $userId, 'INV-PAY-3', 20000, self::FROM . ' 12:00:00');
        $this->pay($shopId, $i1, 100000, self::FROM . ' 10:05:00');
        $this->pay($shopId, $i2, 30000, self::FROM . ' 11:05:00');
    }

    private function filters(): array
    {
        return ['period' => ['from' => CarbonImmutable::parse(self::FROM), 'to' => CarbonImmutable::parse(self::TO)]];
    }

    private function request(string $key, int $shopId, F $format, array $filters, bool $includeSensitive = true): ReportRequest
    {
        $def = app(ReportRegistry::class)->definition($key);
        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(true);
        $keys = app(ColumnPolicy::class)->resolve($def, P::Detailed, $user, includeSensitive: $includeSensitive)->columnKeys;

        return new ReportRequest(
            definition: $def, shopId: $shopId, userId: 1, userName: 'Manager', profile: P::Detailed,
            format: $format, filters: $filters, columnKeys: $keys, includeSensitive: $includeSensitive,
        );
    }

    private function meta(ReportRequest $r): ReportMeta
    {
        return new ReportMeta(
            reportKey: $r->definition->key, reportVersion: $r->definition->version, title: $r->definition->title,
            profileLabel: 'Detailed', format: $r->format->value, filtersApplied: ['Period' => 'Mar 2026'],
            periodLabel: '01 Mar 2026 – 31 Mar 2026', shopLegalName: 'Goldlux', shopAddress: null, shopGstin: null,
            shopStateCode: null, generatedByName: 'Manager', generatedAt: now(), generatorTag: 'test',
        );
    }

    private function build(int $shopId, bool $includeSensitive = true)
    {
        $r = $this->request(PaymentReconciliationDataset::KEY, $shopId, F::Csv, $this->filters(), $includeSensitive);

        return TenantContext::runFor($shopId, fn () => app(ReportRegistry::class)->datasetService(PaymentReconciliationDataset::KEY)->build($r, $this->meta($r)));
    }

    private function metric(array $rows, string $label): float
    {
        foreach ($rows as $row) {
            if ($row['particular'] === $label) {
                return (float) $row['value'];
            }
        }
        return PHP_FLOAT_MAX;
    }

    // ---- AGGREGATE + PER-ROW RECONCILIATION -----------------------------

    public function test_aggregate_and_per_row_reconciliation(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id, $owner->id);

        $ds = $this->build($shop->id);
        $summary = $ds->section('summary')->rows;
        $invoices = $ds->section('invoices')->rows;

        // Summary aggregates.
        $billed = $this->metric($summary, 'Bill Amount');
        $received = $this->metric($summary, 'Received');
        $outstanding = $this->metric($summary, 'Outstanding');
        $this->assertEqualsWithDelta(170000.0, $billed, 0.01);
        $this->assertEqualsWithDelta(130000.0, $received, 0.01);
        $this->assertEqualsWithDelta(40000.0, $outstanding, 0.01);

        $period = ReportPeriod::range(self::FROM, self::TO);

        // PAY-1 — Σ invoice totals == raw salesIn Σ(invoices.total).
        $rawTotal = (float) Invoice::withoutTenant()->where('shop_id', $shop->id)->salesIn($period)->sum('total');
        $this->assertEqualsWithDelta($rawTotal, $billed, 0.01, 'billed == source invoices');

        // PAY-2 — Σ collected == raw invoice_payments aggregate.
        $rawCollected = (float) DB::table('invoice_payments')->where('shop_id', $shop->id)->sum('amount');
        $this->assertEqualsWithDelta($rawCollected, $received, 0.01, 'received == invoice_payments');

        // PAY-3 — Σ outstanding == Σ billed − Σ collected.
        $this->assertEqualsWithDelta(round($billed - $received, 2), $outstanding, 0.01);

        // PAY-4 — every row: bill − received == outstanding.
        foreach ($invoices as $row) {
            $this->assertEqualsWithDelta(
                round((float) $row['total'] - (float) $row['collected'], 2),
                (float) $row['outstanding'],
                0.01,
                "row {$row['invoice_no']} variance"
            );
        }

        // The per-row outstanding values are correct against the seed.
        $byNo = collect($invoices)->keyBy('invoice_no');
        $this->assertEqualsWithDelta(0.0, (float) $byNo['INV-PAY-1']['outstanding'], 0.01);
        $this->assertEqualsWithDelta(20000.0, (float) $byNo['INV-PAY-2']['outstanding'], 0.01);
        $this->assertEqualsWithDelta(20000.0, (float) $byNo['INV-PAY-3']['outstanding'], 0.01);
    }

    // ---- VALIDATOR (reports:validate PAY-1/2/3/4) -----------------------

    public function test_reports_validate_payment_recon_path_passes(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id, $owner->id);

        $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => 3, '--year' => 2026])
            ->expectsOutputToContain('PAY-1 Σ invoice totals == source invoices aggregate (raw salesIn)')
            ->expectsOutputToContain('PAY-2 Σ collected == invoice_payments aggregate (raw)')
            ->expectsOutputToContain('PAY-3 Σ variances == Σ invoice totals − Σ collected')
            ->expectsOutputToContain('PAY-4 per-row invoice_total − collected == variance')
            ->assertExitCode(0);
    }

    // ---- EXPORTS / PARITY -----------------------------------------------

    public function test_all_file_formats_render(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id, $owner->id);

        foreach ([F::Pdf, F::Excel, F::Csv] as $format) {
            $request = $this->request(PaymentReconciliationDataset::KEY, $shop->id, $format, $this->filters());
            $result = TenantContext::runFor($shop->id, fn () => app(ExportPipeline::class)->run($request, $this->meta($request)));
            $this->assertGreaterThan(0, $result->output->byteSize(), $format->value);
        }
    }

    // ---- PERMISSIONS (reports.view gate + customer is sensitive) ---------

    public function test_customer_column_is_sensitive(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id, $owner->id);

        // Without the sensitive opt-in, customer (PII) is not a rendered column.
        $plain = $this->build($shop->id, includeSensitive: false);
        $this->assertNotContains('customer', $plain->section('invoices')->columnKeys());

        // With it, customer appears as a column.
        $withPii = $this->build($shop->id, includeSensitive: true);
        $this->assertContains('customer', $withPii->section('invoices')->columnKeys());
    }

    public function test_screen_requires_reports_view_permission(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedShop($shop->id, $owner->id);
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        // Strip reports.view → 403.
        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->detach(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/payment-reconciliation'))->assertForbidden();

        // Grant it → 200 + spine screen.
        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/payment-reconciliation'))
            ->assertOk()->assertSee('Payment Reconciliation');
    }

    // ---- TENANT ISOLATION + PERFORMANCE ---------------------------------

    public function test_tenant_isolation_and_query_bounded(): void
    {
        [$ownerA, $shopA] = $this->createManufacturerTenant();
        [$ownerB, $shopB] = $this->createManufacturerTenant();
        $this->seedShop($shopA->id, $ownerA->id);
        // B noise: a fully-paid bill that must never appear in A's reconciliation.
        $cB = $this->createCustomer($shopB->id)->id;
        $iB = $this->finalizedInvoice($shopB->id, $cB, $ownerB->id, 'INV-B-1', 999999, self::FROM . ' 10:00:00');
        $this->pay($shopB->id, $iB, 999999, self::FROM . ' 10:05:00');

        DB::enableQueryLog();
        $ds = $this->build($shopA->id);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertEqualsWithDelta(170000.0, $this->metric($ds->section('summary')->rows, 'Bill Amount'), 0.01, 'A must not see B');
        $this->assertCount(3, $ds->section('invoices')->rows);
        $this->assertLessThanOrEqual(12, $queries, "Expected bounded queries, got {$queries}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
