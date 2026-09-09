<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\ShopPaymentMethod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3, Phase 1 (Section B) — HISTORICAL-BATCH-3-UX-CONTRACT-V2 §7/§8
 * payment wiring, exercised through the manual entry HTTP path.
 *
 * `HistoricalSalesPayment` rows created here are, by the model's own class
 * docblock, NEVER `InvoicePayment` — they never append to the live cashbook,
 * bank ledger, wallet or store-credit balance. This file proves that
 * end-to-end (not just re-asserting the schema-level guards already covered
 * by HistoricalCalculationSchemaTest): persistence, tenant validation, and
 * the settlement figures `HistoricalPaymentSettlementService` derives from
 * the payment rows, wired into the real create/draft/publish flow.
 */
class HistoricalManualPaymentWiringTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function paymentPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'PAY-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'customer_name' => 'Payment QA Customer',
            'grand_total' => 1000,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed' => '1',
            'lines' => [[
                'line_item_name' => 'QA Gold Item',
                'line_quantity' => 1,
                'line_total' => 1000,
            ]],
        ], $override);
    }

    private function makeShopPaymentMethod(int $shopId, array $attrs = []): ShopPaymentMethod
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $attrs): ShopPaymentMethod {
            $method = new ShopPaymentMethod;
            $method->forceFill(array_merge([
                'shop_id' => $shopId,
                'type' => ShopPaymentMethod::TYPE_BANK,
                'name' => 'HDFC Current',
                'is_active' => true,
                'sort_order' => 1,
            ], $attrs))->save();

            return $method;
        });
    }

    public function test_multiple_payment_rows_are_persisted_and_the_document_settlement_is_derived_from_their_sum(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->paymentPayload([
            'intent' => 'draft',
            'payments' => [
                ['mode' => 'cash', 'amount' => 400],
                ['mode' => 'upi',  'amount' => 600, 'reference' => 'UPI/REF/001'],
            ],
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertRedirect();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->latest('id')->with('payments')->firstOrFail();

            $this->assertCount(2, $document->payments);
            $this->assertEqualsWithDelta(1000.0, (float) $document->paid_amount_snapshot, 0.01);
            $this->assertEqualsWithDelta(0.0, (float) $document->outstanding_amount_snapshot, 0.01);
            $this->assertEqualsWithDelta(0.0, (float) $document->advance_credit_amount, 0.01);

            $modes = $document->payments->pluck('mode')->sort()->values()->all();
            $this->assertSame(['cash', 'upi'], $modes);
        });
    }

    public function test_a_payment_linked_to_a_shop_payment_method_snapshots_its_label_and_marks_the_link(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $method = $this->makeShopPaymentMethod($shop->id);

        $payload = $this->paymentPayload([
            'intent' => 'draft',
            'payments' => [
                ['mode' => 'bank', 'amount' => 1000, 'shop_payment_method_id' => $method->id],
            ],
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($method): void {
            $document = HistoricalSalesDocument::query()->latest('id')->with('payments')->firstOrFail();
            $payment = $document->payments->first();

            $this->assertSame($method->id, $payment->shop_payment_method_id);
            $this->assertTrue($payment->was_linked_to_payment_method);
            $this->assertSame('HDFC Current', $payment->account_label_snapshot);
        });
    }

    public function test_referencing_a_payment_method_from_another_shop_is_a_clean_validation_error_not_a_500(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();
        $foreignMethod = $this->makeShopPaymentMethod($otherShop->id, ['name' => 'Foreign Bank']);

        $payload = $this->paymentPayload([
            'intent' => 'draft',
            'payments' => [
                ['mode' => 'bank', 'amount' => 1000, 'shop_payment_method_id' => $foreignMethod->id],
            ],
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);

        $response->assertStatus(302);
        $response->assertSessionHasErrors(['payments.0.shop_payment_method_id']);

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
        });
    }

    public function test_payment_rows_never_touch_any_live_accounting_table(): void
    {
        [$owner] = $this->createRetailerTenant();

        $liveTables = ['invoice_payments', 'cash_transactions', 'customer_gold_transactions', 'loyalty_transactions'];
        $before = collect($liveTables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

        $payload = $this->paymentPayload([
            'intent' => 'draft',
            'payments' => [['mode' => 'cash', 'amount' => 1000]],
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        $after = collect($liveTables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $this->assertSame($before, $after, 'A historical payment row touched a live accounting table.');
    }

    public function test_a_custom_free_text_account_label_persists_without_a_payment_method_reference(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->paymentPayload([
            'intent' => 'draft',
            'payments' => [
                ['mode' => 'other', 'amount' => 1000, 'account_label_snapshot' => 'Barter — old scooter'],
            ],
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertRedirect();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->latest('id')->with('payments')->firstOrFail();
            $payment = $document->payments->first();

            $this->assertNull($payment->shop_payment_method_id);
            $this->assertFalse($payment->was_linked_to_payment_method);
            $this->assertSame('Barter — old scooter', $payment->account_label_snapshot);
        });
    }

    public function test_payment_date_and_note_are_persisted_when_provided(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->paymentPayload([
            'intent' => 'draft',
            'payments' => [
                ['mode' => 'cash', 'amount' => 1000, 'payment_date' => '2024-01-15', 'note' => 'Paid at counter'],
            ],
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertRedirect();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->latest('id')->with('payments')->firstOrFail();
            $payment = $document->payments->first();

            $this->assertSame('2024-01-15', $payment->payment_date->toDateString());
            $this->assertSame('Paid at counter', $payment->note);
        });
    }

    public function test_a_wholly_blank_payment_row_is_dropped_silently_not_treated_as_a_validation_error(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->paymentPayload([
            'intent' => 'draft',
            'payments' => [
                ['mode' => '', 'amount' => '', 'reference' => '', 'shop_payment_method_id' => '', 'payment_date' => '', 'note' => ''],
                ['mode' => 'cash', 'amount' => 250],
            ],
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->latest('id')->with('payments')->firstOrFail();

            $this->assertCount(1, $document->payments);
            $this->assertEqualsWithDelta(250.0, (float) $document->payments->first()->amount, 0.01);
        });
    }

    public function test_a_manually_overridden_paid_total_that_mismatches_the_payment_rows_requires_acknowledgement_before_publish(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->paymentPayload([
            'intent' => 'publish',
            'paid_amount_mode' => 'manual',
            'paid_amount' => 1000,
            'payments' => [['mode' => 'cash', 'amount' => 400]],
        ]);

        $preview = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $preview->assertOk();
        $digest = $preview->viewData('warningDigest');
        $this->assertNotNull(
            $digest,
            'A ₹600 mismatch between the payment rows and the manually overridden paid total must raise a warning.'
        );

        $refused = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $refused->assertSessionHas('error');

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
        });

        $accepted = $this->actingAs($owner)->post(route('historical.manual.store'), $payload + [
            'acknowledge_warnings' => '1',
            'acknowledged_warning_digest' => $digest,
        ]);
        $accepted->assertRedirect();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
            $this->assertEqualsWithDelta(1000.0, (float) $document->paid_amount_snapshot, 0.01);
            $this->assertEqualsWithDelta(0.0, (float) $document->outstanding_amount_snapshot, 0.01);
        });
    }

    /**
     * Requirements lock, "Overpayment": warning not error, never a silent clamp,
     * same acknowledgement gate before publish. The settlement math already
     * routed the excess to advance_credit_amount, but said nothing about it —
     * and an overpayment is more often a typo than a real advance.
     *
     * Payment rows only, no manual paid override, so the mismatch warning is not
     * in play and cannot mask this one.
     */
    public function test_an_overpayment_warns_and_requires_acknowledgement_before_publish(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->paymentPayload([
            'intent' => 'publish',
            'grand_total' => 1000,
            'payments' => [['mode' => 'cash', 'amount' => 1200]],
        ]);

        $preview = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $preview->assertOk();
        $digest = $preview->viewData('warningDigest');
        $this->assertNotNull($digest, 'Paying 1200 against a 1000 bill must raise a warning.');
        $preview->assertSee('recorded as an advance/credit', false);

        $refused = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $refused->assertSessionHas('error');

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count(), 'An unacknowledged overpayment must publish nothing.');
        });

        $accepted = $this->actingAs($owner)->post(route('historical.manual.store'), $payload + [
            'acknowledge_warnings' => '1',
            'acknowledged_warning_digest' => $digest,
        ]);
        $accepted->assertRedirect();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
            $this->assertEqualsWithDelta(1200.0, (float) $document->paid_amount_snapshot, 0.01);
            // Clamped, but not lost: the excess is carried, not silently dropped.
            $this->assertEqualsWithDelta(0.0, (float) $document->outstanding_amount_snapshot, 0.01);
            $this->assertEqualsWithDelta(200.0, (float) $document->advance_credit_amount, 0.01);
        });
    }
}
