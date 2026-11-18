<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Shop;
use App\Services\InvoiceAccountingService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Invoice numbers across the financial-year boundary (owner, 2026-11-16). The old "reset every April" switch
 * reused last year's numbers and every sale on 1 April failed on invoices_shop_sequence_unique.
 */
class InvoiceNumberingTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private Shop $shop;
    private Customer $customer;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [, $this->shop] = $this->createRetailerTenant();
        $this->customer = $this->createCustomer($this->shop->id);
    }

    private function at(string $istDateTime): void
    {
        $this->travelTo(CarbonImmutable::parse($istDateTime, 'Asia/Kolkata'));
    }

    private function billing(array $values): void
    {
        DB::table('shop_billing_settings')->where('shop_id', $this->shop->id)->update($values);
    }

    private function issue(): Invoice
    {
        return TenantContext::runFor($this->shop->id, fn () => Invoice::issue([
            'shop_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'gold_rate' => 7200,
            'subtotal' => 1000, 'gst' => 0, 'total' => 1000,
            'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now(),
        ]))->refresh();
    }

    private function draft(): Invoice
    {
        return TenantContext::runFor($this->shop->id, fn () => InvoiceAccountingService::createDraft([
            'shop_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'gold_rate' => 7200,
        ]))->refresh();
    }

    private function finalize(Invoice $draft): Invoice
    {
        return TenantContext::runFor($this->shop->id, fn () => InvoiceAccountingService::finalizeDraft($draft))->refresh();
    }

    public function test_continuous_numbering_runs_across_the_boundary_and_records_each_documents_year(): void
    {
        $this->at('2027-03-31 23:58:00');
        $a = $this->issue();
        $b = $this->issue();
        $this->at('2027-04-01 00:02:00');
        $c = $this->issue();

        $this->assertSame(['INV-1001', 'INV-1002', 'INV-1003'], [$a->invoice_number, $b->invoice_number, $c->invoice_number]);
        $this->assertSame(['2026-27', '2026-27', '2027-28'], [$a->fiscal_year, $b->fiscal_year, $c->fiscal_year]);
    }

    public function test_a_yearly_series_restarts_on_the_first_of_april_instead_of_failing(): void
    {
        $this->billing(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);

        $this->at('2027-03-30 12:00:00');
        $a = $this->issue();
        $b = $this->issue();
        $this->at('2027-04-01 10:00:00');
        $c = $this->issue();
        $d = $this->issue();

        $this->assertSame(['INV/26-27/1001', 'INV/26-27/1002'], [$a->invoice_number, $b->invoice_number]);
        $this->assertSame(['INV/27-28/1001', 'INV/27-28/1002'], [$c->invoice_number, $d->invoice_number]);
        $this->assertSame([1001, 1001], [(int) $a->invoice_sequence, (int) $c->invoice_sequence]);
        $this->assertSame('2027-28', $c->fiscal_year);
    }

    public function test_the_year_token_also_works_in_the_suffix(): void
    {
        $this->billing(['invoice_prefix' => 'SH-', 'invoice_suffix' => '/{FY}', 'invoice_series_yearly_from' => '2026-27']);
        $this->at('2026-11-16 12:00:00');

        $this->assertSame('SH-1001/26-27', $this->issue()->invoice_number);
    }

    public function test_a_yearly_setting_without_the_year_token_never_blocks_a_sale(): void
    {
        // Settings refuse this combination; if it reaches the database anyway the series must stay unique.
        $this->billing(['invoice_prefix' => 'INV-', 'invoice_series_yearly_from' => '2026-27']);

        $this->at('2027-03-31 12:00:00');
        $a = $this->issue();
        $this->at('2027-04-01 12:00:00');
        $b = $this->issue();

        $this->assertSame(['INV-1001', 'INV-1002'], [$a->invoice_number, $b->invoice_number]);
    }

    public function test_the_yearly_series_only_applies_from_its_first_year(): void
    {
        $this->billing(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2027-28']);

        $this->at('2027-03-31 12:00:00');
        $a = $this->issue(); // still continuous in 2026-27
        $this->at('2027-04-01 12:00:00');
        $b = $this->issue(); // yearly from 2027-28

        $this->assertSame('INV/26-27/1001', $a->invoice_number);
        $this->assertSame('INV/27-28/1001', $b->invoice_number);
        $this->assertSame('continuous', DB::table('invoices')->where('id', $a->id)->value('invoice_series'));
        $this->assertSame('yearly', DB::table('invoices')->where('id', $b->id)->value('invoice_series'));
    }

    public function test_a_yearly_series_can_end_and_numbering_continues_without_reusing_a_number(): void
    {
        $this->billing(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27', 'invoice_series_yearly_until' => '2027-28']);

        $this->at('2027-03-31 12:00:00');
        $a = $this->issue();
        $this->at('2027-04-01 12:00:00');
        $b = $this->issue();
        $c = $this->issue();

        $this->assertSame('INV/26-27/1001', $a->invoice_number);
        $this->assertNotSame($a->invoice_number, $b->invoice_number);
        $this->assertSame(2, collect([$b, $c])->pluck('invoice_number')->unique()->count());
        $this->assertSame('continuous', DB::table('invoices')->where('id', $b->id)->value('invoice_series'));
    }

    public function test_a_draft_started_in_march_and_finalized_in_april_takes_the_new_years_number(): void
    {
        $this->billing(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);

        $this->at('2027-03-31 23:50:00');
        $draft = $this->draft();
        $draftNumber = $draft->invoice_number;
        $this->assertSame('INV/26-27/1001', $draftNumber);

        $this->at('2027-04-01 00:10:00');
        $final = $this->finalize($draft);

        $this->assertSame('INV/27-28/1001', $final->invoice_number);
        $this->assertSame('2027-28', $final->fiscal_year);
        // The number the draft held is accounted for, not silently lost.
        $old = DB::table('invoice_number_events')->where('shop_id', $this->shop->id)->where('invoice_number', $draftNumber)->first();
        $this->assertSame('voided', $old->event_type);
        $this->assertSame('2026-27', $old->fiscal_year);
        $this->assertSame('consumed', DB::table('invoice_number_events')->where('invoice_number', 'INV/27-28/1001')->value('event_type'));
    }

    public function test_a_continuous_draft_keeps_its_number_but_belongs_to_the_year_it_was_billed(): void
    {
        $this->at('2027-03-31 23:50:00');
        $draft = $this->draft();
        $this->at('2027-04-01 00:10:00');
        $final = $this->finalize($draft);

        $this->assertSame($draft->invoice_number, $final->invoice_number);
        $this->assertSame('2027-28', $final->fiscal_year);
    }

    public function test_a_draft_finalized_in_the_same_year_keeps_its_number(): void
    {
        $this->billing(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);
        $this->at('2026-11-16 10:00:00');
        $draft = $this->draft();
        $this->at('2026-11-16 10:05:00');

        $this->assertSame($draft->invoice_number, $this->finalize($draft)->invoice_number);
    }

    public function test_a_finished_invoices_year_and_series_cannot_be_rewritten(): void
    {
        $this->at('2026-11-16 12:00:00');
        $invoice = $this->issue();

        $this->expectExceptionMessageMatches('/immutable|cannot/i');
        DB::table('invoices')->where('id', $invoice->id)->update(['fiscal_year' => '2025-26']);
    }

    public function test_the_same_sequence_may_appear_once_per_year_and_never_twice_in_one_year(): void
    {
        $this->billing(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);
        $this->at('2027-03-31 12:00:00');
        $a = $this->issue();
        $this->at('2027-04-01 12:00:00');
        $this->issue();

        $this->assertSame(2, DB::table('invoice_number_events')->where('shop_id', $this->shop->id)->where('sequence_value', 1001)->count());

        // Forcing a duplicate inside one year is still refused by the database.
        $this->expectExceptionMessageMatches('/unique/i');
        DB::table('invoices')->insert([
            'shop_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'invoice_number' => 'X-DUP',
            'invoice_sequence' => $a->invoice_sequence, 'fiscal_year' => '2026-27', 'status' => 'draft',
            'subtotal' => 0, 'gst' => 0, 'total' => 0, 'gold_rate' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_cancelling_a_last_year_invoice_records_the_cancel_in_that_invoices_own_year(): void
    {
        $this->billing(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);
        $this->at('2027-03-31 12:00:00');
        $old = $this->issue();
        $this->at('2027-04-01 12:00:00');
        $this->issue(); // 27-28 / 1001 consumed

        TenantContext::runFor($this->shop->id, fn () => InvoiceAccountingService::cancelByReversal($old, 'Wrong customer'));

        $this->assertSame('cancelled', DB::table('invoice_number_events')->where('invoice_number', $old->invoice_number)->value('event_type'));
        $this->assertSame('consumed', DB::table('invoice_number_events')->where('invoice_number', 'INV/27-28/1001')->value('event_type'));
    }

    public function test_the_issued_model_carries_its_number_without_a_reload(): void
    {
        // Callers use the number straight after issuing (receipts, toasts, links).
        $this->at('2026-11-16 12:00:00');
        $invoice = TenantContext::runFor($this->shop->id, fn () => Invoice::issue([
            'shop_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'gold_rate' => 7200,
            'subtotal' => 1000, 'gst' => 0, 'total' => 1000, 'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now(),
        ]));

        $this->assertSame('INV-1001', $invoice->invoice_number);
        $this->assertSame(1001, $invoice->invoice_sequence);
        $this->assertSame('2026-27', $invoice->fiscal_year);
        $this->assertFalse($invoice->isDirty());
    }

    public function test_another_shops_series_is_untouched(): void
    {
        [, $other] = $this->createRetailerTenant();
        $otherCustomer = $this->createCustomer($other->id);
        $this->billing(['invoice_prefix' => 'INV/{FY}/', 'invoice_series_yearly_from' => '2026-27']);
        $this->at('2027-04-01 12:00:00');
        $this->issue();

        $theirs = TenantContext::runFor($other->id, fn () => Invoice::issue([
            'shop_id' => $other->id, 'customer_id' => $otherCustomer->id, 'gold_rate' => 7200,
            'subtotal' => 1000, 'gst' => 0, 'total' => 1000, 'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now(),
        ]))->refresh();

        $this->assertSame('INV-1001', $theirs->invoice_number);
    }
}
