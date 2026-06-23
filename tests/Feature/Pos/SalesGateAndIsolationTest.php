<?php

namespace Tests\Feature\Pos;

use App\Models\Invoice;
use App\Models\Shop;
use App\Models\User;
use App\Services\SalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * POS / Sales / Invoicing — gate ordering, tenant isolation & payment integrity
 * (Module 8). Complements PosSalesTest/InvoiceFlowTest (sale side-effects, item
 * sold, cashbook, cross-shop item already covered) with the untested surface:
 * the subscription-gate-before-edition ordering (carried Module-3 fix), cross-
 * shop customer rejection, cross-shop invoice show/print, and payment mismatch.
 */
class SalesGateAndIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    // ── Gate ordering (subscription gate is the outermost boundary) ────────

    public function test_active_retailer_hits_edition_check_only_after_gate_passes(): void
    {
        // Retailer (no manufacturer edition) with an ACTIVE subscription: the
        // subscription gate passes, THEN the edition check fires. Proves the
        // reorder didn't drop the edition guard — it just runs second.
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Manufacturer edition required');
        TenantContext::runFor($shop->id, fn () => SalesService::sellItem(1, 1, 7200, 0, 0));
    }

    public function test_expired_shop_is_blocked_by_subscription_gate_first(): void
    {
        // Regression for the carried fix: an expired shop must report the access
        // block (not a misleading "manufacturer edition required"), regardless of
        // edition — the gate now runs before the edition check.
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor($shop->id, fn () => $shop->subscription?->forceFill([
            'status' => 'expired',
            'ends_at' => now()->subDays(10)->toDateString(),
            'grace_ends_at' => now()->subDays(3)->toDateString(),
        ])->save());
        // Reflect the lapse on the shop access mode (what the gate reads).
        $shop->forceFill(['access_mode' => 'suspended', 'is_active' => false])->save();
        $this->actingAs($user->fresh());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('blocked');
        TenantContext::runFor($shop->id, fn () => SalesService::sellItem(1, 1, 7200, 0, 0));
    }

    /** A finalized invoice for $shop. Most amount columns aren't $fillable, so insert via forceFill. */
    private function finalizedInvoice(Shop $shop, string $number): Invoice
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $number) {
            $cust = $this->createCustomer($shop->id);
            $inv = new Invoice();
            $inv->forceFill([
                'shop_id' => $shop->id, 'customer_id' => $cust->id, 'invoice_number' => $number,
                'status' => Invoice::STATUS_FINALIZED, 'gold_rate' => 6000,
                'subtotal' => 100, 'gst' => 3, 'total' => 103,
            ])->save();

            return $inv;
        });
    }

    // ── Tenant isolation ───────────────────────────────────────────────────

    public function test_sale_rejects_a_customer_from_another_shop(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        [, $otherShop] = $this->createManufacturerTenant();
        $otherCustomer = $this->createCustomer($otherShop->id);

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $otherCustomer->id, // cross-shop customer
            'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 0, 'stone' => 0, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 1]],
        ]));

        $res->assertStatus(422);
        $res->assertJsonValidationErrors('customer_id');
    }

    public function test_sale_rejects_a_mismatched_payment_total(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        // Pay ₹1 against a real-priced sale → must be rejected, item NOT sold.
        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 0, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 1]],
        ]));

        $this->assertContains($res->getStatusCode(), [422, 500], 'mismatched payment must not succeed');
        $this->assertSame('in_stock', $item->fresh()->status, 'item must remain unsold on payment mismatch');
    }

    public function test_cannot_view_another_shops_invoice(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $invoiceB = $this->finalizedInvoice($shopB, 'INV-B-1');

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($userA)
            ->get(self::ERP . '/invoices/' . $invoiceB->id));

        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop invoice must not be viewable');
    }

    public function test_cannot_print_another_shops_invoice(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $invoiceB = $this->finalizedInvoice($shopB, 'INV-B-2');

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($userA)
            ->get(self::ERP . '/invoice/' . $invoiceB->id . '/print'));

        $this->assertContains($res->getStatusCode(), [403, 404]);
    }

    public function test_guest_cannot_reach_pos(): void
    {
        $res = $this->get(self::ERP . '/pos');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
