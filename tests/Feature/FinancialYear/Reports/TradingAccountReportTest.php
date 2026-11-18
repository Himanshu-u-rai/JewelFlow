<?php

namespace Tests\Feature\FinancialYear\Reports;

use App\Models\Invoice;
use App\Models\Shop;
use App\Reporting\ProfitReportingService;
use App\Reporting\ReportPeriod;
use App\Services\FiscalYearSnapshotService;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Reports\TradingAccountDataset as T;
use App\Support\FinancialYear;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** Trading account with stock: opening + purchases - closing = cost of goods sold; net sales - COGS = gross profit. */
class TradingAccountReportTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, BuildsYearReports;

    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [, $this->shop] = $this->createRetailerTenant();
    }

    private function sale(string $when, float $subtotal, float $discount = 0): void
    {
        $customer = $this->createCustomer($this->shop->id)->id;
        $id = (int) DB::table('invoices')->insertGetId([
            'shop_id' => $this->shop->id, 'customer_id' => $customer, 'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => $subtotal, 'discount' => $discount, 'gst' => 0, 'gst_rate' => 3, 'total' => $subtotal - $discount,
            'status' => Invoice::STATUS_DRAFT, 'created_at' => $when, 'updated_at' => $when, 'finalized_at' => null,
        ]);
        DB::table('invoices')->where('id', $id)->update(['status' => Invoice::STATUS_FINALIZED, 'finalized_at' => $when]);
    }

    /**
     * FY 2025-26. Opening (31 Mar 2025, gold 22 @ 5000): OP1 cost 40000 (market 47500 -> 40000), OP2 unknown cost.
     * Purchases in year: P1 30000, P2 20000 (sold 1 Sep), P3 unknown cost. Closing (31 Mar 2026 @ 6000): OP1 40000,
     * P1 30000, OP2 and P3 not valued -> 70000 with 2 not valued. COGS = 40000 + 50000 - 70000 = 20000.
     */
    private function seedYear(): void
    {
        $s = $this->shop->id;
        $this->at('2025-01-10 10:00:00');
        $this->goldItem($s, 'OP1', 40000);
        $this->goldItem($s, 'OP2', null);
        $this->at('2025-06-01 10:00:00');
        $this->goldItem($s, 'P1', 30000);
        $p2 = $this->goldItem($s, 'P2', 20000);
        $this->goldItem($s, 'P3', null);
        $this->at('2025-09-01 10:00:00');
        $this->setStatus($p2->id, 'sold');
        $this->sale('2025-09-01 10:00:00', 60000, 5000);
        $this->today();
        $this->rate($s, '2025-03-31', 'gold', 22, 5000);
        $this->rate($s, '2026-03-31', 'gold', 22, 6000);
    }

    private function amount(ReportDataset $ds, string $label, string $section = 'trading'): mixed
    {
        foreach ($ds->section($section)->rows as $r) {
            if ($r['particular'] === $label) {
                return $r['amount'] ?? $r['count'];
            }
        }
        $this->fail("No row '{$label}'");
    }

    public function test_it_is_registered_and_needs_the_money_switch(): void
    {
        $this->assertSame('trading-account', T::KEY);
        $this->assertSame('reports.money', app(ReportRegistry::class)->definition(T::KEY)->permissions->effectiveViewGate());
    }

    public function test_cogs_and_gross_profit_derive_from_opening_purchases_closing_and_net_sales(): void
    {
        $this->seedYear();
        $ds = $this->runReport(T::KEY, $this->shop->id);

        $this->assertEqualsWithDelta(40000.0, $this->amount($ds, T::OPENING), 0.001);
        $this->assertEqualsWithDelta(50000.0, $this->amount($ds, T::PURCHASES), 0.001);
        $this->assertEqualsWithDelta(70000.0, $this->amount($ds, T::CLOSING), 0.001);
        $this->assertEqualsWithDelta(20000.0, $this->amount($ds, T::COGS), 0.001);
        $this->assertEqualsWithDelta(55000.0, $this->amount($ds, T::NET_SALES), 0.001);
        $this->assertEqualsWithDelta(35000.0, $this->amount($ds, T::GROSS_PROFIT), 0.001);
    }

    public function test_net_sales_are_the_same_figure_the_profit_and_loss_report_uses(): void
    {
        $this->seedYear();
        $ds = $this->runReport(T::KEY, $this->shop->id);
        $pl = TenantContext::runFor($this->shop->id, fn () => app(ProfitReportingService::class)->summary($this->shop->id, ReportPeriod::range('2025-04-01', '2026-03-31')));
        $this->assertEqualsWithDelta($pl->revenue, $this->amount($ds, T::NET_SALES), 0.001);
    }

    public function test_unknown_costs_are_excluded_and_counted_never_guessed(): void
    {
        $this->seedYear();
        $ds = $this->runReport(T::KEY, $this->shop->id);
        $this->assertSame(1, $this->amount($ds, T::DQ_OPENING_NOT_VALUED, 'data_quality'));      // OP2
        $this->assertSame(1, $this->amount($ds, T::DQ_PURCHASES_NO_COST, 'data_quality'));      // P3
        $this->assertSame(2, $this->amount($ds, T::DQ_CLOSING_NOT_VALUED, 'data_quality'));     // OP2, P3
    }

    public function test_snapshot_present_and_absent_give_the_same_numbers(): void
    {
        $this->seedYear();
        $live = $this->runReport(T::KEY, $this->shop->id);
        $this->assertStringContainsString('Live', $live->meta->filtersApplied['Closing stock source']);

        $svc = app(FiscalYearSnapshotService::class);
        $svc->take($this->shop->id, FinancialYear::fromLabel('2024-25'), 'closing');
        $svc->take($this->shop->id, FinancialYear::fromLabel('2025-26'), 'closing');
        $snap = $this->runReport(T::KEY, $this->shop->id);

        $this->assertStringContainsString('snapshot v1', $snap->meta->filtersApplied['Closing stock source']);
        $this->assertStringContainsString('snapshot v1', $snap->meta->filtersApplied['Opening stock source']);
        $this->assertEquals($live->section('trading')->rows, $snap->section('trading')->rows);
        $this->assertEquals($live->section('data_quality')->rows, $snap->section('data_quality')->rows);
    }

    public function test_a_piece_sold_and_returned_inside_the_year_does_not_inflate_cogs(): void
    {
        $this->at('2025-01-10 10:00:00');
        $it = $this->goldItem($this->shop->id, 'RET', 40000);
        $this->at('2025-08-01 10:00:00');
        $this->setStatus($it->id, 'sold');
        $this->at('2025-08-10 10:00:00');
        $this->setStatus($it->id, 'in_stock');
        $this->today();
        $this->rate($this->shop->id, '2025-03-31', 'gold', 22, 5000);
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22, 6000);

        $ds = $this->runReport(T::KEY, $this->shop->id);
        $this->assertEqualsWithDelta(40000.0, $this->amount($ds, T::OPENING), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->amount($ds, T::PURCHASES), 0.001);
        $this->assertEqualsWithDelta(40000.0, $this->amount($ds, T::CLOSING), 0.001);
        $this->assertEqualsWithDelta(0.0, $this->amount($ds, T::COGS), 0.001, 'sold and came back: nothing was consumed');
    }

    public function test_a_piece_bought_before_the_year_and_sold_inside_it_costs_its_opening_value(): void
    {
        $this->at('2025-01-10 10:00:00');
        $it = $this->goldItem($this->shop->id, 'SOLD', 40000);
        $this->at('2025-08-01 10:00:00');
        $this->setStatus($it->id, 'sold');
        $this->today();
        $this->rate($this->shop->id, '2025-03-31', 'gold', 22, 5000);
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22, 6000);

        $ds = $this->runReport(T::KEY, $this->shop->id);
        $this->assertEqualsWithDelta(40000.0, $this->amount($ds, T::COGS), 0.001);
    }

    public function test_purchases_on_the_year_boundary_belong_to_exactly_one_year(): void
    {
        $this->at('2026-03-31 23:59:00');
        $this->goldItem($this->shop->id, 'LATE', 1111);
        $this->at('2026-04-01 00:00:00');
        $this->goldItem($this->shop->id, 'NEXT', 2222);
        $this->today();
        $this->assertEqualsWithDelta(1111.0, $this->amount($this->runReport(T::KEY, $this->shop->id), T::PURCHASES), 0.001);
        $this->assertEqualsWithDelta(2222.0, $this->amount($this->runReport(T::KEY, $this->shop->id, '2026-27'), T::PURCHASES), 0.001);
    }

    public function test_two_shops_are_isolated(): void
    {
        $this->seedYear();
        [, $other] = $this->createRetailerTenant();
        $this->at('2025-06-01 10:00:00');
        $this->goldItem($other->id, 'OTHER', 777);
        $this->today();
        $this->assertEqualsWithDelta(777.0, $this->amount($this->runReport(T::KEY, $other->id), T::PURCHASES), 0.001);
        $this->assertEqualsWithDelta(50000.0, $this->amount($this->runReport(T::KEY, $this->shop->id), T::PURCHASES), 0.001);
    }
}
