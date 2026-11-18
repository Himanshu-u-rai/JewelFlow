<?php

namespace Tests\Feature\FinancialYear\Reports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Reporting\ReportPeriod;
use App\Reporting\TaxService;
use App\Services\Reporting\Definition\ReportClassification;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** HSN summary (GSTR-1 table 12) for a financial year, net of credit notes, agreeing with the sum of its months. */
class HsnAnnualReportTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, BuildsGstYearReports;

    private const KEY = 'gst-hsn-annual';

    private Shop $shop;
    private Customer $customer;
    private $owner;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->customer = $this->createCustomer($this->shop->id);
        ShopPreferences::withoutTenant()->updateOrCreate(['shop_id' => $this->shop->id], [
            'refund_making_charges' => true, 'refund_stone_charges' => true, 'refund_gst' => true,
            'wear_loss_pct' => 0, 'restocking_fee_pct' => 0, 'return_settlement_mode' => 'cash_or_credit',
        ]);
    }

    /** @return array<string, array<string, mixed>> rows keyed by HSN */
    private function byHsn(string $from, string $to, ?Shop $shop = null): array
    {
        $out = [];
        foreach ($this->buildReport($shop ?? $this->shop, self::KEY, $from, $to)->section('hsn')->rows as $row) {
            $out[$row['hsn_code']] = $row;
        }

        return $out;
    }

    private function partialReturn(Invoice $bill, int $lineIndex): void
    {
        $line = $bill->items()->orderBy('id')->get()[$lineIndex];
        TenantContext::runFor($this->shop->id, fn () => app(ReturnService::class)->createPartialReturn(
            $bill->fresh(), [$line->id => ['disposition' => 'restocked']], 'one piece back', $this->owner->id));
    }

    public function test_it_is_a_registered_compliance_dataset(): void
    {
        $definition = app(ReportRegistry::class)->definition(self::KEY);

        $this->assertSame(ReportClassification::Compliance, $definition->classification);
        $this->assertSame('HSN summary', $definition->title);
    }

    public function test_one_row_per_hsn_with_uqc_quantity_value_and_tax_split(): void
    {
        $this->at('2026-11-10 10:00:00');
        $this->makeBill($this->shop, $this->customer, [['7113', 50000, 1500, 12.5], ['7113', 20000, 600, 5.0], ['7114', 10000, 300, 2.0]]);

        $rows = $this->byHsn(...$this->fy('2026-27'));

        $this->assertSame(['7113', '7114'], array_map('strval', array_keys($rows)));
        $r = $rows['7113'];
        $this->assertSame('GMS', $r['uqc']);
        $this->assertEqualsWithDelta(17.5, $r['quantity'], 0.0005);
        $this->assertEqualsWithDelta(70000, $r['taxable'], 0.005);
        $this->assertEqualsWithDelta(1050, $r['cgst'], 0.005);
        $this->assertEqualsWithDelta(1050, $r['sgst'], 0.005);
        $this->assertEqualsWithDelta(0, $r['igst'], 0.005);
        $this->assertEqualsWithDelta(0, $r['cess'], 0.005);
        $this->assertEqualsWithDelta(72100, $r['total_value'], 0.005);
    }

    public function test_an_inter_state_bill_reports_igst_not_cgst_and_sgst(): void
    {
        $this->at('2026-11-10 10:00:00');
        $this->makeBill($this->shop, $this->customer, [['7113', 50000, 1500]], interState: true);

        $r = $this->byHsn(...$this->fy('2026-27'))['7113'];

        $this->assertEqualsWithDelta(1500, $r['igst'], 0.005);
        $this->assertEqualsWithDelta(0, $r['cgst'] + $r['sgst'], 0.005);
    }

    public function test_credit_notes_are_netted_by_hsn_in_the_year_they_were_issued(): void
    {
        $this->at('2026-11-10 10:00:00');
        $bill = $this->makeBill($this->shop, $this->customer, [['7113', 50000, 1500, 10], ['7114', 20000, 600, 4]]);
        $this->at('2026-12-02 10:00:00');
        $this->partialReturn($bill, 1);

        $rows = $this->byHsn(...$this->fy('2026-27'));

        $this->assertEqualsWithDelta(50000, $rows['7113']['taxable'], 0.005);
        $this->assertEqualsWithDelta(0, $rows['7114']['taxable'], 0.005);
        $this->assertEqualsWithDelta(0, $rows['7114']['quantity'], 0.0005);
        $this->assertEqualsWithDelta(0, $rows['7114']['cgst'] + $rows['7114']['sgst'], 0.005);
    }

    public function test_a_credit_note_in_the_next_year_reduces_that_year_not_the_year_of_the_sale(): void
    {
        $this->at('2027-03-20 10:00:00');
        $bill = $this->makeBill($this->shop, $this->customer, [['7113', 50000, 1500, 10], ['7114', 1000, 30, 1]]);
        $this->at('2027-04-10 10:00:00');
        $this->partialReturn($bill, 0);

        $old = $this->byHsn(...$this->fy('2026-27'));
        $new = $this->byHsn(...$this->fy('2027-28'));

        $this->assertEqualsWithDelta(50000, $old['7113']['taxable'], 0.005);
        $this->assertEqualsWithDelta(-50000, $new['7113']['taxable'], 0.005);
        $this->assertEqualsWithDelta(-10, $new['7113']['quantity'], 0.0005);
    }

    public function test_a_sale_on_the_last_second_of_march_and_one_at_midnight_land_in_their_own_years(): void
    {
        $this->at('2027-03-31 23:59:59');
        $this->makeBill($this->shop, $this->customer, [['7113', 10000, 300]]);
        $this->at('2027-04-01 00:00:00');
        $this->makeBill($this->shop, $this->customer, [['7113', 20000, 600]]);

        $this->assertEqualsWithDelta(10000, $this->byHsn(...$this->fy('2026-27'))['7113']['taxable'], 0.005);
        $this->assertEqualsWithDelta(20000, $this->byHsn(...$this->fy('2027-28'))['7113']['taxable'], 0.005);
    }

    public function test_lines_without_an_hsn_are_grouped_under_a_dash_like_the_monthly_gstr1(): void
    {
        $this->at('2026-11-10 10:00:00');
        $this->makeBill($this->shop, $this->customer, [['', 5000, 150]]);

        $this->assertArrayHasKey('—', $this->byHsn(...$this->fy('2026-27')));
    }

    public function test_cancelled_and_draft_invoices_are_not_in_the_summary(): void
    {
        $this->at('2026-11-10 10:00:00');
        $bill = $this->makeBill($this->shop, $this->customer, [['7113', 50000, 1500]]);
        $this->cancel($this->shop, $bill);
        $this->makeBill($this->shop, $this->customer, [['7113', 1000, 30]]);

        $rows = $this->byHsn(...$this->fy('2026-27'));

        $this->assertEqualsWithDelta(1000, $rows['7113']['taxable'], 0.005);
    }

    public function test_the_year_equals_the_sum_of_its_twelve_months_and_the_gross_figures_equal_the_monthly_gstr1_hsn_section(): void
    {
        $bills = [
            ['2026-04-01 00:00:00', [['7113', 10000, 300, 3], ['7114', 2000, 60, 1]]],
            ['2026-06-30 23:59:59', [['7113', 20000, 600, 6], ['7114', 100, 3, 0.1]]],
            ['2026-08-15 12:00:00', [['7113', 30000, 900, 9], ['9999', 500, 15, 0.5]]],
            ['2026-12-31 23:59:59', [['7114', 40000, 1200, 4], ['7113', 100, 3, 0.1]]],
            ['2027-01-01 00:00:00', [['7113', 5000, 150, 1]]],
            ['2027-03-31 23:59:59', [['7113', 7000, 210, 2]]],
        ];
        $made = [];
        foreach ($bills as [$when, $lines]) {
            $this->at($when);
            $made[] = $this->makeBill($this->shop, $this->customer, $lines);
        }
        $this->at('2026-09-10 10:00:00');
        $this->partialReturn($made[1], 0); // credit note in September
        $this->at('2027-02-05 10:00:00');
        $this->partialReturn($made[3], 0); // credit note in February

        $annual = $this->byHsn(...$this->fy('2026-27'));

        $sum = [];
        $monthlyGrossTaxable = $monthlyGrossGst = 0.0;
        foreach (range(0, 11) as $i) {
            $start = \Carbon\CarbonImmutable::create(2026, 4, 1, 0, 0, 0, 'Asia/Kolkata')->addMonthsNoOverflow($i);
            $rows = $this->byHsn($start->toDateString(), $start->endOfMonth()->toDateString());
            foreach ($rows as $hsn => $r) {
                foreach (['quantity', 'taxable', 'igst', 'cgst', 'sgst', 'cess', 'total_value'] as $col) {
                    $sum[$hsn][$col] = ($sum[$hsn][$col] ?? 0) + $r[$col];
                }
            }
            $period = ReportPeriod::range($start->toDateString(), $start->endOfMonth()->toDateString());
            foreach (app(TaxService::class)->gstr1($this->shop->id, $period)->hsnSummary as $h) {
                $monthlyGrossTaxable += (float) $h->taxable;
                $monthlyGrossGst += (float) $h->gst;
            }
        }

        $this->assertEqualsCanonicalizing(array_keys($annual), array_keys($sum));
        foreach ($annual as $hsn => $row) {
            foreach (['quantity', 'taxable', 'igst', 'cgst', 'sgst', 'cess', 'total_value'] as $col) {
                $this->assertEqualsWithDelta($sum[$hsn][$col], $row[$col], 0.005, "$hsn $col");
            }
        }

        // The monthly GSTR-1 HSN section is net of credit notes too (owner, 2026-11-16), so the twelve monthly
        // sections add up to the year exactly.
        $cnTaxable = (float) \Illuminate\Support\Facades\DB::table('return_line_items')->where('shop_id', $this->shop->id)->sum('refund_subtotal');
        $this->assertEqualsWithDelta(array_sum(array_column($annual, 'taxable')), $monthlyGrossTaxable, 0.005);
        $this->assertEqualsWithDelta(
            array_sum(array_column($annual, 'cgst')) + array_sum(array_column($annual, 'sgst')) + array_sum(array_column($annual, 'igst')),
            $monthlyGrossGst,
            0.005
        );
        $this->assertGreaterThan(0, $cnTaxable, 'the scenario must include credit notes');
    }

    public function test_the_monthly_gstr1_hsn_section_is_reduced_by_a_credit_note_issued_that_month(): void
    {
        $this->at('2026-08-05 12:00:00');
        $bill = $this->makeBill($this->shop, $this->customer, [['7113', 10000, 300, 3]]);
        $this->at('2026-09-10 10:00:00');
        $this->partialReturn($bill, 0);

        $hsnFor = function (string $from, string $to): float {
            return (float) collect(app(TaxService::class)->gstr1($this->shop->id, ReportPeriod::range($from, $to))->hsnSummary)
                ->where('hsn_code', '7113')->sum('taxable');
        };
        $refunded = (float) \Illuminate\Support\Facades\DB::table('return_line_items')->where('shop_id', $this->shop->id)->sum('refund_subtotal');

        $this->assertGreaterThan(0, $refunded);
        $this->assertEqualsWithDelta(10000.0, $hsnFor('2026-08-01', '2026-08-31'), 0.005, 'the sale month is unchanged');
        $this->assertEqualsWithDelta(-$refunded, $hsnFor('2026-09-01', '2026-09-30'), 0.005, 'the credit note reduces its own month');
    }

    public function test_two_shops_are_isolated(): void
    {
        [, $other] = $this->createRetailerTenant();
        $otherCustomer = $this->createCustomer($other->id);
        $this->at('2026-11-10 10:00:00');
        $this->makeBill($this->shop, $this->customer, [['7113', 50000, 1500]]);
        $this->makeBill($other, $otherCustomer, [['7113', 1000, 30]]);

        $this->assertEqualsWithDelta(50000, $this->byHsn(...$this->fy('2026-27'))['7113']['taxable'], 0.005);
        $this->assertEqualsWithDelta(1000, $this->byHsn(...$this->fy('2026-27'), shop: $other)['7113']['taxable'], 0.005);
    }

    public function test_an_empty_year_returns_no_rows_and_no_error(): void
    {
        $dataset = $this->buildReport($this->shop, self::KEY, '2020-04-01', '2021-03-31');

        $this->assertSame([], $dataset->section('hsn')->rows);
    }

    public function test_the_basis_note_says_quick_bills_are_excluded_and_cess_is_not_recorded(): void
    {
        $basis = implode(' ', array_column($this->buildReport($this->shop, self::KEY, '2026-04-01', '2027-03-31')->section('basis')->rows, 'note'));

        $this->assertStringContainsString('Quick bills are not included', $basis);
        $this->assertStringContainsString('cess', strtolower($basis));
    }

    public function test_the_generic_screen_renders_for_the_named_financial_year_preset(): void
    {
        $this->at('2026-11-10 10:00:00');
        $this->makeBill($this->shop, $this->customer, [['7113', 50000, 1500]]);
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class, \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class, \App\Http\Middleware\EnsureShopExists::class,
        ]);
        TenantContext::runFor((int) $this->shop->id, function () {
            $this->owner->role->permissions()->syncWithoutDetaching(\App\Models\Permission::whereIn('name', ['reports.view'])->pluck('id'));
        });

        $response = TenantContext::runFor((int) $this->shop->id, fn () => $this->actingAs($this->owner)
            ->get('/reporting/' . self::KEY . '/screen?date_preset=named_fy&fy_name=2026-27'));

        $response->assertOk();
        $response->assertSee('7113');
    }
}
