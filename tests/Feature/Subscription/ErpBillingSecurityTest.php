<?php

namespace Tests\Feature\Subscription;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Support\ShopEdition;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * ERP Subscription / Billing — security & access (Module 3). Closes the gaps the
 * existing flow/lifecycle suites leave open: cross-shop billing isolation, the
 * plan-choice forge surface, and the no-flow-no-access guard. The payment
 * callback's signature/amount/capture/duplicate chain is already covered by
 * SubscriptionFlowTest + SubscriptionLifecycleTest and is not re-asserted here.
 */
class ErpBillingSecurityTest extends TestCase
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

    private function invoiceFor(int $shopId): PlatformInvoice
    {
        // The shop's active subscription (created by the tenant factory) backs the invoice.
        $sub = ShopSubscription::where('shop_id', $shopId)->latest('id')->first();

        return TenantContext::runFor($shopId, fn () => PlatformInvoice::create([
            'shop_id' => $shopId,
            'shop_subscription_id' => $sub?->id,
            'plan_id' => $sub?->plan_id,
            'invoice_number' => 'PINV-' . random_int(100000, 999999),
            'invoice_sequence' => random_int(1, 99999),
            'billing_cycle' => 'yearly',
            'billing_period_start' => now()->toDateString(),
            'billing_period_end' => now()->addYear()->toDateString(),
            'amount_before_tax' => 16949.15,
            'gst_rate' => 18.0,
            'gst_amount' => 3049.85,
            'total_amount' => 19999.00,
            'status' => 'paid',
            'issued_at' => now(),
        ]));
    }

    // ── Cross-shop billing isolation ───────────────────────────────────────

    public function test_owner_cannot_view_another_shops_platform_invoice(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $invoiceB = $this->invoiceFor($shopB->id);

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/billing/' . $invoiceB->id));

        $res->assertForbidden(); // BillingController::show enforces shop_id === auth shop
    }

    public function test_owner_can_view_own_platform_invoice(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $invoiceA = $this->invoiceFor($shopA->id);

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/billing/' . $invoiceA->id));

        $res->assertOk();
    }

    // ── Plan-choice forge surface ──────────────────────────────────────────
    // Uses a fresh onboarding user (NO live paid subscription) — a shop that
    // already has an active sub is redirected out of choosePlan before
    // validation, so the forge surface only exists pre-subscription.

    private function onboardingUser(string $mobile): \App\Models\User
    {
        return \App\Models\User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'),
            'realm' => 'erp', 'is_active' => true,
        ]);
    }

    public function test_choose_plan_rejects_a_nonexistent_plan_id(): void
    {
        $user = $this->onboardingUser('9300000501');

        $res = $this->actingAs($user)
            ->post(self::ERP . '/subscription/choose', ['plan_id' => 999999, 'billing_cycle' => 'yearly']);

        $res->assertSessionHasErrors('plan_id');
    }

    public function test_choose_plan_rejects_an_inactive_plan(): void
    {
        $user = $this->onboardingUser('9300000502');
        $inactive = Plan::create([
            'code' => 'dead_plan', 'name' => 'Dead', 'price_monthly' => 1, 'price_yearly' => 1,
            'grace_days' => 0, 'is_active' => false,
        ]);

        $res = $this->actingAs($user)
            ->post(self::ERP . '/subscription/choose', ['plan_id' => $inactive->id, 'billing_cycle' => 'yearly']);

        $res->assertSessionHasErrors('plan_id'); // exists-where-active rule blocks it
    }

    // ── No-flow → no access (payment page requires a chosen plan) ──────────

    public function test_payment_page_requires_a_selected_plan(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Hitting payment with no pending plan in session must not proceed.
        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->withSession([]) // no pending_plan_id
            ->get(self::ERP . '/subscription/payment'));

        // No chosen plan → bounced out of the payment step (to plans or status),
        // never allowed to proceed to a checkout for an unselected plan.
        $res->assertRedirect();
        $loc = (string) $res->headers->get('Location');
        $this->assertTrue(
            str_contains($loc, 'plans') || str_contains($loc, 'subscription'),
            "expected a bounce to plan selection / subscription status, got: {$loc}"
        );
    }

    // ── Dhiran isolation: ERP billing must not grant Dhiran ────────────────

    public function test_active_erp_subscription_does_not_grant_dhiran_edition(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->assertTrue($shop->fresh()->hasEdition(ShopEdition::RETAILER));
        $this->assertFalse($shop->fresh()->hasEdition(ShopEdition::DHIRAN),
            'an ERP (retailer) subscription must never grant the Dhiran edition');
    }

    // ── Guest cannot reach billing/subscription ────────────────────────────

    public function test_guest_cannot_reach_billing(): void
    {
        $res = $this->get(self::ERP . '/billing');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
