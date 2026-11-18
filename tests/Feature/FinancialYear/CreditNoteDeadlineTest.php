<?php

namespace Tests\Feature\FinancialYear;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ReturnOrder;
use App\Models\ShopPreferences;
use App\Services\InvoiceAccountingService;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** No credit note for a past year's bill after 30 November of the next year (GST s.34(2)); every return path. */
class CreditNoteDeadlineTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private ReturnService $returns;
    private $owner;
    private $shop;
    private $customer;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->returns = app(ReturnService::class);
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->customer = $this->createCustomer($this->shop->id);
        ShopPreferences::withoutTenant()->updateOrCreate(['shop_id' => $this->shop->id], [
            'refund_making_charges' => true, 'refund_stone_charges' => true, 'refund_gst' => true,
            'wear_loss_pct' => 0, 'restocking_fee_pct' => 0, 'return_settlement_mode' => 'cash_or_credit',
        ]);
    }

    private function at(string $ist): void
    {
        $this->travelTo(CarbonImmutable::parse($ist, 'Asia/Kolkata'));
    }

    /** A finalized bill dated now (travelled), with one line. */
    private function bill(): Invoice
    {
        $item = $this->createItem($this->shop->id, null, ['selling_price' => 50000]);

        return TenantContext::runFor($this->shop->id, function () use ($item) {
            $draft = new Invoice();
            $draft->forceFill([
                'shop_id' => $this->shop->id, 'customer_id' => $this->customer->id, 'gold_rate' => 7200,
                'subtotal' => 0, 'gst' => 0, 'gst_rate' => 3, 'wastage_charge' => 0, 'discount' => 0,
                'round_off' => 0, 'total' => 0, 'status' => Invoice::STATUS_DRAFT,
            ])->save();
            InvoiceItem::record([
                'invoice_id' => $draft->id, 'item_id' => $item->id, 'metal_type' => $item->metal_type,
                'weight' => (float) $item->gross_weight, 'rate' => 0, 'making_charges' => (float) $item->making_charges,
                'stone_amount' => (float) $item->stone_charges, 'hallmark_charges' => 0, 'line_total' => 50000,
                'gst_rate' => 3, 'gst_amount' => 1500, 'allocated_discount' => 0, 'allocated_round_off' => 0,
                'allocated_loyalty_pts' => 0,
            ]);

            return InvoiceAccountingService::finalizeDraft($draft);
        });
    }

    private function fullReturn(Invoice $bill, bool $void = false)
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->returns->createFullReturn(
            $bill->fresh(), 'customer returned it', $this->owner->id, $void, ReturnService::REFUND_SETTLEMENT_STORE_CREDIT));
    }

    private function assertRefused(callable $attempt): void
    {
        $cnBefore = CreditNote::withoutTenant()->where('shop_id', $this->shop->id)->count();
        $roBefore = ReturnOrder::withoutTenant()->where('shop_id', $this->shop->id)->count();
        try {
            $attempt();
            $this->fail('A credit note was allowed after the GST deadline.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('30 November 2026', $e->getMessage());
        }
        $this->assertSame($cnBefore, CreditNote::withoutTenant()->where('shop_id', $this->shop->id)->count());
        $this->assertSame($roBefore, ReturnOrder::withoutTenant()->where('shop_id', $this->shop->id)->count());
    }

    public function test_a_last_years_bill_can_be_returned_up_to_30_november(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $this->at('2026-11-30 23:00:00');

        $this->fullReturn($bill);

        $this->assertSame(1, CreditNote::withoutTenant()->where('invoice_id', $bill->id)->count());
    }

    public function test_from_1_december_a_last_years_bill_cannot_get_a_credit_note(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $this->at('2026-12-01 00:05:00');

        $this->assertRefused(fn () => $this->fullReturn($bill));
    }

    public function test_the_full_void_path_obeys_the_same_deadline(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $this->at('2026-12-01 10:00:00');

        $this->assertRefused(fn () => $this->fullReturn($bill, true));
    }

    public function test_a_partial_return_obeys_the_deadline(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $line = $bill->items()->first();
        $this->at('2026-12-01 10:00:00');

        $this->assertRefused(fn () => TenantContext::runFor($this->shop->id, fn () => $this->returns->createPartialReturn(
            $bill->fresh(), [$line->id], 'one piece back', $this->owner->id)));
    }

    public function test_a_return_waiting_for_approval_cannot_be_approved_after_the_deadline(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $line = $bill->items()->first();
        $this->at('2026-11-29 10:00:00');
        $pending = TenantContext::runFor($this->shop->id, fn () => $this->returns->createPendingApproval(
            $bill->fresh(), [$line->id], 'needs approval', $this->owner->id));

        $this->at('2026-12-01 10:00:00');
        try {
            TenantContext::runFor($this->shop->id, fn () => $this->returns->approveReturn($pending->fresh(), $this->owner->id));
            $this->fail('Approval issued a credit note after the deadline.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('old-gold', $e->getMessage());
        }
        $this->assertSame(0, CreditNote::withoutTenant()->where('invoice_id', $bill->id)->count());
    }

    public function test_a_new_return_request_is_refused_up_front_after_the_deadline(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $line = $bill->items()->first();
        $this->at('2026-12-02 10:00:00');

        $this->assertRefused(fn () => TenantContext::runFor($this->shop->id, fn () => $this->returns->createPendingApproval(
            $bill->fresh(), [$line->id], 'needs approval', $this->owner->id)));
    }

    public function test_the_return_page_turns_staff_back_with_the_reason_before_they_fill_it_in(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $this->at('2026-12-01 10:00:00');

        TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(route('returns.create', $bill)))
            ->assertRedirect(route('invoices.show', $bill))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, 'old-gold purchase'));
    }

    public function test_the_exchange_page_does_the_same(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $this->at('2026-12-01 10:00:00');

        TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)->get(route('exchanges.unified.create', $bill)))
            ->assertRedirect(route('invoices.show', $bill))
            ->assertSessionHas('warning', fn ($m) => str_contains($m, '30 November 2026'));
    }

    public function test_cancelling_a_last_years_bill_by_reversal_obeys_the_deadline_even_for_the_owner(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $this->at('2026-12-01 10:00:00');
        $this->actingAs($this->owner);

        try {
            TenantContext::runFor($this->shop->id, fn () => InvoiceAccountingService::cancelByReversal($bill->fresh(), 'Customer returned the piece after a long time'));
            $this->fail('A reversal was allowed after the GST deadline.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('30 November 2026', $e->getMessage());
        }
        $this->assertSame('finalized', $bill->fresh()->status);
    }

    public function test_cancelling_a_last_years_bill_by_reversal_before_the_deadline_is_still_possible(): void
    {
        $this->at('2026-03-15 12:00:00');
        $bill = $this->bill();
        $this->at('2026-11-30 10:00:00');
        $this->actingAs($this->owner);

        TenantContext::runFor($this->shop->id, fn () => InvoiceAccountingService::cancelByReversal($bill->fresh(), 'Customer returned the piece in time'));

        $this->assertSame('cancelled', $bill->fresh()->status);
    }

    public function test_this_years_bill_is_unaffected(): void
    {
        $this->at('2026-04-02 12:00:00');
        $bill = $this->bill();
        $this->at('2026-12-15 12:00:00');

        $this->fullReturn($bill);

        $this->assertSame(1, CreditNote::withoutTenant()->where('invoice_id', $bill->id)->count());
    }
}
