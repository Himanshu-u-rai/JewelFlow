<?php

namespace Tests\Feature\CashBook;

use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 1 of the Cash Book completion plan: every money-bearing cash_transactions
 * row must carry a normalized payment_mode, split tenders must produce one row per
 * mode, and old-metal tenders must never produce a cash row.
 */
class CashTransactionModeTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** A single cash sale records one cash row tagged payment_mode=cash. */
    public function test_cash_sale_records_cash_mode(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $total = $this->previewTotal($user, $item, $customer);

        $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id'     => $item->id,
            'gold_rate'   => 6000,
            'making'      => 500,
            'stone'       => 200,
            'discount'    => 0,
            'round_off'   => 0,
            'payments'    => [['mode' => 'cash', 'amount' => $total]],
        ])->assertOk();

        TenantContext::set($shop->id);
        $rows = CashTransaction::where('shop_id', $shop->id)->where('type', 'in')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('cash', $rows->first()->payment_mode);
        $this->assertEqualsWithDelta($total, (float) $rows->first()->amount, 0.01);
    }

    /** A split cash+UPI sale records TWO cash rows, one per mode, summing to the total. */
    public function test_split_tender_records_one_row_per_mode(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $total = $this->previewTotal($user, $item, $customer);
        $cash = round($total * 0.4, 2);
        $upi  = round($total - $cash, 2);

        $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id'     => $item->id,
            'gold_rate'   => 6000,
            'making'      => 500,
            'stone'       => 200,
            'discount'    => 0,
            'round_off'   => 0,
            'payments'    => [
                ['mode' => 'cash', 'amount' => $cash],
                ['mode' => 'upi',  'amount' => $upi],
            ],
        ])->assertOk();

        TenantContext::set($shop->id);
        $rows = CashTransaction::where('shop_id', $shop->id)->where('type', 'in')->get();

        $this->assertCount(2, $rows, 'split tender must create one cash row per mode');
        $byMode = $rows->keyBy('payment_mode');
        $this->assertArrayHasKey('cash', $byMode->all());
        $this->assertArrayHasKey('upi', $byMode->all());
        $this->assertEqualsWithDelta($cash, (float) $byMode['cash']->amount, 0.01);
        $this->assertEqualsWithDelta($upi, (float) $byMode['upi']->amount, 0.01);
        // Every money row has a mode — none null.
        $this->assertSame(0, $rows->whereNull('payment_mode')->count());
    }

    /** Old-gold tender must NOT create any cash row (it is metal, not money). */
    public function test_old_gold_tender_creates_no_cash_row(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $total = $this->previewTotal($user, $item, $customer);
        // Pay part cash, part old_gold; only the cash part should hit the cash book.
        $cash = round($total * 0.5, 2);
        $metalValue = round($total - $cash, 2);

        $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id'     => $item->id,
            'gold_rate'   => 6000,
            'making'      => 500,
            'stone'       => 200,
            'discount'    => 0,
            'round_off'   => 0,
            'payments'    => [
                ['mode' => 'cash', 'amount' => $cash],
                [
                    'mode'                => 'old_gold',
                    'amount'              => $metalValue,
                    'metal_gross_weight'  => 10,
                    'metal_purity'        => 22,
                    'metal_test_loss'     => 0,
                    'metal_rate_per_gram' => $metalValue / (10 * (22 / 24)),
                ],
            ],
        ])->assertOk();

        TenantContext::set($shop->id);
        $rows = CashTransaction::where('shop_id', $shop->id)->where('type', 'in')->get();

        $this->assertCount(1, $rows, 'only the cash portion should create a cash row');
        $this->assertSame('cash', $rows->first()->payment_mode);
        $this->assertSame(0, $rows->whereNull('payment_mode')->count());
        $this->assertSame(0, $rows->where('payment_mode', 'old_gold')->count());
    }

    /** A cash refund (full return settled to cash) records an out row tagged payment_mode=cash. */
    public function test_cash_refund_records_cash_mode(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $total = $this->previewTotal($user, $item, $customer);

        $sell = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id,
            'item_id'     => $item->id,
            'gold_rate'   => 6000,
            'making'      => 500,
            'stone'       => 200,
            'discount'    => 0,
            'round_off'   => 0,
            'payments'    => [['mode' => 'cash', 'amount' => $total]],
        ])->assertOk();

        TenantContext::set($shop->id);
        $invoice = Invoice::find($sell->json('invoice_id'));

        // Drive the service directly: createFullReturn fires the cash refund
        // immediately (no approval gate), settled to cash.
        $this->actingAs($user);
        app(\App\Services\Returns\ReturnService::class)->createFullReturn(
            $invoice,
            'Customer returned item for testing',
            $user->id,
            true, // skipPolicy
            \App\Services\Returns\ReturnService::REFUND_SETTLEMENT_CASH,
        );

        TenantContext::set($shop->id);
        $refundRows = CashTransaction::where('shop_id', $shop->id)
            ->where('type', 'out')
            ->where('source_type', 'credit_note')
            ->get();

        $this->assertGreaterThanOrEqual(1, $refundRows->count(), 'a cash refund row should exist');
        $this->assertSame(0, $refundRows->whereNull('payment_mode')->count(), 'refund cash rows must have a mode');
        $this->assertSame($refundRows->count(), $refundRows->where('payment_mode', 'cash')->count());
    }

    /** A manual cash-book entry records the chosen payment mode. */
    public function test_manual_entry_records_selected_mode(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post('/cashbook', [
            'type'         => 'out',
            'amount'       => 1500,
            'source_type'  => 'rent',
            'payment_mode' => 'bank',
            'description'  => 'Shop rent via bank',
        ]);

        TenantContext::set($shop->id);
        $row = CashTransaction::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('bank', $row->payment_mode);
    }

    /** A manual entry with no mode defaults to cash (never null). */
    public function test_manual_entry_defaults_to_cash(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post('/cashbook', [
            'type'        => 'in',
            'amount'      => 500,
            'source_type' => 'other_income',
        ]);

        TenantContext::set($shop->id);
        $row = CashTransaction::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('cash', $row->payment_mode);
    }

    /** A known money-OUT reason cannot be saved against a money-IN entry. */
    public function test_mismatched_direction_reason_is_rejected(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post('/cashbook', [
            'type'        => 'in',
            'amount'      => 1000,
            'source_type' => 'karigar_payment', // an OUT reason
        ])->assertSessionHasErrors('source_type');

        TenantContext::set($shop->id);
        $this->assertSame(0, CashTransaction::where('shop_id', $shop->id)->count(), 'No row should be written on a mismatch.');
    }

    /** A custom free-text reason is allowed for either direction. */
    public function test_custom_reason_allowed_for_either_direction(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post('/cashbook', [
            'type'        => 'in',
            'amount'      => 250,
            'source_type' => 'Tourist tip jar', // not in either known list
        ])->assertSessionHasNoErrors();

        TenantContext::set($shop->id);
        $this->assertSame(1, CashTransaction::where('shop_id', $shop->id)->count());
    }

    /** Model helper: known reasons are direction-locked, custom passes. */
    public function test_reason_matches_type_helper(): void
    {
        $this->assertTrue(CashTransaction::reasonMatchesType('in', 'customer_payment'));
        $this->assertFalse(CashTransaction::reasonMatchesType('in', 'karigar_payment'));
        $this->assertTrue(CashTransaction::reasonMatchesType('out', 'rent'));
        $this->assertFalse(CashTransaction::reasonMatchesType('out', 'customer_payment'));
        $this->assertTrue(CashTransaction::reasonMatchesType('in', 'anything_custom'));
        $this->assertTrue(CashTransaction::reasonMatchesType('out', 'anything_custom'));
    }

    private function previewTotal($user, $item, $customer): float
    {
        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id'     => $item->id,
            'customer_id' => $customer->id,
            'gold_rate'   => 6000,
            'making'      => 500,
            'stone'       => 200,
            'discount'    => 0,
            'round_off'   => 0,
        ]);
        $preview->assertOk();

        return (float) $preview->json('total');
    }
}
