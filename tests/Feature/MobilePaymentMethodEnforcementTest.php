<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\ShopPaymentMethod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class MobilePaymentMethodEnforcementTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_mobile_pos_requires_payment_method_id_for_upi_bank_and_wallet_modes(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        Sanctum::actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $lot = $this->createMetalLot($shop->id);
        $item = $this->createItem($shop->id, $lot->id, [
            'barcode' => 'MOB-POS-REQ-001',
        ]);

        $total = $this->mobileManufacturerPosTotal($customer->id, $item->id);

        foreach (['upi', 'bank', 'wallet'] as $mode) {
            $response = $this->postJson('/api/mobile/pos/sell', [
                'customer_id' => $customer->id,
                'item_id' => $item->id,
                'gold_rate' => 6000,
                'making' => 500,
                'stone' => 200,
                'payments' => [
                    ['mode' => $mode, 'amount' => $total],
                ],
            ]);

            $response->assertStatus(422);
            $response->assertJsonValidationErrors(['payments.0.payment_method_id']);
            $this->assertSame(
                "Payment method is required for mode \"{$mode}\".",
                $response->json('errors')['payments.0.payment_method_id'][0]
            );
        }
    }

    public function test_mobile_pos_rejects_mismatched_payment_method_type(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        Sanctum::actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $lot = $this->createMetalLot($shop->id);
        $item = $this->createItem($shop->id, $lot->id, [
            'barcode' => 'MOB-POS-MISMATCH-001',
        ]);
        $bankMethod = $this->createShopPaymentMethod($shop->id, ShopPaymentMethod::TYPE_BANK);

        $total = $this->mobileManufacturerPosTotal($customer->id, $item->id);

        $response = $this->postJson('/api/mobile/pos/sell', [
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
            'payments' => [
                [
                    'mode' => 'upi',
                    'amount' => $total,
                    'payment_method_id' => $bankMethod->id,
                ],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.0.payment_method_id']);
        $this->assertSame(
            'Payment method type must match mode "upi".',
            $response->json('errors')['payments.0.payment_method_id'][0]
        );
    }

    public function test_mobile_pos_accepts_correctly_mapped_payment_method(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        Sanctum::actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $lot = $this->createMetalLot($shop->id);
        $item = $this->createItem($shop->id, $lot->id, [
            'barcode' => 'MOB-POS-OK-001',
        ]);
        $upiMethod = $this->createShopPaymentMethod($shop->id, ShopPaymentMethod::TYPE_UPI);

        $total = $this->mobileManufacturerPosTotal($customer->id, $item->id);

        $response = $this->postJson('/api/mobile/pos/sell', [
            'customer_id' => $customer->id,
            'item_id' => $item->id,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
            'payments' => [
                [
                    'mode' => 'upi',
                    'amount' => $total,
                    'payment_method_id' => $upiMethod->id,
                    'reference' => 'UPI-OK-001',
                ],
            ],
        ]);

        $response->assertOk();
        $invoicePayment = InvoicePayment::withoutTenant()
            ->where('invoice_id', $response->json('invoice_id'))
            ->firstOrFail();

        $this->assertSame('upi', $invoicePayment->mode);
        $this->assertSame($upiMethod->id, (int) $invoicePayment->payment_method_id);
    }

    public function test_mobile_invoice_requires_payment_method_id_for_upi_and_bank_modes(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        Sanctum::actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $invoice = $this->createFinalizedInvoice($shop->id, $customer->id, 2500);

        $singlePayloadResponse = $this->postJson("/api/mobile/invoices/{$invoice->id}/payments", [
            'mode' => 'upi',
            'amount' => 500,
        ]);

        $singlePayloadResponse->assertStatus(422);
        $singlePayloadResponse->assertJsonValidationErrors(['payments.0.payment_method_id']);
        $this->assertSame(
            'Payment method is required for mode "upi".',
            $singlePayloadResponse->json('errors')['payments.0.payment_method_id'][0]
        );

        $paymentsArrayResponse = $this->postJson("/api/mobile/invoices/{$invoice->id}/payments", [
            'payments' => [
                ['mode' => 'bank', 'amount' => 500],
            ],
        ]);

        $paymentsArrayResponse->assertStatus(422);
        $paymentsArrayResponse->assertJsonValidationErrors(['payments.0.payment_method_id']);
        $this->assertSame(
            'Payment method is required for mode "bank".',
            $paymentsArrayResponse->json('errors')['payments.0.payment_method_id'][0]
        );
    }

    public function test_mobile_invoice_accepts_valid_payment_method_and_keeps_response_shape(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        Sanctum::actingAs($user);

        $customer = $this->createCustomer($shop->id);
        $invoice = $this->createFinalizedInvoice($shop->id, $customer->id, 2500);
        $upiMethod = $this->createShopPaymentMethod($shop->id, ShopPaymentMethod::TYPE_UPI);

        $response = $this->postJson("/api/mobile/invoices/{$invoice->id}/payments", [
            'mode' => 'upi',
            'amount' => 500,
            'reference' => 'INV-UPI-001',
            'payment_method_id' => $upiMethod->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('payment.payment_method_id', $upiMethod->id);
        $response->assertJsonPath('payments.0.payment_method_id', $upiMethod->id);
        $response->assertJsonPath('payment.payment_method_label', $upiMethod->account_label);
        $response->assertJsonPath('payments.0.payment_method_label', $upiMethod->account_label);
    }

    public function test_mobile_quick_bill_requires_payment_method_id_for_wallet(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/mobile/quick-bills', $this->quickBillPayload([
            'payments' => [
                [
                    'payment_mode' => 'wallet',
                    'amount' => 500,
                    'reference_no' => 'WALLET-MISS-001',
                ],
            ],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['payments.0.payment_method_id']);
        $this->assertSame(
            'Payment method is required for mode "wallet".',
            $response->json('errors')['payments.0.payment_method_id'][0]
        );
    }

    public function test_mobile_quick_bill_accepts_valid_wallet_method_and_returns_method_metadata(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        Sanctum::actingAs($user);

        $walletMethod = $this->createShopPaymentMethod($shop->id, ShopPaymentMethod::TYPE_WALLET);

        $response = $this->postJson('/api/mobile/quick-bills', $this->quickBillPayload([
            'payments' => [
                [
                    'payment_mode' => 'wallet',
                    'payment_method_id' => $walletMethod->id,
                    'amount' => 500,
                    'reference_no' => 'WALLET-OK-001',
                ],
            ],
        ]));

        $response->assertCreated();
        $response->assertJsonPath('quick_bill.payments.0.payment_method_id', $walletMethod->id);
        $response->assertJsonPath('quick_bill.payments.0.payment_method_label', $walletMethod->account_label);
    }

    private function mobileManufacturerPosTotal(int $customerId, int $itemId): float
    {
        $response = $this->postJson('/api/mobile/pos/preview', [
            'customer_id' => $customerId,
            'item_id' => $itemId,
            'gold_rate' => 6000,
            'making' => 500,
            'stone' => 200,
            'discount' => 0,
            'round_off' => 0,
        ]);

        $response->assertOk();

        return (float) $response->json('total');
    }

    private function createShopPaymentMethod(int $shopId, string $type): ShopPaymentMethod
    {
        $method = new ShopPaymentMethod();
        $method->forceFill([
            'shop_id' => $shopId,
            'type' => $type,
            'name' => strtoupper($type) . ' Method',
            'upi_id' => $type === ShopPaymentMethod::TYPE_UPI ? 'upi-method@test' : null,
            'bank_name' => $type === ShopPaymentMethod::TYPE_BANK ? 'Test Bank' : null,
            'account_holder' => $type === ShopPaymentMethod::TYPE_BANK ? 'Test Holder' : null,
            'account_number' => $type === ShopPaymentMethod::TYPE_BANK ? '1234567890' : null,
            'ifsc_code' => $type === ShopPaymentMethod::TYPE_BANK ? 'TEST0001234' : null,
            'account_type' => $type === ShopPaymentMethod::TYPE_BANK ? 'savings' : null,
            'branch' => $type === ShopPaymentMethod::TYPE_BANK ? 'Main Branch' : null,
            'wallet_id' => $type === ShopPaymentMethod::TYPE_WALLET ? 'wallet-method@test' : null,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $method->save();

        return $method;
    }

    private function createFinalizedInvoice(int $shopId, int $customerId, float $total): Invoice
    {
        return TenantContext::runFor($shopId, fn () => Invoice::issue([
            'shop_id' => $shopId,
            'customer_id' => $customerId,
            'status' => Invoice::STATUS_FINALIZED,
            'gold_rate' => 0,
            'subtotal' => $total,
            'gst' => 0,
            'gst_rate' => 3,
            'wastage_charge' => 0,
            'discount' => 0,
            'round_off' => 0,
            'total' => $total,
            'finalized_at' => now(),
        ]));
    }

    private function quickBillPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'no_gst',
            'gst_rate' => 0,
            'round_off' => 0,
            'save_action' => 'draft',
            'items' => [
                [
                    'description' => 'Quick Bill Item',
                    'pcs' => 1,
                    'gross_weight' => 1,
                    'stone_weight' => 0,
                    'net_weight' => 1,
                    'rate' => 1000,
                    'making_charge' => 0,
                    'stone_charge' => 0,
                    'wastage_percent' => 0,
                    'line_discount' => 0,
                ],
            ],
            'payments' => [],
        ], $overrides);
    }
}
