<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\StoreCreditMovement;
use App\Services\StoreCreditService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Store-credit redemption (spend) against a finalized invoice.
 *
 * Reproduces the staging P1: issuance works but redemption via
 * POST /invoices/{invoice}/store-credit/apply returns success yet neither the
 * invoice due nor the customer wallet balance changes.
 *
 * Correct accounting: redeeming credit is a wallet-liability drawdown, NOT cash:
 *   - a negative StoreCreditMovement (source=sale_applied) debits the wallet
 *   - a wallet-mode InvoicePayment reduces the invoice's outstanding balance
 *   - no CashTransaction is written
 *   - invoice total is untouched; over-application is impossible
 */
class StoreCreditRedemptionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** A finalized invoice with an outstanding balance (no payments yet). */
    private function finalizedInvoice(int $shopId, int $customerId, float $total): Invoice
    {
        return TenantContext::runFor($shopId, fn () => Invoice::issue([
            'shop_id'     => $shopId,
            'customer_id' => $customerId,
            'gold_rate'   => 7200,
            'subtotal'    => $total,
            'gst'         => 0,
            'total'       => $total,
            'status'      => Invoice::STATUS_FINALIZED,
        ]));
    }

    /** Seed wallet credit through the real StoreCreditMovement path (manual adjust). */
    private function seedCredit(int $shopId, int $customerId, int $ownerId, float $amount): void
    {
        $customer = Customer::withoutTenant()->findOrFail($customerId);
        TenantContext::runFor($shopId, fn () => app(StoreCreditService::class)
            ->manualAdjust($customer, $shopId, $amount, 'seed credit for test', $ownerId, $ownerId));
    }

    private function balance(int $shopId, int $customerId): float
    {
        return app(StoreCreditService::class)->balance($shopId, $customerId);
    }

    /**
     * POST to the redemption route as the given owner.
     *
     * Feature tests run in "console" mode, so BelongsToShop's global scope
     * fails closed during route-model binding (SubstituteBindings runs before
     * EnsureTenantUser) and every {invoice}-bound route 404s. Mirror what
     * EnsureTenantUser does on a real request — set the tenant to the acting
     * user's shop — so binding resolves and we exercise the real controller.
     */
    private function applyAs(\App\Models\User $actor, Invoice $invoice, $amount)
    {
        $this->actingAs($actor);
        TenantContext::set((int) $actor->shop_id);

        return $this->post(route('store-credit.apply', $invoice), ['amount' => $amount]);
    }

    private function paidOn(Invoice $invoice): float
    {
        return (float) InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)->sum('amount');
    }

    /** Core reproduction: credit < due partially pays the invoice. */
    public function test_applying_credit_reduces_due_and_wallet(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->seedCredit($shop->id, $customer->id, $owner->id, 3000);
        $invoice = $this->finalizedInvoice($shop->id, $customer->id, 10000);

        $response = $this->applyAs($owner, $invoice, 3000);

        $response->assertRedirect(route('invoices.show', $invoice));
        $response->assertSessionHas('success');

        // Invoice due dropped by 3000 (paid = 3000).
        $this->assertEqualsWithDelta(3000, $this->paidOn($invoice), 0.01, 'invoice paid must increase');

        // Wallet drained to zero.
        $this->assertEqualsWithDelta(0, $this->balance($shop->id, $customer->id), 0.01, 'wallet must decrease');

        // A negative redemption movement exists.
        $redeem = StoreCreditMovement::withoutTenant()->where('shop_id', $shop->id)
            ->where('source_type', StoreCreditMovement::SOURCE_SALE_APPLIED)->firstOrFail();
        $this->assertEqualsWithDelta(-3000, (float) $redeem->amount, 0.01);

        // A wallet-mode payment row exists.
        $wallet = InvoicePayment::withoutTenant()->where('invoice_id', $invoice->id)
            ->where('mode', InvoicePayment::MODE_WALLET)->firstOrFail();
        $this->assertEqualsWithDelta(3000, (float) $wallet->amount, 0.01);

        // No fake cash.
        $this->assertSame(0, CashTransaction::withoutTenant()->where('invoice_id', $invoice->id)->count());
    }

    /** Credit greater than due only applies up to the outstanding amount. */
    public function test_credit_over_due_only_applies_up_to_due(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->seedCredit($shop->id, $customer->id, $owner->id, 15000);
        $invoice = $this->finalizedInvoice($shop->id, $customer->id, 10000);

        // Attempt to over-apply: request full 15000 against a 10000 bill.
        $response = $this->applyAs($owner, $invoice, 15000);

        // Either rejected as a validation error, or clamped to due — never over-applied.
        $paid = $this->paidOn($invoice);
        $this->assertLessThanOrEqual(10000.0 + 0.01, $paid, 'must never pay more than the invoice total');
        $this->assertLessThanOrEqual(15000.0 + 0.01, 15000 - $this->balance($shop->id, $customer->id));
    }

    /** Zero available credit is rejected and nothing is written. */
    public function test_zero_balance_is_rejected(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $invoice = $this->finalizedInvoice($shop->id, $customer->id, 10000);

        $response = $this->applyAs($owner, $invoice, 1000);

        $response->assertSessionHasErrors();
        $this->assertEqualsWithDelta(0, $this->paidOn($invoice), 0.01);
    }

    /** Applying credit to another shop's invoice is forbidden (404). */
    public function test_cross_shop_invoice_is_forbidden(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $customerB = $this->createCustomer($shopB->id);
        $invoiceB = $this->finalizedInvoice($shopB->id, $customerB->id, 10000);

        // ownerA's tenant context can never resolve shopB's invoice.
        $response = $this->applyAs($ownerA, $invoiceB, 1000);
        $response->assertNotFound();
    }

    /** Fully paid invoice cannot receive more credit. */
    public function test_fully_paid_invoice_is_rejected(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->seedCredit($shop->id, $customer->id, $owner->id, 10000);
        $invoice = $this->finalizedInvoice($shop->id, $customer->id, 10000);

        // First application clears the bill.
        $this->applyAs($owner, $invoice, 10000);
        $this->assertEqualsWithDelta(10000, $this->paidOn($invoice), 0.01);

        // Second application must be rejected — no over-apply.
        $response = $this->applyAs($owner, $invoice, 1);
        $response->assertSessionHasErrors();
        $this->assertEqualsWithDelta(10000, $this->paidOn($invoice), 0.01);
    }
}
