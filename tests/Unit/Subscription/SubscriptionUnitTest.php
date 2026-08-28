<?php

namespace Tests\Unit\Subscription;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\User;
use App\Services\SubscriptionGateService;
use App\Services\SubscriptionPaymentService;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * ERP Subscription / Billing — unit level (Module 3). The payment-integrity
 * primitives (amount-in-paise verification, trial-family mapping, one-trial-per-
 * family eligibility) and the gate's fail-closed behaviour, in isolation.
 */
class SubscriptionUnitTest extends TestCase
{
    use RefreshDatabase;

    private function plan(array $over = []): Plan
    {
        return Plan::create(array_merge([
            'code' => 'retailer_yearly_' . random_int(1000, 9999),
            'name' => 'Retailer Yearly', 'price_monthly' => 1999, 'price_yearly' => 19999,
            'grace_days' => 5, 'is_active' => true,
        ], $over));
    }

    private function rzpOrder(int $amountPaise, string $id = 'order_test'): object
    {
        return (object) ['id' => $id, 'amount' => $amountPaise];
    }

    // ── verifyAmount: forged/short amount is rejected ──────────────────────

    public function test_verify_amount_accepts_the_exact_plan_price_in_paise(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $plan = $this->plan(['price_yearly' => 19999]);

        $paise = $svc->verifyAmount($this->rzpOrder(1999900), $plan, 'yearly');
        $this->assertSame(1999900, $paise, '₹19,999 = 19,99,900 paise');
    }

    public function test_verify_amount_uses_monthly_price_for_monthly_cycle(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $plan = $this->plan(['price_monthly' => 1999]);

        $this->assertSame(199900, $svc->verifyAmount($this->rzpOrder(199900), $plan, 'monthly'));
    }

    public function test_verify_amount_rejects_a_short_paid_amount(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $plan = $this->plan(['price_yearly' => 19999]);

        // Attacker forges the order to ₹1 (100 paise) for a ₹19,999 plan.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Payment amount mismatch');
        $svc->verifyAmount($this->rzpOrder(100), $plan, 'yearly');
    }

    public function test_verify_amount_rejects_a_one_paise_under_amount(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $plan = $this->plan(['price_yearly' => 19999]);

        $this->expectException(\Exception::class);
        $svc->verifyAmount($this->rzpOrder(1999899), $plan, 'yearly'); // 1 paise short
    }

    // ── trial family mapping (ERP vs Dhiran separation) ────────────────────

    public function test_trial_family_groups_retail_and_manufacturer_as_erp(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $this->assertSame('erp', $svc->trialFamilyFor(ShopEdition::RETAILER));
        $this->assertSame('erp', $svc->trialFamilyFor(ShopEdition::MANUFACTURER));
    }

    public function test_trial_family_keeps_dhiran_separate(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        // Dhiran is its own family — an ERP trial must not consume a Dhiran trial.
        $this->assertNotSame('erp', $svc->trialFamilyFor(ShopEdition::DHIRAN));
        $this->assertSame(ShopEdition::DHIRAN, $svc->trialFamilyFor(ShopEdition::DHIRAN));
    }

    // ── hasUsedTrialForFamily eligibility ──────────────────────────────────

    public function test_has_used_trial_is_false_for_a_fresh_identity(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $this->assertFalse($svc->hasUsedTrialForFamily(null, ShopEdition::RETAILER, null),
            'no shop + no user → matches nothing (fail-safe), not everything');
    }

    public function test_has_used_trial_detects_a_prior_user_scoped_trial(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $user = User::create(['mobile_number' => '9300000401', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);
        $plan = $this->plan();

        // A prior FREE trial (price_paid=0, no razorpay id) for this user.
        ShopSubscription::create([
            'shop_id' => null, 'plan_id' => $plan->id, 'user_id' => $user->id,
            'status' => 'trial', 'billing_cycle' => 'yearly', 'price_paid' => 0,
            'starts_at' => now(), 'ends_at' => now()->addDays(14),
        ]);

        // Same ERP family → already used.
        $this->assertTrue($svc->hasUsedTrialForFamily(null, ShopEdition::RETAILER, $user->id));
        // Manufacturer shares the ERP family → also used.
        $this->assertTrue($svc->hasUsedTrialForFamily(null, ShopEdition::MANUFACTURER, $user->id));
    }

    /**
     * RETARGETED. hasUsedTrialForFamily() answers ONE narrow question: "has this
     * identity ever held a FREE row for this family?" It is the predicate the
     * concurrent-trial race recovery and the partial unique index are both keyed
     * on (price_paid = 0 AND razorpay_payment_id IS NULL), so it must keep
     * saying FALSE for a paid subscription — otherwise the unique-index recovery
     * path would start looking for rows the index never covered.
     *
     * It is NOT, and must not become, the automatic-trial ELIGIBILITY question.
     * That broader question ("has this identity ever held ANY entitlement for
     * this family, free or paid?") is hasPriorEntitlementForFamily(), asserted
     * in the next test. Conflating the two was the original defect: a lapsed
     * paying customer looked like a brand-new one and got a free month.
     */
    public function test_a_paid_subscription_does_not_count_as_a_used_trial(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $user = User::create(['mobile_number' => '9300000402', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);
        $plan = $this->plan();

        // A PAID subscription (price_paid>0 + razorpay id) is not a trial.
        ShopSubscription::create([
            'shop_id' => null, 'plan_id' => $plan->id, 'user_id' => $user->id,
            'status' => 'active', 'billing_cycle' => 'yearly', 'price_paid' => 19999,
            'razorpay_payment_id' => 'pay_xxx', 'starts_at' => now(), 'ends_at' => now()->addYear(),
        ]);

        $this->assertFalse(
            $svc->hasUsedTrialForFamily(null, ShopEdition::RETAILER, $user->id),
            'narrow meaning preserved: a paid row is not a used TRIAL'
        );
    }

    /**
     * The ELIGIBILITY predicate — the broader one. ANY prior entitlement for the
     * family, free or paid, live or long dead, ends the automatic free trial.
     * Status is deliberately not consulted: a cancelled or expired row is still
     * history, and history is exactly what "new customer" is supposed to mean.
     */
    public function test_prior_paid_entitlement_disqualifies_the_automatic_trial(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $user = User::create(['mobile_number' => '9300000403', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);
        $plan = $this->plan();

        $this->assertFalse(
            $svc->hasPriorEntitlementForFamily(null, ShopEdition::RETAILER, $user->id),
            'a user with no history at all is eligible'
        );

        // A long-dead PAID term. No free row exists anywhere for this identity,
        // so a trial-only predicate sees a virgin account.
        ShopSubscription::create([
            'shop_id' => null, 'plan_id' => $plan->id, 'user_id' => $user->id,
            'status' => 'expired', 'billing_cycle' => 'yearly', 'price_paid' => 19999,
            'razorpay_payment_id' => 'pay_yyy', 'starts_at' => now()->subYears(2), 'ends_at' => now()->subYear(),
        ]);

        $this->assertFalse(
            $svc->hasUsedTrialForFamily(null, ShopEdition::RETAILER, $user->id),
            'still not a used trial — the two predicates must stay distinct'
        );
        $this->assertTrue(
            $svc->hasPriorEntitlementForFamily(null, ShopEdition::RETAILER, $user->id),
            'but it IS prior entitlement, so the automatic trial is gone'
        );
        $this->assertTrue(
            $svc->hasPriorEntitlementForFamily(null, ShopEdition::MANUFACTURER, $user->id),
            'retailer and manufacturer share the erp family'
        );
        $this->assertFalse(
            $svc->hasPriorEntitlementForFamily(null, 'dhiran', $user->id),
            'a different family keeps its own trial'
        );
    }

    /** Same fail-safe as the trial predicate: no scope → match nothing, never all. */
    public function test_prior_entitlement_with_no_shop_and_no_user_matches_nothing(): void
    {
        $svc = app(SubscriptionPaymentService::class);
        $user = User::create(['mobile_number' => '9300000404', 'password' => bcrypt('x'), 'realm' => 'erp', 'is_active' => true]);
        $plan = $this->plan();
        ShopSubscription::create([
            'shop_id' => null, 'plan_id' => $plan->id, 'user_id' => $user->id,
            'status' => 'active', 'billing_cycle' => 'yearly', 'price_paid' => 19999,
            'razorpay_payment_id' => 'pay_zzz', 'starts_at' => now(), 'ends_at' => now()->addYear(),
        ]);

        $this->assertFalse(
            $svc->hasPriorEntitlementForFamily(null, ShopEdition::RETAILER, null),
            'no shop + no user → matches nothing (fail-safe), not everything'
        );
    }

    // ── gate fail-closed ───────────────────────────────────────────────────

    public function test_gate_fails_closed_when_the_shop_does_not_exist(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Shop not found');
        SubscriptionGateService::assertShopWritable(999999);
    }
}
