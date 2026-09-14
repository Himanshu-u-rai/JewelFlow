<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Who is recorded as the actor when a SHOP OWNER acts on their own subscription?
 * Nobody at JewelFlows — and that has to be recorded honestly, because
 * `updated_by_admin_id` is read by humans deciding whether the platform did
 * something to a tenant.
 *
 * The three self-service paths used to name an administrator who had not acted:
 * `createSubscription()` and `startTrial()` both stamped systemAdmin() (the
 * lowest-id super_admin), and `renewSubscription()` copied whatever stamp the
 * previous term happened to carry. Every row so written contradicted its own
 * `actor_type = 'self_service'`.
 *
 * The 2026-08-15 migration made both columns nullable for exactly this case, and
 * every other lifecycle writer — SubscriptionWebhookService, CheckSubscriptionExpiry,
 * RepairTrialTermSubscriptions — already passes null. These paths were the holdouts.
 *
 * The second half of the defect is availability, and it is the reason two of
 * these tests deliberately assert against an EMPTY platform_admins table: both
 * paths refused to proceed at all ("Platform configuration incomplete.") when no
 * super_admin row existed, failing a customer's purchase for a reason that has
 * nothing to do with their purchase.
 */
class SelfServiceAttributionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // Invoice mail + ops alerts are queued jobs; neither is under test here.
        Bus::fake();
        Mail::fake();
        $this->seed(\Database\Seeders\PlatformProductSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    /** A shop with an owner, and no subscription history of any kind. */
    private function ownerOfFreshShop(): User
    {
        $shop = Shop::create([
            'name' => 'Retailer Shop',
            'shop_type' => 'retailer',
            'phone' => fake()->unique()->numerify('9########'),
            'owner_first_name' => 'A',
            'owner_last_name' => 'B',
            'owner_mobile' => fake()->unique()->numerify('9########'),
            'is_active' => true,
            'access_mode' => 'active',
        ]);

        return User::create([
            'name' => 'Owner',
            'mobile_number' => fake()->unique()->numerify('9########'),
            'shop_id' => $shop->id,
            'role_id' => $this->createOwnerRole($shop->id)->id,
            'password' => bcrypt('x'),
            'is_active' => true,
        ]);
    }

    private function plan(string $code = 'retailer_yearly'): Plan
    {
        return Plan::where('code', $code)->firstOrFail();
    }

    private function service(): SubscriptionPaymentService
    {
        return app(SubscriptionPaymentService::class);
    }

    // ── the owner's own trial ────────────────────────────────────────────────

    public function test_an_owner_started_trial_names_no_administrator(): void
    {
        $owner = $this->ownerOfFreshShop();
        $this->actingAs($owner);

        $this->assertSame(0, PlatformAdmin::count(), 'no administrator exists, and none is needed');

        $subscription = $this->service()->startTrial($this->plan());

        $this->assertNull($subscription->updated_by_admin_id,
            'an owner starting their own trial is not an administrator action');
        $this->assertSame('self_service', $subscription->actor_type);

        $event = SubscriptionEvent::where('shop_subscription_id', $subscription->id)
            ->where('event_type', 'subscription.trial_started')
            ->firstOrFail();

        $this->assertNull($event->admin_id, 'the audit event must not name an admin either');
    }

    // ── the owner's own purchase ─────────────────────────────────────────────

    public function test_a_self_service_purchase_names_no_administrator(): void
    {
        $owner = $this->ownerOfFreshShop();
        $plan = $this->plan();

        $this->assertSame(0, PlatformAdmin::count());

        $subscription = $this->service()->createSubscription(
            $plan,
            'yearly',
            (float) $plan->price_yearly,
            'pay_selfservice_1',
            'order_selfservice_1',
            $owner,
        );

        $this->assertNull($subscription->updated_by_admin_id);
        $this->assertSame('self_service', $subscription->actor_type);

        $event = SubscriptionEvent::where('shop_subscription_id', $subscription->id)
            ->where('event_type', 'subscription.paid')
            ->firstOrFail();

        $this->assertNull($event->admin_id);
    }

    /**
     * The availability half of the defect, stated on its own: a customer's
     * payment must not fail because of platform bookkeeping they cannot see.
     */
    public function test_a_purchase_succeeds_on_a_platform_with_no_super_admin(): void
    {
        $owner = $this->ownerOfFreshShop();
        $plan = $this->plan();

        PlatformAdmin::query()->delete();

        $subscription = $this->service()->createSubscription(
            $plan, 'yearly', (float) $plan->price_yearly, 'pay_noadmin', 'order_noadmin', $owner,
        );

        $this->assertSame('active', $subscription->status);
        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_noadmin')->count());
    }

    // ── renewal must not inherit a stamp ─────────────────────────────────────

    /**
     * The subtlest of the three. If the previous term was keyed in by an admin —
     * a manual renewal, a win-back — copying that stamp forward made the admin
     * the recorded actor on a card payment the customer made themselves, months
     * later. The renewal's own SubscriptionEvent always (correctly) said null.
     */
    public function test_a_renewal_does_not_inherit_the_previous_terms_admin_stamp(): void
    {
        $owner = $this->ownerOfFreshShop();
        $plan = $this->plan();
        $admin = $this->createPlatformAdmin();

        $current = ShopSubscription::create([
            'shop_id' => $owner->shop_id,
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subYear(),
            'ends_at' => now()->addDays(5),
            'grace_ends_at' => now()->addDays(10),
            'billing_cycle' => 'yearly',
            'price_paid' => $plan->price_yearly,
            'razorpay_payment_id' => 'pay_prior',
            'razorpay_order_id' => 'order_prior',
            'updated_by_admin_id' => $admin->id,
            'actor_type' => 'admin',
        ]);

        $renewed = $this->service()->renewSubscription(
            $current, 'yearly', (float) $plan->price_yearly, 'pay_renewal', 'order_renewal',
        );

        $this->assertNotSame($current->id, $renewed->id, 'renewal creates a new term row');
        $this->assertNull($renewed->updated_by_admin_id,
            "the customer renewed their own term; the previous term's admin did not");
        $this->assertSame('self_service', $renewed->actor_type);

        // The row the admin really did write is untouched — history stays honest
        // in both directions.
        $this->assertSame($admin->id, $current->fresh()->updated_by_admin_id);
    }

    /**
     * The availability half for renewals. The renewal path never called
     * systemAdmin(), but it is the one self-service path a customer reaches while
     * already paying, so "works on a platform with no administrator" is asserted
     * for it directly rather than inferred from the purchase test.
     */
    public function test_a_renewal_succeeds_on_a_platform_with_no_super_admin(): void
    {
        $owner = $this->ownerOfFreshShop();
        $plan = $this->plan();

        $current = ShopSubscription::create([
            'shop_id' => $owner->shop_id,
            'user_id' => $owner->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subYear(),
            'ends_at' => now()->addDays(5),
            'grace_ends_at' => now()->addDays(10),
            'billing_cycle' => 'yearly',
            'price_paid' => $plan->price_yearly,
            'razorpay_payment_id' => 'pay_prior_noadmin',
            'razorpay_order_id' => 'order_prior_noadmin',
            'updated_by_admin_id' => null,
            'actor_type' => 'self_service',
        ]);

        PlatformAdmin::query()->delete();
        $this->assertSame(0, PlatformAdmin::count());

        $renewed = $this->service()->renewSubscription(
            $current, 'yearly', (float) $plan->price_yearly, 'pay_renew_noadmin', 'order_renew_noadmin',
        );

        $this->assertSame('active', $renewed->status);
        $this->assertNull($renewed->updated_by_admin_id);
    }

    // ── the other direction: a real admin action stays attributed ────────────

    /**
     * THE HALF THAT MUST NOT BE LOST. Nulling the invented stamps is only correct
     * because a genuine administrator action still records one — otherwise the
     * fix would have replaced "an admin who did not act" with "no record of the
     * admin who did", and `updated_by_admin_id` would mean nothing either way.
     *
     * Driven through the real HTTP surface an administrator uses, not the
     * service, because the controller is where the acting admin is resolved.
     */
    public function test_an_administrator_keyed_subscription_still_records_that_administrator(): void
    {
        $admin = $this->createPlatformAdmin();
        $admin->forceFill(['email_verified_at' => now()])->save();

        $owner = $this->ownerOfFreshShop();
        $plan = $this->plan();

        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $this->actingAs($admin, 'platform_admin')
            ->withSession([\App\Http\Middleware\EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->patch(route('admin.shops.subscription', $owner->shop_id), [
                'plan_id' => $plan->id,
                'status' => 'active',
                'billing_cycle' => 'yearly',
                'starts_at' => now()->toDateString(),
                'ends_at' => now()->addYear()->toDateString(),
                'price_paid' => $plan->price_yearly,
                'reason' => 'manual term keyed in by support',
            ])->assertSessionHasNoErrors();

        $subscription = ShopSubscription::where('shop_id', $owner->shop_id)->latest('id')->firstOrFail();

        $this->assertSame($admin->id, $subscription->updated_by_admin_id,
            'an administrator really did key this in; the row must say so');
    }
}
