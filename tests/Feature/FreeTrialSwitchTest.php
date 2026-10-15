<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformSetting;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Platform Settings → Free Trial → "Offer free trials".
 *
 * One switch for every product's NEW self-service trial. It lives in the single
 * automatic-trial gate (SubscriptionPaymentService::automaticTrialRefusalReason),
 * so the plans page and the POST that starts a trial cannot disagree — a page
 * opened while trials were on cannot start one after they were switched off.
 * Existing trials, paid subscriptions, trial length and eligibility history are
 * not touched by it.
 */
class FreeTrialSwitchTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private PlatformAdmin $admin;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config(['business.subscription_trial_days' => 30, 'platform.enforce_subscriptions' => true]);
        $this->seed(\Database\Seeders\PlatformProductSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->admin = $this->createPlatformAdmin();
    }

    /** @return array{0: Shop, 1: User} */
    private function owner(string $shopType = 'retailer'): array
    {
        $shop = Shop::create([
            'name' => ucfirst($shopType) . ' Shop', 'shop_type' => $shopType,
            'phone' => fake()->unique()->numerify('9########'),
            'owner_first_name' => 'A', 'owner_last_name' => 'B',
            'owner_mobile' => fake()->unique()->numerify('9########'),
            'is_active' => true, 'access_mode' => 'active',
        ]);
        $user = User::create([
            'name' => 'Owner', 'mobile_number' => fake()->unique()->numerify('9########'),
            'shop_id' => $shop->id, 'role_id' => $this->createOwnerRole($shop->id)->id,
            'password' => bcrypt('x'), 'is_active' => true,
        ]);

        return [$shop, $user];
    }

    private function plan(string $code = 'retailer_yearly'): Plan
    {
        return Plan::where('code', $code)->firstOrFail();
    }

    private function saveSettings(array $fields, ?PlatformAdmin $admin = null)
    {
        return $this->actingAs($admin ?? $this->admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->patch(route('admin.settings.update'), array_merge([
                'retailer_enabled' => '1', 'manufacturer_enabled' => '1', 'dhiran_enabled' => '1', 'maintenance_mode' => '0',
            ], $fields));
    }

    private function refusal(User $user, string $planCode): ?string
    {
        $this->actingAs($user);

        return app(SubscriptionPaymentService::class)->automaticTrialRefusalReason($this->plan($planCode));
    }

    public function test_trials_are_offered_by_default(): void
    {
        $this->assertTrue(PlatformSetting::trialsOffered());
        [, $user] = $this->owner();
        $this->actingAs($user)->get(route('subscription.plans'))->assertOk()
            ->assertSee('Free trial')->assertSee(route('subscription.trial.start'), false);
    }

    public function test_admin_can_switch_trials_off_and_on_and_both_changes_are_audited(): void
    {
        $this->saveSettings(['free_trials_offered' => '0', 'subscription_trial_days' => '30'])->assertSessionHasNoErrors();
        $this->assertFalse(PlatformSetting::trialsOffered());

        $this->saveSettings(['free_trials_offered' => '1', 'subscription_trial_days' => '30'])->assertSessionHasNoErrors();
        $this->assertTrue(PlatformSetting::trialsOffered());

        $audits = PlatformAuditLog::where('action', 'admin.platform_settings.trial_availability_updated')->orderBy('id')->get();
        $this->assertCount(2, $audits);
        $this->assertSame([['free_trials_offered' => true], ['free_trials_offered' => false]], [$audits[0]->before, $audits[0]->after]);
        $this->assertSame([['free_trials_offered' => false], ['free_trials_offered' => true]], [$audits[1]->before, $audits[1]->after]);
        $this->assertSame($this->admin->id, $audits[0]->actor_admin_id);
    }

    public function test_settings_page_shows_the_switch_in_the_free_trial_section(): void
    {
        $this->actingAs($this->admin, 'platform_admin')->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->get(route('admin.settings.index'))->assertOk()
            ->assertSeeInOrder(['Free Trial', 'Offer free trials', 'Trial length (days)'])
            ->assertSee('name="free_trials_offered"', false);
    }

    public function test_tenant_users_and_non_super_admins_cannot_change_it(): void
    {
        [, $user] = $this->owner();
        $this->actingAs($user)->patch(route('admin.settings.update'), ['free_trials_offered' => '0']);
        $this->assertTrue(PlatformSetting::trialsOffered());

        $support = $this->createPlatformAdmin();
        $support->forceFill(['role' => 'platform_operator'])->save();
        $this->saveSettings(['free_trials_offered' => '0'], $support)->assertForbidden();
        $this->assertTrue(PlatformSetting::trialsOffered());
    }

    public function test_saving_other_settings_never_changes_trial_availability(): void
    {
        PlatformSetting::set('free_trials_offered', 'false');

        // The main form without the switch field (an older page, another client).
        $this->saveSettings(['subscription_trial_days' => '45'])->assertSessionHasNoErrors();
        $this->assertFalse(PlatformSetting::trialsOffered());
        $this->assertSame(45, PlatformSetting::trialDays());

        // The separate mail form.
        $this->actingAs($this->admin, 'platform_admin')->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->patch(route('admin.settings.update'), ['section' => 'mail', 'mail_mailer' => 'log']);
        $this->assertFalse(PlatformSetting::trialsOffered());

        // And switching it never moves the trial length.
        $this->saveSettings(['free_trials_offered' => '1', 'subscription_trial_days' => '45']);
        $this->assertSame(45, PlatformSetting::trialDays());
        $this->assertTrue(PlatformSetting::trialsOffered());
    }

    public function test_off_hides_the_offer_and_a_stale_plans_page_cannot_start_a_trial(): void
    {
        [$shop, $user] = $this->owner();
        $this->actingAs($user)->get(route('subscription.plans'))->assertSee(route('subscription.trial.start'), false);

        PlatformSetting::set('free_trials_offered', 'false');

        $this->actingAs($user)->get(route('subscription.plans'))->assertOk()
            ->assertDontSee(route('subscription.trial.start'), false)
            ->assertDontSee('Start free trial');
        // The form opened before the switch is submitted anyway.
        $this->actingAs($user)->post(route('subscription.trial.start'), ['plan_id' => $this->plan()->id])
            ->assertRedirect(route('subscription.plans'))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'not being offered'));
        $this->assertSame(0, ShopSubscription::where('shop_id', $shop->id)->count());
    }

    public function test_both_products_follow_the_switch_at_the_shared_gate(): void
    {
        [, $retail] = $this->owner('retailer');
        [, $dhiran] = $this->owner('dhiran');
        $retailOn = $this->refusal($retail, 'retailer_yearly');
        $dhiranOn = $this->refusal($dhiran, 'dhiran_yearly');
        $this->assertNull($retailOn);

        PlatformSetting::set('free_trials_offered', 'false');
        $this->assertStringContainsString('not being offered', $this->refusal($retail, 'retailer_yearly'));
        $this->assertStringContainsString('not being offered', $this->refusal($dhiran, 'dhiran_yearly'));
        $this->actingAs($dhiran);
        $this->expectException(\LogicException::class);
        try {
            app(SubscriptionPaymentService::class)->startTrial($this->plan('dhiran_yearly'));
        } finally {
            $this->assertSame(0, ShopSubscription::where('user_id', $dhiran->id)->count());
            PlatformSetting::set('free_trials_offered', 'true');
            // Back ON: exactly the eligibility each product had before.
            $this->assertSame($retailOn, $this->refusal($retail, 'retailer_yearly'));
            $this->assertSame($dhiranOn, $this->refusal($dhiran, 'dhiran_yearly'));
        }
    }

    public function test_switching_back_on_does_not_reset_used_trial_eligibility(): void
    {
        [$shop, $user] = $this->owner();
        $this->actingAs($user);
        $trial = app(SubscriptionPaymentService::class)->startTrial($this->plan());
        $trial->forceFill(['status' => 'expired', 'ends_at' => now()->subDay()->toDateString(), 'grace_ends_at' => now()->subDay()->toDateString()])->save();

        PlatformSetting::set('free_trials_offered', 'false');
        PlatformSetting::set('free_trials_offered', 'true');

        $this->assertStringContainsString('already used your free trial', $this->refusal($user, 'retailer_yearly'));
        $this->actingAs($user)->post(route('subscription.trial.start'), ['plan_id' => $this->plan()->id]);
        $this->assertSame(1, ShopSubscription::where('shop_id', $shop->id)->count());
    }

    public function test_existing_trials_and_paid_subscriptions_are_unchanged_and_paid_plans_stay_available(): void
    {
        [$trialShop, $trialUser] = $this->owner();
        $this->actingAs($trialUser);
        $trial = app(SubscriptionPaymentService::class)->startTrial($this->plan());
        [$paidShop, $paidUser] = $this->owner();
        $paid = ShopSubscription::create([
            'shop_id' => $paidShop->id, 'user_id' => $paidUser->id, 'plan_id' => $this->plan()->id, 'status' => 'active',
            'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(11), 'grace_ends_at' => now()->addMonths(11)->addDays(7),
            'billing_cycle' => 'yearly', 'price_paid' => 50000, 'razorpay_payment_id' => 'pay_switch_test', 'razorpay_order_id' => 'order_switch_test',
            'actor_type' => 'self_service',
        ]);
        $snapshot = fn () => [
            $trial->fresh()->only(['status', 'starts_at', 'ends_at', 'grace_ends_at']),
            $paid->fresh()->only(['status', 'starts_at', 'ends_at', 'grace_ends_at', 'price_paid']),
            $trialShop->fresh()->only(['access_mode', 'is_active']),
        ];
        $before = $snapshot();

        $this->saveSettings(['free_trials_offered' => '0', 'subscription_trial_days' => '30'])->assertSessionHasNoErrors();

        $this->assertEquals($before, $snapshot());

        // A new owner can still choose and pay for a plan.
        [, $newOwner] = $this->owner();
        $this->actingAs($newOwner)->get(route('subscription.plans'))->assertOk()
            ->assertSee(route('subscription.choose'), false);
    }

    /**
     * A paid term is exactly its billing cycle (SubscriptionTerm never reads
     * plan->trial_days), so the checkout must never say a trial is included —
     * with trials on or off.
     */
    public function test_paid_checkout_never_promises_trial_days_the_paid_term_does_not_grant(): void
    {
        $plan = $this->plan();
        $plan->forceFill(['trial_days' => 14])->save();
        [, $user] = $this->owner();
        $session = ['pending_plan_id' => $plan->id, 'pending_billing_cycle' => 'yearly'];

        foreach (['true', 'false'] as $offered) {
            PlatformSetting::set('free_trials_offered', $offered);
            $this->actingAs($user)->withSession($session)->get(route('subscription.payment'))->assertOk()
                ->assertDontSee('trial included')->assertDontSee('-day trial');
        }
    }
}
