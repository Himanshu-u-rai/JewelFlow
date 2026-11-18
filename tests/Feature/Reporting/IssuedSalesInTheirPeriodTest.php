<?php

namespace Tests\Feature\Reporting;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ShopPreferences;
use App\Reporting\AuditService;
use App\Reporting\GstReportingService;
use App\Reporting\HsnSummaryService;
use App\Reporting\LedgerService;
use App\Reporting\ProfitReportingService;
use App\Reporting\ReportPeriod;
use App\Reporting\TaxService;
use App\Services\InvoiceAccountingService;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The rule (GST + books): a bill is a sale in the period it was ISSUED, whatever happens to it later; a later
 * cancellation (reversal bill) or return (credit note) is a separate negative entry in ITS OWN period.
 *
 * Every sales-figure consumer is read through the same scenarios. Each figure is the NET sale of the period
 * (sales minus reductions) so a figure that double-counts a reduction, or drops a cancelled bill from its own
 * period, fails here by name.
 *
 * The test bill has two lines: 1000 (cost 600) and 500 (cost 300), GST 3% => taxable 1500, GST 45, total 1545.
 */
class IssuedSalesInTheirPeriodTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private $owner;
    private $shop;
    private $customer;

    /** [$basis] taxable|gst|total|cogs : what share of the bill a figure measures. */
    private const FIGURES = [
        'profit.revenue' => 'taxable',
        'profit.cogs' => 'cogs',
        'gst.taxable' => 'taxable',
        'gst.net_liability' => 'gst',
        'gstr3b.net_taxable' => 'taxable',
        'gstr3b.net_gst' => 'gst',
        'gstr1.net_taxable' => 'taxable',
        'hsn.taxable' => 'taxable',
        'daybook.net_sales' => 'total',
        'operator.net_sales' => 'total',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->customer = $this->createCustomer($this->shop->id);
        $this->prefs($this->shop->id);
    }

    private function prefs(int $shopId): void
    {
        ShopPreferences::withoutTenant()->updateOrCreate(['shop_id' => $shopId], [
            'refund_making_charges' => true, 'refund_stone_charges' => true, 'refund_gst' => true,
            'wear_loss_pct' => 0, 'restocking_fee_pct' => 0, 'return_settlement_mode' => 'cash_or_credit',
        ]);
    }

    private function at(string $ist): void
    {
        $this->travelTo(CarbonImmutable::parse($ist, 'Asia/Kolkata'));
        $this->actingAs($this->owner);
    }

    private function bill(?int $shopId = null, ?int $customerId = null, float $discount = 0, bool $interState = false, ?string $buyerGstin = null): Invoice
    {
        $shopId ??= $this->shop->id;
        $customerId ??= $this->customer->id;
        $a = $this->createItem($shopId, null, ['selling_price' => 1000, 'cost_price' => 600]);
        $b = $this->createItem($shopId, null, ['selling_price' => 500, 'cost_price' => 300]);

        return TenantContext::runFor($shopId, function () use ($shopId, $customerId, $a, $b, $discount, $interState, $buyerGstin) {
            $draft = new Invoice();
            $draft->forceFill([
                'shop_id' => $shopId, 'customer_id' => $customerId, 'gold_rate' => 7200,
                'subtotal' => 0, 'gst' => 0, 'gst_rate' => 3, 'wastage_charge' => 0, 'discount' => $discount,
                'round_off' => 0, 'total' => 0, 'status' => Invoice::STATUS_DRAFT,
            ])->save();
            foreach ([[$a, 1000, 30], [$b, 500, 15]] as [$item, $line, $gst]) {
                InvoiceItem::record([
                    'invoice_id' => $draft->id, 'item_id' => $item->id, 'metal_type' => $item->metal_type,
                    'weight' => (float) $item->gross_weight, 'rate' => 0, 'making_charges' => 0, 'stone_amount' => 0,
                    'hallmark_charges' => 0, 'line_total' => $line, 'gst_rate' => 3, 'gst_amount' => $gst,
                    'allocated_discount' => 0, 'allocated_round_off' => 0, 'allocated_loyalty_pts' => 0,
                    'hsn_code' => '7113',
                ]);
            }

            if ($interState) {
                $draft->forceFill(['igst_amount' => 45, 'cgst_amount' => 0, 'sgst_amount' => 0])->save();
            }
            if ($buyerGstin) {
                $draft->forceFill(['buyer_gstin' => $buyerGstin, 'place_of_supply_state_code' => '29'])->save();
            }

            return InvoiceAccountingService::finalizeDraft($draft);
        });
    }

    private function reverse(Invoice $bill, ?int $shopId = null): void
    {
        TenantContext::runFor($shopId ?? $this->shop->id, fn () => InvoiceAccountingService::cancelByReversal($bill->fresh(), 'test reversal'));
    }

    private function fullReturn(Invoice $bill): void
    {
        TenantContext::runFor($this->shop->id, fn () => app(ReturnService::class)->createFullReturn(
            $bill->fresh(), 'test return', $this->owner->id, false, ReturnService::REFUND_SETTLEMENT_STORE_CREDIT));
    }

    private function returnLine(Invoice $bill, int $index): void
    {
        $line = $bill->items()->orderBy('id')->get()[$index];
        TenantContext::runFor($this->shop->id, fn () => app(ReturnService::class)->createPartialReturn(
            $bill->fresh(), [$line->id => ['disposition' => 'restocked']], 'one piece back', $this->owner->id));
    }

    /** @return array<string,float> every sales figure of the period, for one shop */
    private function figures(string $from, string $to, ?int $shopId = null): array
    {
        $shopId ??= $this->shop->id;
        $period = ReportPeriod::range($from, $to);

        $profit = app(ProfitReportingService::class)->summary($shopId, $period);
        $gst = app(GstReportingService::class)->summary($shopId, $period);
        $tax = app(TaxService::class);
        $r3b = $tax->gstr3b($shopId, $period);
        $r1 = $tax->gstr1($shopId, $period);
        $day = app(LedgerService::class)->dayBook($shopId, $period);
        $ops = app(AuditService::class)->operatorPerformance($shopId, $period);

        return [
            'profit.revenue' => $profit->revenue,
            'profit.cogs' => $profit->cogs,
            'gst.taxable' => round($gst->taxableAmount - $gst->cnTaxableReversed, 2),
            'gst.net_liability' => $gst->netGstLiability,
            'gstr3b.net_taxable' => $r3b->netTaxable,
            'gstr3b.net_gst' => $r3b->netGst,
            'gstr1.net_taxable' => round((float) $r1->b2b->sum('taxable') + (float) $r1->b2cs->sum('taxable') - $r1->cnTaxable, 2),
            'hsn.taxable' => round(array_sum(array_column(app(HsnSummaryService::class)->summary($shopId, $period), 'taxable')), 2),
            'daybook.net_sales' => round($day->salesTotal - $day->refundTotal, 2),
            'operator.net_sales' => round($ops->totalSales - $ops->totalReturnsValue, 2),
        ];
    }

    /** What one clean bill reads as: [basis => amount]. */
    private const BILL = ['taxable' => 1500.0, 'gst' => 45.0, 'total' => 1545.0, 'cogs' => 900.0];

    /** @param array<string,float> $actual  @param callable(string $basis): float $expected */
    private function assertFigures(array $actual, callable $expected, string $why): void
    {
        $wrong = [];
        foreach (self::FIGURES as $name => $basis) {
            $want = round($expected($basis), 2);
            if (abs($actual[$name] - $want) > 0.01) {
                $wrong[] = "{$name}: expected {$want}, got {$actual[$name]}";
            }
        }
        $this->assertSame([], $wrong, $why . "\n" . implode("\n", $wrong));
    }

    public function test_sanity_a_clean_bill_reads_as_the_whole_bill_in_every_figure(): void
    {
        $this->at('2026-05-10 12:00:00');
        $this->bill();
        $this->assertFigures($this->figures('2026-05-01', '2026-05-31'), fn ($b) => self::BILL[$b], 'a plain sale');
    }

    public function test_a_bill_cancelled_by_reversal_in_the_same_month_nets_to_zero_everywhere(): void
    {
        $this->at('2026-05-10 12:00:00');
        $bill = $this->bill();
        $this->reverse($bill);

        $this->assertFigures($this->figures('2026-05-01', '2026-05-31'), fn () => 0.0, 'same-month reversal');
    }

    public function test_a_full_return_in_the_same_month_nets_to_zero_everywhere(): void
    {
        $this->at('2026-05-10 12:00:00');
        $bill = $this->bill();
        $this->fullReturn($bill);

        $this->assertFigures($this->figures('2026-05-01', '2026-05-31'), fn () => 0.0, 'same-month full return');
    }

    public function test_a_march_sale_reversed_in_may_stays_in_march_and_the_reversal_lands_in_may(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill();
        $this->at('2026-05-10 12:00:00');
        $this->reverse($bill);

        $this->assertFigures($this->figures('2026-03-01', '2026-03-31'), fn ($b) => self::BILL[$b], 'March must not change');
        $may = $this->figures('2026-05-01', '2026-05-31');
        // The reversal reduces May; COGS comes back (the goods return to stock) in May too.
        $this->assertFigures($may, fn ($b) => -self::BILL[$b], 'May carries the reversal');
    }

    public function test_a_march_sale_fully_returned_in_may_stays_in_march_and_the_credit_note_lands_in_may(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill();
        $this->at('2026-05-10 12:00:00');
        $this->fullReturn($bill);

        $this->assertFigures($this->figures('2026-03-01', '2026-03-31'), fn ($b) => self::BILL[$b], 'March must not change');
        $this->assertFigures($this->figures('2026-05-01', '2026-05-31'), fn ($b) => -self::BILL[$b], 'May carries the credit note');
    }

    public function test_a_year_covering_both_months_nets_the_bill_to_zero(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill();
        $this->at('2026-05-10 12:00:00');
        $this->reverse($bill);

        $this->assertFigures($this->figures('2026-03-01', '2026-05-31'), fn () => 0.0, 'quarter holding both the sale and its reversal');
    }

    public function test_a_partial_return_reduces_only_its_own_period_by_the_returned_line(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill();
        $this->at('2026-05-10 12:00:00');
        $this->returnLine($bill, 0); // the 1000 line (GST 30)

        $this->assertFigures($this->figures('2026-03-01', '2026-03-31'), fn ($b) => self::BILL[$b], 'March must not change');
        $may = $this->figures('2026-05-01', '2026-05-31');
        $expected = ['taxable' => -1000.0, 'gst' => -30.0, 'total' => -1030.0];
        $wrong = [];
        foreach (self::FIGURES as $name => $basis) {
            if ($basis === 'cogs') {
                continue; // the returned piece's cost is deliberately not added back (see ProfitReportingService)
            }
            if (abs($may[$name] - $expected[$basis]) > 0.01) {
                $wrong[] = "{$name}: expected {$expected[$basis]}, got {$may[$name]}";
            }
        }
        $this->assertSame([], $wrong, implode("\n", $wrong));
    }

    public function test_a_partial_return_in_the_same_month_leaves_the_rest_of_the_bill(): void
    {
        $this->at('2026-05-10 12:00:00');
        $bill = $this->bill();
        $this->returnLine($bill, 0);

        $may = $this->figures('2026-05-01', '2026-05-31');
        $this->assertEqualsWithDelta(500.0, $may['profit.revenue'], 0.01);
        $this->assertEqualsWithDelta(15.0, $may['gst.net_liability'], 0.01);
        $this->assertEqualsWithDelta(515.0, $may['daybook.net_sales'], 0.01);
        $this->assertEqualsWithDelta(500.0, $may['hsn.taxable'], 0.01);
    }

    public function test_another_shops_reversal_never_moves_this_shops_figures(): void
    {
        $this->at('2026-05-10 12:00:00');
        $mine = $this->bill();

        [$owner2, $shop2] = $this->createRetailerTenant();
        $this->prefs($shop2->id);
        $customer2 = $this->createCustomer($shop2->id);
        $this->actingAs($owner2);
        $theirs = $this->bill($shop2->id, $customer2->id);
        $this->reverse($theirs, $shop2->id);

        $this->assertFigures($this->figures('2026-05-01', '2026-05-31'), fn ($b) => self::BILL[$b], 'shop 1 is untouched by shop 2');
        $this->assertFigures($this->figures('2026-05-01', '2026-05-31', $shop2->id), fn () => 0.0, 'shop 2 nets to zero');
    }

    public function test_gstr1_lists_the_original_in_its_own_month_and_the_reversal_in_the_month_it_happened(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill();
        $this->at('2026-05-10 12:00:00');
        $this->reverse($bill);

        $tax = app(TaxService::class);
        $march = $tax->gstr1($this->shop->id, ReportPeriod::range('2026-03-01', '2026-03-31'));
        $may = $tax->gstr1($this->shop->id, ReportPeriod::range('2026-05-01', '2026-05-31'));

        $marchRows = $march->b2b->concat($march->b2cs);
        $this->assertEqualsWithDelta(1500.0, (float) $marchRows->sum('taxable'), 0.01, 'the bill is listed in March');
        $mayRows = $may->b2b->concat($may->b2cs);
        $this->assertEqualsWithDelta(-1500.0, (float) $mayRows->sum('taxable'), 0.01, 'the reversal is listed in May, negative');
    }

    public function test_the_reversal_of_an_inter_state_bill_takes_back_igst_not_cgst_and_sgst(): void
    {
        $this->at('2026-05-10 12:00:00');
        $bill = $this->bill(null, null, 0, true);
        $this->reverse($bill);

        $s = app(GstReportingService::class)->summary($this->shop->id, ReportPeriod::range('2026-05-01', '2026-05-31'));
        $this->assertEqualsWithDelta(0.0, $s->igstCollected, 0.01, 'IGST nets to zero');
        $this->assertEqualsWithDelta(0.0, $s->cgstCollected, 0.01, 'CGST stays zero (not a negative half)');
        $this->assertEqualsWithDelta(0.0, $s->sgstCollected, 0.01, 'SGST stays zero (not a negative half)');
    }

    public function test_the_monthly_gstr1_hsn_section_follows_the_issue_month_and_is_reduced_in_the_reversal_and_return_month(): void
    {
        $this->at('2026-03-20 12:00:00');
        $reversed = $this->bill();
        $returned = $this->bill();
        $this->at('2026-05-10 12:00:00');
        $this->reverse($reversed);
        $this->fullReturn($returned);

        $hsn = fn (string $from, string $to) => round((float) app(TaxService::class)
            ->gstr1($this->shop->id, ReportPeriod::range($from, $to))->hsnSummary->sum('taxable'), 2);

        $this->assertEqualsWithDelta(3000.0, $hsn('2026-03-01', '2026-03-31'), 0.01, 'both bills are sales of March');
        // May: the reversal bill takes its lines off, and (owner, 2026-11-16: monthly HSN is net) so does the
        // returned bill's credit note — which is also still listed in its own table.
        $cn = (float) \Illuminate\Support\Facades\DB::table('return_line_items')->where('shop_id', $this->shop->id)->sum('refund_subtotal');
        $this->assertGreaterThan(0, $cn);
        $this->assertEqualsWithDelta(-1500.0 - $cn, $hsn('2026-05-01', '2026-05-31'), 0.01);
    }

    public function test_a_gstin_bill_reversed_in_a_later_month_stays_b2b_in_its_own_month_and_comes_off_as_b2b(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill(null, null, 0, false, '29ABCDE1234F1Z5');
        $this->at('2026-05-10 12:00:00');
        $this->reverse($bill);

        $tax = app(TaxService::class);
        $march = $tax->gstr1($this->shop->id, ReportPeriod::range('2026-03-01', '2026-03-31'));
        $may = $tax->gstr1($this->shop->id, ReportPeriod::range('2026-05-01', '2026-05-31'));
        $this->assertEqualsWithDelta(1500.0, (float) $march->b2b->sum('taxable'), 0.01, 'listed under the buyer in March');
        $this->assertEqualsWithDelta(-1500.0, (float) $may->b2b->sum('taxable'), 0.01, 'taken off the same buyer in May, not as an anonymous B2C line');
        $this->assertEqualsWithDelta(0.0, (float) $may->b2cs->sum('taxable'), 0.01);
    }

    // ---- Dashboard, invoice list totals, sales register, customer spend ----------------------------------

    private function dash(): array
    {
        return TenantContext::runFor($this->shop->id, fn () => \App\Services\DashboardMetricsService::build($this->shop->id));
    }

    private function trendRevenue(array $metrics, string $date, string $key = 'trendData'): float
    {
        return (float) $metrics[$key]->firstWhere('date', $date)['revenue'];
    }

    public function test_dashboard_a_bill_reversed_the_same_day_nets_to_zero_revenue_and_profit(): void
    {
        $this->at('2026-05-10 12:00:00');
        $this->reverse($this->bill());
        $m = $this->dash();

        $this->assertEqualsWithDelta(0.0, (float) $m['todaysRevenue'], 0.01, 'today revenue');
        $this->assertEqualsWithDelta(0.0, (float) $m['todaysProfit'], 0.01, 'today profit');
        $this->assertEqualsWithDelta(0.0, $this->trendRevenue($m, '2026-05-10'), 0.01, '7-day trend');
        $this->assertEqualsWithDelta(0.0, $this->trendRevenue($m, '2026-05-10', 'monthlyRevenueTrend'), 0.01, '30-day trend');
        $this->assertEqualsWithDelta(0.0, (float) $m['trendData']->firstWhere('date', '2026-05-10')['profit'], 0.01, 'trend profit');
        $this->assertSame(1, $m['invoicesToday'], 'one bill was raised today (the reversal is not a bill raised to a customer)');
    }

    public function test_dashboard_a_full_return_the_same_day_nets_to_zero_revenue_and_profit(): void
    {
        $this->at('2026-05-10 12:00:00');
        $this->fullReturn($this->bill());
        $m = $this->dash();

        $this->assertEqualsWithDelta(0.0, (float) $m['todaysRevenue'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $m['todaysProfit'], 0.01);
        $this->assertEqualsWithDelta(0.0, $this->trendRevenue($m, '2026-05-10'), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->trendRevenue($m, '2026-05-10', 'monthlyRevenueTrend'), 0.01);
    }

    public function test_dashboard_an_earlier_days_sale_keeps_its_day_and_the_return_lands_today(): void
    {
        $this->at('2026-05-05 12:00:00');
        $bill = $this->bill();
        $this->at('2026-05-08 12:00:00');
        $this->fullReturn($bill);
        $m = $this->dash();

        $this->assertEqualsWithDelta(1545.0, $this->trendRevenue($m, '2026-05-05'), 0.01, 'the sale day is not rewritten');
        $this->assertEqualsWithDelta(-1545.0, $this->trendRevenue($m, '2026-05-08'), 0.01, 'the return lands today');
        $this->assertEqualsWithDelta(1545.0, $this->trendRevenue($m, '2026-05-05', 'monthlyRevenueTrend'), 0.01);
        $this->assertEqualsWithDelta(-1545.0, $this->trendRevenue($m, '2026-05-08', 'monthlyRevenueTrend'), 0.01);
        $this->assertEqualsWithDelta(-1545.0, (float) $m['todaysRevenue'], 0.01);
    }

    public function test_dashboard_a_reversal_on_a_later_day_does_the_same(): void
    {
        $this->at('2026-05-05 12:00:00');
        $bill = $this->bill();
        $this->at('2026-05-08 12:00:00');
        $this->reverse($bill);
        $m = $this->dash();

        $this->assertEqualsWithDelta(1545.0, $this->trendRevenue($m, '2026-05-05'), 0.01);
        $this->assertEqualsWithDelta(-1545.0, $this->trendRevenue($m, '2026-05-08'), 0.01);
        $this->assertEqualsWithDelta(-1545.0, (float) $m['todaysRevenue'], 0.01);
    }

    public function test_dashboard_a_partial_return_today_reduces_revenue_by_the_returned_line(): void
    {
        $this->at('2026-05-10 12:00:00');
        $bill = $this->bill();
        $this->returnLine($bill, 0);
        $m = $this->dash();

        $this->assertEqualsWithDelta(515.0, (float) $m['todaysRevenue'], 0.01);
        $this->assertEqualsWithDelta(515.0, $this->trendRevenue($m, '2026-05-10'), 0.01);
        $this->assertSame(1, $m['invoicesToday']);
    }

    public function test_dashboard_top_customers_do_not_go_negative_after_a_reversal(): void
    {
        $this->at('2026-05-10 12:00:00');
        $this->reverse($this->bill());

        $this->assertEqualsWithDelta(0.0, (float) $this->dash()['topCustomers']->sum('total_spend'), 0.01);
        $this->assertEqualsWithDelta(0.0, Invoice::spentByCustomer($this->shop->id, $this->customer->id), 0.01, 'lifetime spend of a customer whose only bill was reversed');
    }

    public function test_the_invoice_list_totals_net_a_same_day_reversal_to_zero(): void
    {
        $this->at('2026-05-10 12:00:00');
        $this->reverse($this->bill());

        $stats = $this->actingAs($this->owner)->get(route('invoices.index'))->viewData('stats');
        $this->assertEqualsWithDelta(0.0, (float) $stats->today_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $stats->month_total, 0.01);
    }

    public function test_the_sales_register_lists_the_cancelled_original_in_its_own_month_and_nets_with_the_reversal(): void
    {
        $this->at('2026-03-20 12:00:00');
        $bill = $this->bill();
        $this->at('2026-05-10 12:00:00');
        $this->reverse($bill);

        $march = $this->register('2026-03-01', '2026-03-31');
        $may = $this->register('2026-05-01', '2026-05-31');
        $this->assertEqualsWithDelta(1500.0, (float) array_sum(array_column($march, 'taxable')), 0.01, 'the bill is in March');
        $this->assertEqualsWithDelta(-1500.0, (float) array_sum(array_column($may, 'taxable')), 0.01, 'the reversal is in May');
    }

    /** @return array<int,array<string,mixed>> */
    private function register(string $from, string $to): array
    {
        $registry = app(\App\Services\Reporting\Definition\ReportRegistry::class);
        $definition = $registry->definition('sales-register');
        $user = \Mockery::mock(\App\Models\User::class);
        $user->shouldReceive('hasPermission')->andReturn(true);
        $keys = app(\App\Services\Reporting\ColumnPolicy::class)->resolve($definition, $definition->profiles[0], $user, includeSensitive: false)->columnKeys;
        $request = new \App\Services\Reporting\Dataset\ReportRequest(
            definition: $definition, shopId: (int) $this->shop->id, userId: 1, userName: 'Tester',
            profile: $definition->profiles[0], format: \App\Services\Reporting\Definition\ExportFormat::Csv,
            filters: ['period' => [
                'from' => CarbonImmutable::parse($from, 'Asia/Kolkata')->startOfDay(),
                'to' => CarbonImmutable::parse($to, 'Asia/Kolkata')->endOfDay(),
            ]],
            columnKeys: $keys,
        );
        $meta = new \App\Services\Reporting\Dataset\ReportMeta(
            reportKey: 'sales-register', reportVersion: $definition->version, title: $definition->title, profileLabel: 'Fixed',
            format: 'csv', filtersApplied: [], periodLabel: "$from to $to", shopLegalName: 'Shop', shopAddress: null,
            shopGstin: null, shopStateCode: '29', generatedByName: 'Tester', generatedAt: now(), generatorTag: 'test',
        );

        return TenantContext::runFor((int) $this->shop->id, fn () => $registry->datasetService('sales-register')->build($request, $meta))
            ->section('sales')->rows;
    }

    public function test_the_reconciliation_command_agrees_with_the_reports_after_cancellations_and_returns(): void
    {
        $this->at('2026-04-20 12:00:00');
        $april = $this->bill();
        $this->at('2026-05-01 12:00:00');
        $sameDay = $this->bill();
        $this->reverse($sameDay);
        $this->reverse($april);
        $returned = $this->bill();
        $this->returnLine($returned, 0);

        $exit = \Artisan::call('reports:validate', ['--shop' => $this->shop->id, '--month' => 5, '--year' => 2026]);

        $this->assertSame(0, $exit, \Artisan::output());
    }

    public function test_payment_reconciliation_keeps_the_cancelled_bill_in_its_month_and_never_flags_the_reversal_as_unpaid(): void
    {
        $this->at('2026-05-10 12:00:00');
        $this->reverse($this->bill());

        $recon = app(\App\Reporting\SalesService::class)->paymentReconciliation($this->shop->id, ReportPeriod::range('2026-05-01', '2026-05-31'));
        $this->assertSame(1, $recon->invoiceCount, 'the bill that was issued; its reversal is not a bill anyone pays');
        $numbers = $recon->mismatches->pluck('total')->all();
        $this->assertNotContains(-1545.0, $numbers, 'the reversal bill must not read as an unpaid invoice');
    }

    public function test_a_reversal_of_a_discounted_bill_nets_revenue_to_zero(): void
    {
        $this->at('2026-05-10 12:00:00');
        // A discounted bill: the discount sits on the bill; its reversal must carry the same discount back.
        $bill = $this->bill(null, null, 100);
        $this->reverse($bill);

        $this->assertEqualsWithDelta(0.0, app(ProfitReportingService::class)->summary($this->shop->id, ReportPeriod::range('2026-05-01', '2026-05-31'))->revenue, 0.01);
    }
}
