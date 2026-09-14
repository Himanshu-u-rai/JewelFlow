<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A plan can only be billed on a cycle it prices.
 *
 * `plans` states "not sold on this cycle" by leaving the price column null —
 * the seeded `retailer_monthly`, `manufacturer_monthly` and `dhiran_monthly`
 * have no `price_yearly` at all. Both write paths validated the cycle STRING
 * (`in:monthly,yearly`) and neither asked the plan whether it sells it:
 *
 *   • The owner's plan picker (SubscriptionController::choosePlan) wrote the
 *     mismatched pair into the session and carried it to the payment screen,
 *     which then refused it with "invalid pricing, contact support" — a
 *     dead end one step after the owner believed they had chosen.
 *
 *   • The Super Admin subscription editor (BillingManagementController) is the
 *     one that could PERSIST it: a yearly term on a plan with no yearly price,
 *     which the owner's own status page then prices at nothing, because it
 *     falls back to the plan's cycle column when `price_paid` is null.
 *
 * Both now refuse the pair where it is chosen, and name the offending cycle.
 * The accept-cases are here for the same reason as the refuse-cases: a guard
 * that also blocks the supported cycle has not fixed anything.
 */
class UnsupportedBillingCycleTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        Bus::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    /** The shape of every seeded `*_monthly` plan: a monthly price, no yearly one. */
    private function monthlyOnlyPlan(): Plan
    {
        return Plan::create([
            'code' => 'retailer_monthly_' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Retailer Monthly',
            'price_monthly' => 4630.00,
            'price_yearly' => null,
            'trial_days' => 0,
            'grace_days' => 7,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ]);
    }

    private function bothCyclesPlan(): Plan
    {
        return Plan::create([
            'code' => 'retailer_yearly_' . fake()->unique()->numberBetween(1000, 999999),
            'name' => 'Retailer Yearly',
            'price_monthly' => 4167.00,
            'price_yearly' => 50000.00,
            'trial_days' => 0,
            'grace_days' => 14,
            'downgrade_to_read_only_on_due' => true,
            'is_active' => true,
        ]);
    }

    /** An owner of a shop with no subscription history — free to buy. */
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

    private function choose(User $owner, Plan $plan, string $cycle)
    {
        return $this->actingAs($owner)->post(route('subscription.choose'), [
            'plan_id' => $plan->id,
            'billing_cycle' => $cycle,
        ]);
    }

    private function verifiedAdmin(): PlatformAdmin
    {
        $admin = $this->createPlatformAdmin();
        $admin->forceFill(['email_verified_at' => now()])->save();

        return $admin;
    }

    private function submitAdmin(PlatformAdmin $admin, Shop $shop, array $payload)
    {
        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->patch(route('admin.shops.subscription', $shop), $payload);
    }

    // ── the owner's plan picker ─────────────────────────────────────────────

    public function test_the_plan_picker_refuses_a_cycle_the_plan_does_not_price(): void
    {
        $owner = $this->ownerOfFreshShop();
        $plan = $this->monthlyOnlyPlan();

        $response = $this->choose($owner, $plan, 'yearly');

        $response->assertRedirect(route('subscription.plans'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('yearly', session('error'),
            'The message must name the cycle that is wrong, not just say "invalid".');

        $this->assertNull(session('pending_plan_id'),
            'A refused pair must not be carried to the payment screen.');
        $this->assertNull(session('pending_billing_cycle'));
    }

    public function test_the_plan_picker_accepts_the_cycle_the_plan_does_price(): void
    {
        $owner = $this->ownerOfFreshShop();
        $plan = $this->monthlyOnlyPlan();

        $this->choose($owner, $plan, 'monthly')
            ->assertRedirect(route('subscription.payment'));

        $this->assertSame($plan->id, session('pending_plan_id'));
        $this->assertSame('monthly', session('pending_billing_cycle'));
    }

    public function test_a_plan_that_prices_both_cycles_still_accepts_either(): void
    {
        $plan = $this->bothCyclesPlan();

        $this->choose($this->ownerOfFreshShop(), $plan, 'yearly')
            ->assertRedirect(route('subscription.payment'));

        $this->choose($this->ownerOfFreshShop(), $plan, 'monthly')
            ->assertRedirect(route('subscription.payment'));
    }

    // ── the captured-payment path ───────────────────────────────────────────

    /**
     * The last gate before money becomes a term. An order that names an unpriced
     * cycle used to be measured against `(int) round(null * 100)` = 0 paise, so
     * it was refused — but refused as an AMOUNT MISMATCH, which tells the
     * operator reading the unresolved-payment evidence that the customer paid the
     * wrong amount. The cause is the plan, not the payment, and the classifier
     * must still call it permanent: no retry adds a price to a plan.
     */
    public function test_a_captured_payment_on_an_unpriced_cycle_is_refused_as_a_cycle_problem(): void
    {
        $plan = $this->monthlyOnlyPlan();
        $service = app(\App\Services\SubscriptionPaymentService::class);

        $order = (object) ['id' => 'order_CYC', 'amount' => 5000000, 'currency' => 'INR'];

        try {
            $service->verifyAmount($order, $plan, 'yearly');
            $this->fail('A cycle the plan does not price must not be accepted.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('yearly', $e->getMessage(),
                'The evidence must name the cycle, not report a wrong amount.');
            $this->assertFalse($service->isTransientPaymentError($e),
                'Unpriced cycle is permanent — retrying cannot give the plan a price.');
        }

        $this->assertDatabaseCount('shop_subscriptions', 0);
    }

    /** The priced cycle still passes the same gate. */
    public function test_a_captured_payment_on_the_priced_cycle_still_verifies(): void
    {
        $plan = $this->monthlyOnlyPlan();

        $this->assertSame(463000, app(\App\Services\SubscriptionPaymentService::class)->verifyAmount(
            (object) ['id' => 'order_OK', 'amount' => 463000, 'currency' => 'INR'],
            $plan,
            'monthly',
        ));
    }

    // ── the administrator's subscription editor ─────────────────────────────

    /**
     * The persisting path. Nothing may be written: no subscription row, and so
     * no invoice and no invoice email either.
     */
    public function test_an_administrator_cannot_bill_a_plan_on_a_cycle_it_does_not_price(): void
    {
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $plan = $this->monthlyOnlyPlan();

        $response = $this->submitAdmin($admin, $shop, [
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'yearly',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addYear()->toDateString(),
            'price_paid' => 50000,
            'reason' => 'override supplied so the failure is the cycle, not the dates',
        ]);

        $response->assertSessionHasErrors('billing_cycle');
        $this->assertDatabaseCount('shop_subscriptions', 0);
        $this->assertDatabaseCount('platform_invoices', 0);
        $this->assertSame('active', $shop->fresh()->access_mode,
            'A rejected submission must leave the shop untouched.');
    }

    /**
     * Deliberately a NON-entitling status. The cycle is not merely an
     * entitlement detail — it is copied onto the invoice and read back by the
     * status page, so an expired row carries the same misstatement. The guard
     * is not scoped to trial/active/grace.
     */
    public function test_the_cycle_is_checked_even_for_a_status_that_grants_nothing(): void
    {
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $plan = $this->monthlyOnlyPlan();

        $this->submitAdmin($admin, $shop, [
            'plan_id' => $plan->id,
            'status' => 'expired',
            'billing_cycle' => 'yearly',
            'starts_at' => now()->subYear()->toDateString(),
            'ends_at' => now()->subDay()->toDateString(),
            'reason' => 'closing out an old term',
        ])->assertSessionHasErrors('billing_cycle');

        $this->assertDatabaseCount('shop_subscriptions', 0);
    }

    public function test_an_administrator_can_still_bill_the_cycle_the_plan_prices(): void
    {
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $plan = $this->monthlyOnlyPlan();

        $this->submitAdmin($admin, $shop, [
            'plan_id' => $plan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(),
            'price_paid' => 4630,
            'reason' => 'test',
        ])->assertSessionHasNoErrors();

        $subscription = ShopSubscription::where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->assertSame('monthly', $subscription->billing_cycle);
        $this->assertSame('active', $subscription->status);
    }

    /**
     * The form is what leads an operator into it. It used to print
     * number_format(null) for an unpriced cycle — "₹0/yr" — which reads as a
     * price rather than as an absence.
     */
    public function test_the_admin_form_does_not_advertise_a_price_the_plan_does_not_have(): void
    {
        $admin = $this->verifiedAdmin();
        $shop = $this->createShop('retailer');
        $this->monthlyOnlyPlan();

        $response = $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->get(route('admin.shops.show', $shop));

        $response->assertOk();
        $response->assertSee('no yearly price');
        $response->assertDontSee('₹0/yr', false);
    }
}
