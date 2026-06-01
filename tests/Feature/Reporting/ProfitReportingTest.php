<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Reporting\ProfitReportingService;
use App\Reporting\ReportPeriod;
use App\Services\DashboardMetricsService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Locks the rebuilt P&L (true gross margin = revenue − COGS) and the dashboard
 * sales-definition convergence (audit A2/A4).
 */
class ProfitReportingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function insertInvoice(int $shopId, int $customerId, string $status, string $when, array $attrs = []): int
    {
        return (int) DB::table('invoices')->insertGetId(array_merge([
            'shop_id'        => $shopId,
            'customer_id'    => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate'      => 7200,
            'subtotal'       => 0,
            'discount'       => 0,
            'gst'            => 0,
            'gst_rate'       => 3,
            'total'          => 0,
            'status'         => $status,
            'created_at'     => $when,
            'updated_at'     => $when,
            'finalized_at'   => $status === Invoice::STATUS_FINALIZED ? $when : null,
        ], $attrs));
    }

    private function finalizeInvoice(int $invoiceId, string $when): void
    {
        // Raw update bypasses the Eloquent immutability guard; the DB
        // invoice_items_finalized_guard only blocks mutating line items, not
        // flipping the invoice's own status, so lines must be inserted first.
        DB::table('invoices')->where('id', $invoiceId)->update([
            'status'       => Invoice::STATUS_FINALIZED,
            'finalized_at' => $when,
        ]);
    }

    private function insertLine(int $invoiceId, int $itemId, float $lineTotal): void
    {
        DB::table('invoice_items')->insert([
            'invoice_id'     => $invoiceId,
            'item_id'        => $itemId,
            'weight'         => 9.5,
            'rate'           => 7200,
            'making_charges' => 5000,
            'stone_amount'   => 2000,
            'line_total'     => $lineTotal,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function test_gross_profit_is_revenue_minus_cogs_and_excludes_unknown_cost(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        // Insert as draft, add lines, then finalize (the line guard blocks
        // mutating items once an invoice is finalized).
        // subtotal must equal Σ line_totals (100000+100000+50000) and
        // total must equal subtotal+gst (accounting guard at finalize).
        $inv = $this->insertInvoice($shop->id, $customer->id, Invoice::STATUS_DRAFT, '2026-03-15 10:00:00', [
            'subtotal' => 250000, 'discount' => 0, 'gst' => 7500, 'total' => 257500,
        ]);

        // Two sold items with known cost 50000 each (COGS 100000) + one with no cost.
        $i1 = $this->createItem($shop->id, null, ['cost_price' => 50000]);
        $i2 = $this->createItem($shop->id, null, ['cost_price' => 50000]);
        $i3 = $this->createItem($shop->id, null, ['cost_price' => null]);
        $this->insertLine($inv, $i1->id, 100000);
        $this->insertLine($inv, $i2->id, 100000);
        $this->insertLine($inv, $i3->id, 50000);
        $this->finalizeInvoice($inv, '2026-03-15 10:00:00');

        // A draft invoice that must be ignored entirely.
        $draft = $this->insertInvoice($shop->id, $customer->id, Invoice::STATUS_DRAFT, '2026-03-16 10:00:00', [
            'subtotal' => 999999, 'gst' => 99999, 'total' => 1099998,
        ]);
        $iDraft = $this->createItem($shop->id, null, ['cost_price' => 1]);
        $this->insertLine($draft, $iDraft->id, 999999);

        $data = app(ProfitReportingService::class)->summary($shop->id, ReportPeriod::month(2026, 3));

        $this->assertEqualsWithDelta(250000.0, $data->revenue, 0.01);
        $this->assertEqualsWithDelta(100000.0, $data->cogs, 0.01, 'unknown-cost line excluded from COGS');
        $this->assertEqualsWithDelta(150000.0, $data->grossProfit, 0.01);
        $this->assertEqualsWithDelta(60.0, $data->marginPct, 0.01);
        $this->assertSame(3, $data->soldLineCount);
        $this->assertSame(1, $data->costUnknownLines);
    }

    public function test_gross_profit_can_be_negative(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $inv = $this->insertInvoice($shop->id, $customer->id, Invoice::STATUS_DRAFT, '2026-03-15 10:00:00', [
            'subtotal' => 40000, 'discount' => 0, 'gst' => 1200, 'total' => 41200,
        ]);
        $item = $this->createItem($shop->id, null, ['cost_price' => 60000]); // sold below cost
        $this->insertLine($inv, $item->id, 40000);
        $this->finalizeInvoice($inv, '2026-03-15 10:00:00');

        $data = app(ProfitReportingService::class)->summary($shop->id, ReportPeriod::month(2026, 3));

        $this->assertEqualsWithDelta(-20000.0, $data->grossProfit, 0.01, 'P&L must be able to show a loss');
    }

    public function test_dashboard_today_sales_exclude_drafts(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $today = now()->format('Y-m-d H:i:s');

        $this->insertInvoice($shop->id, $customer->id, Invoice::STATUS_FINALIZED, $today, [
            'subtotal' => 10000, 'gst' => 300, 'total' => 10300,
        ]);
        $this->insertInvoice($shop->id, $customer->id, Invoice::STATUS_DRAFT, $today, [
            'subtotal' => 500000, 'gst' => 15000, 'total' => 515000,
        ]);

        $metrics = TenantContext::runFor($shop->id, fn () => DashboardMetricsService::build($shop->id));

        $this->assertSame(1, $metrics['invoicesToday'], 'draft must not count as a sale');
        $this->assertEqualsWithDelta(10300.0, (float) $metrics['todaysRevenue'], 0.01);
    }
}
