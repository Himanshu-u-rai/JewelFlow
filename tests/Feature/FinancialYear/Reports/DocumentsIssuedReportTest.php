<?php

namespace Tests\Feature\FinancialYear\Reports;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Services\Reporting\Definition\ReportClassification;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** GSTR-1 Table 13 "Documents issued during the tax period", per document type and series. */
class DocumentsIssuedReportTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, BuildsGstYearReports;

    private const KEY = 'gst-documents-issued';

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

    /** @return array<int, array<string, mixed>> */
    private function rows(string $from, string $to, ?Shop $shop = null): array
    {
        return $this->buildReport($shop ?? $this->shop, self::KEY, $from, $to)->section('documents')->rows;
    }

    private function invoiceRows(array $rows): array
    {
        return array_values(array_filter($rows, fn ($r) => $r['doc_type'] === 'Invoices for outward supply'));
    }

    private function cnRows(array $rows): array
    {
        return array_values(array_filter($rows, fn ($r) => $r['doc_type'] === 'Credit note'));
    }

    public function test_the_report_is_registered_as_a_compliance_dataset(): void
    {
        $definition = app(ReportRegistry::class)->definition(self::KEY);

        $this->assertSame(ReportClassification::Compliance, $definition->classification);
        $this->assertSame('Documents issued', $definition->title);
    }

    public function test_numbers_are_listed_from_lowest_to_highest_with_counts(): void
    {
        $this->at('2026-11-10 10:00:00');
        $this->issue($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);

        $rows = $this->invoiceRows($this->rows('2026-11-01', '2026-11-30'));

        $this->assertCount(1, $rows);
        $this->assertSame(['INV-', 'INV-1001', 'INV-1003', 3, 0, 3],
            [$rows[0]['series'], $rows[0]['from'], $rows[0]['to'], $rows[0]['total'], $rows[0]['cancelled'], $rows[0]['net']]);
    }

    public function test_an_invoice_issued_at_the_last_second_of_march_belongs_to_the_old_year_and_one_at_midnight_to_the_new(): void
    {
        $this->at('2027-03-31 23:59:59');
        $this->issue($this->shop, $this->customer);
        $this->at('2027-04-01 00:00:00');
        $this->issue($this->shop, $this->customer);

        $old = $this->invoiceRows($this->rows(...$this->fy('2026-27')));
        $new = $this->invoiceRows($this->rows(...$this->fy('2027-28')));

        $this->assertSame(['INV-1001', 'INV-1001', 1], [$old[0]['from'], $old[0]['to'], $old[0]['total']]);
        $this->assertSame(['INV-1002', 'INV-1002', 1], [$new[0]['from'], $new[0]['to'], $new[0]['total']]);
    }

    public function test_a_continuous_series_spanning_years_shows_the_right_from_and_to_in_each_year(): void
    {
        $this->at('2027-03-30 12:00:00');
        $this->issue($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);
        $this->at('2027-04-02 12:00:00');
        $this->issue($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);

        $old = $this->invoiceRows($this->rows(...$this->fy('2026-27')));
        $new = $this->invoiceRows($this->rows(...$this->fy('2027-28')));

        $this->assertSame(['INV-1001', 'INV-1002', 2], [$old[0]['from'], $old[0]['to'], $old[0]['total']]);
        $this->assertSame(['INV-1003', 'INV-1005', 3], [$new[0]['from'], $new[0]['to'], $new[0]['total']]);
    }

    public function test_a_yearly_series_restart_shows_as_two_series_rows_over_a_two_year_range(): void
    {
        $this->billing($this->shop, ['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);
        $this->at('2027-03-30 12:00:00');
        $this->issue($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);
        $this->at('2027-04-01 10:00:00');
        $this->issue($this->shop, $this->customer);

        $rows = $this->invoiceRows($this->rows('2027-03-01', '2027-04-30'));

        $this->assertCount(2, $rows);
        $this->assertSame(['INV/26-27/', 'INV/26-27/1001', 'INV/26-27/1002', 2], [$rows[0]['series'], $rows[0]['from'], $rows[0]['to'], $rows[0]['total']]);
        $this->assertSame(['INV/27-28/', 'INV/27-28/1001', 'INV/27-28/1001', 1], [$rows[1]['series'], $rows[1]['from'], $rows[1]['to'], $rows[1]['total']]);
    }

    public function test_a_cancelled_invoice_counts_in_total_and_in_cancelled_and_the_reversal_bill_it_creates_is_a_number_too(): void
    {
        $this->at('2026-11-10 10:00:00');
        $this->issue($this->shop, $this->customer);
        $second = $this->issue($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);
        $this->cancel($this->shop, $second); // cancelByReversal issues a reversal bill: INV-1004

        $row = $this->invoiceRows($this->rows('2026-11-01', '2026-11-30'))[0];

        $this->assertSame([4, 1, 3], [$row['total'], $row['cancelled'], $row['net']]);
        $this->assertSame(['INV-1001', 'INV-1004'], [$row['from'], $row['to']]);
    }

    public function test_a_draft_that_moved_into_the_next_year_voids_its_old_number_which_counts_as_cancelled_in_the_old_year(): void
    {
        $this->billing($this->shop, ['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);
        $this->at('2027-03-30 12:00:00');
        $draft = $this->draft($this->shop, $this->customer);
        $this->at('2027-04-02 12:00:00');
        $billed = $this->finalize($this->shop, $draft);

        $this->assertSame('INV/27-28/1001', $billed->invoice_number);

        $old = $this->invoiceRows($this->rows(...$this->fy('2026-27')));
        $new = $this->invoiceRows($this->rows(...$this->fy('2027-28')));

        $this->assertSame(['INV/26-27/', 'INV/26-27/1001', 1, 1, 0], [$old[0]['series'], $old[0]['from'], $old[0]['total'], $old[0]['cancelled'], $old[0]['net']]);
        $this->assertSame(['INV/27-28/', 'INV/27-28/1001', 1, 0, 1], [$new[0]['series'], $new[0]['from'], $new[0]['total'], $new[0]['cancelled'], $new[0]['net']]);
    }

    public function test_a_draft_never_billed_counts_as_a_cancelled_number_because_its_number_was_used(): void
    {
        $this->at('2026-11-10 10:00:00');
        $this->issue($this->shop, $this->customer);
        $this->draft($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);

        $row = $this->invoiceRows($this->rows('2026-11-01', '2026-11-30'))[0];

        $this->assertSame([3, 1, 2], [$row['total'], $row['cancelled'], $row['net']]);
    }

    public function test_a_continuous_draft_carried_into_the_next_year_counts_once_in_the_year_it_was_billed(): void
    {
        $this->at('2027-03-30 12:00:00');
        $draft = $this->draft($this->shop, $this->customer);
        $this->at('2027-04-02 12:00:00');
        $this->finalize($this->shop, $draft);

        $old = $this->invoiceRows($this->rows(...$this->fy('2026-27')));
        $new = $this->invoiceRows($this->rows(...$this->fy('2027-28')));

        $this->assertSame([], $old);
        $this->assertSame([1, 0, 1], [$new[0]['total'], $new[0]['cancelled'], $new[0]['net']]);
    }

    public function test_credit_notes_are_counted_separately_from_invoices_and_by_their_issue_date(): void
    {
        $this->at('2027-03-15 12:00:00');
        $bill = $this->makeBill($this->shop, $this->customer, [['7113', 50000, 1500]]);
        $this->at('2027-04-05 12:00:00');
        TenantContext::runFor($this->shop->id, fn () => app(ReturnService::class)->createFullReturn(
            $bill->fresh(), 'customer returned it', $this->owner->id, false, ReturnService::REFUND_SETTLEMENT_STORE_CREDIT));

        $oldYear = $this->rows(...$this->fy('2026-27'));
        $newYear = $this->rows(...$this->fy('2027-28'));

        $this->assertCount(1, $this->invoiceRows($oldYear));
        $this->assertSame([], $this->cnRows($oldYear));
        $this->assertSame([], $this->invoiceRows($newYear));
        $cn = $this->cnRows($newYear);
        $this->assertCount(1, $cn);
        $this->assertSame(['CN-', 'CN-1', 'CN-1', 1, 0, 1], [$cn[0]['series'], $cn[0]['from'], $cn[0]['to'], $cn[0]['total'], $cn[0]['cancelled'], $cn[0]['net']]);
    }

    public function test_two_shops_never_see_each_others_numbers(): void
    {
        [, $otherShop] = $this->createRetailerTenant();
        $otherCustomer = $this->createCustomer($otherShop->id);

        $this->at('2026-11-10 10:00:00');
        $this->issue($this->shop, $this->customer);
        $this->issue($this->shop, $this->customer);
        $this->issue($otherShop, $otherCustomer);

        $mine = $this->invoiceRows($this->rows('2026-11-01', '2026-11-30'));
        $theirs = $this->invoiceRows($this->rows('2026-11-01', '2026-11-30', $otherShop));

        $this->assertSame(2, $mine[0]['total']);
        $this->assertSame(1, $theirs[0]['total']);
    }

    public function test_a_year_with_no_documents_returns_no_rows_and_no_error(): void
    {
        $dataset = $this->buildReport($this->shop, self::KEY, '2020-04-01', '2021-03-31');

        $this->assertSame([], $dataset->section('documents')->rows);
    }

    public function test_a_month_shows_only_that_months_numbers(): void
    {
        $this->at('2026-11-30 23:59:00');
        $this->issue($this->shop, $this->customer);
        $this->at('2026-12-01 00:01:00');
        $this->issue($this->shop, $this->customer);

        $nov = $this->invoiceRows($this->rows('2026-11-01', '2026-11-30'));
        $dec = $this->invoiceRows($this->rows('2026-12-01', '2026-12-31'));

        $this->assertSame(['INV-1001', 'INV-1001'], [$nov[0]['from'], $nov[0]['to']]);
        $this->assertSame(['INV-1002', 'INV-1002'], [$dec[0]['from'], $dec[0]['to']]);
    }

    public function test_the_report_states_that_quick_bills_are_excluded_and_how_drafts_are_treated(): void
    {
        $basis = implode(' ', array_column($this->buildReport($this->shop, self::KEY, '2026-04-01', '2027-03-31')->section('basis')->rows, 'note'));

        $this->assertStringContainsString('Quick bills are not included', $basis);
        $this->assertStringContainsString('draft', strtolower($basis));
    }

    public function test_the_generic_screen_renders_for_the_owner(): void
    {
        $this->at('2026-11-10 10:00:00');
        $this->issue($this->shop, $this->customer);
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class, \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class, \App\Http\Middleware\EnsureShopExists::class,
        ]);
        TenantContext::runFor((int) $this->shop->id, function () {
            $this->owner->role->permissions()->syncWithoutDetaching(Permission::whereIn('name', ['reports.view'])->pluck('id'));
        });

        $response = TenantContext::runFor((int) $this->shop->id, fn () => $this->actingAs($this->owner)
            ->get('/reporting/' . self::KEY . '/screen?date_preset=custom&date_from=2026-11-01&date_to=2026-11-30'));

        $response->assertOk();
        $response->assertSee('Documents issued');
        $response->assertSee('INV-1001');
    }
}
