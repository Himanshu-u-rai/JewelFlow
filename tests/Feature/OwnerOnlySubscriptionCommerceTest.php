<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformInvoice;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 OPUS-AUDIT CORRECTIONS — groups 5 and 6.
 *
 *   5. Subscription COMMERCE is owner-only. A cashier must not be able to pick a
 *      plan, open checkout, initiate a Razorpay order, or read the shop's
 *      PlatformInvoice history. Staff keep exactly two things: logout, and the
 *      "ask the shop owner to renew" message.
 *
 *   6. The status ⇄ plans redirect chain must terminate. An administrator-held
 *      shop that never bought a subscription used to bounce forever: status has
 *      no subscription to render so it forwards to plans, and plans refuses an
 *      administratively-held shop so it forwards back to status.
 *
 * role:owner middleware cannot be used on the commerce routes themselves —
 * RoleMiddleware redirects any user without a shop_id to shops.create, and shop
 * creation sits AFTER plan selection in onboarding, so a blanket route guard
 * would lock every new signup out of the funnel. The guard therefore lives in
 * the controller and bites only once a shop (and therefore a role) exists.
 * subscription.status is different — it is inside the ERP group where a shop
 * always exists — so it takes the project's established route middleware.
 */
class OwnerOnlySubscriptionCommerceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    /** @return array{0: User, 1: Shop, 2: Plan, 3: PlatformAdmin} */
    private function tenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan  = $this->createPlan('manufacturer');
        $shop  = $this->createShop('manufacturer');
        $role  = $this->createOwnerRole($shop->id);
        $user  = $this->createOwnerUser($shop, $role);
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);

        return [$user, $shop, $plan, $admin];
    }

    private function staffUser(Shop $shop): User
    {
        $role = new Role();
        $role->forceFill(['name' => 'cashier', 'display_name' => 'Cashier', 'shop_id' => $shop->id]);
        $role->save();

        return User::factory()->create([
            'shop_id'   => $shop->id,
            'role_id'   => $role->id,
            'is_active' => true,
        ]);
    }

    private function subscription(Shop $shop, Plan $plan, array $attrs = []): ShopSubscription
    {
        return ShopSubscription::create(array_merge([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at'   => now()->addMonth()->toDateString(),
        ], $attrs));
    }

    private function lockAsAdministrativeReadOnly(Shop $shop, PlatformAdmin $admin): void
    {
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => true,
            'suspended_at'      => now(),
            'suspension_reason' => 'Compliance review by platform admin',
            'suspended_by'      => $admin->id,
            'suspended_until'   => null,
        ])->save();
    }

    // ════════════════════════════════════════════════════════════════════════
    // GROUP 5 — owner-only commerce
    // ════════════════════════════════════════════════════════════════════════

    /** Every owner commerce entry point, with the verb the real route uses. */
    public static function commerceRoutes(): array
    {
        return [
            'plans'    => ['get',  'subscription.plans'],
            'choose'   => ['post', 'subscription.choose'],
            'payment'  => ['get',  'subscription.payment'],
            'initiate' => ['post', 'subscription.payment.initiate'],
            'callback' => ['post', 'subscription.payment.callback'],
            'status'   => ['get',  'subscription.status'],
            'trial'    => ['post', 'subscription.trial.start'],
        ];
    }

    /** @dataProvider commerceRoutes */
    public function test_staff_are_denied_every_owner_commerce_route(string $verb, string $routeName): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan);
        $staff = $this->staffUser($shop);

        $this->actingAs($staff)
            ->{$verb}(route($routeName), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertForbidden();
    }

    public function test_staff_cannot_read_the_shops_platform_invoice_history(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan);
        $staff = $this->staffUser($shop);

        $invoice = PlatformInvoice::create([
            'shop_id'              => $shop->id,
            'shop_subscription_id' => $sub->id,
            'plan_id'              => $plan->id,
            'invoice_number'       => 'PLT-STAFF-LEAK-0001',
            'invoice_sequence'     => 1,
            'billing_cycle'        => 'monthly',
            'billing_period_start' => now()->subMonth()->toDateString(),
            'billing_period_end'   => now()->toDateString(),
            'amount_before_tax'    => 999,
            'gst_rate'             => 18,
            'gst_amount'           => 179.82,
            'total_amount'         => 1178.82,
            'status'               => 'paid',
            'issued_at'            => now(),
            'created_by_admin_id'  => $admin->id,
        ]);

        $response = $this->actingAs($staff)->get(route('subscription.status'));

        $response->assertForbidden();
        $response->assertDontSee($invoice->invoice_number);
    }

    public function test_staff_can_still_log_out(): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan);
        $staff = $this->staffUser($shop);

        $this->actingAs($staff)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    /** The staff-facing recovery message must survive the owner-only guard. */
    public function test_staff_on_a_lapsed_shop_still_get_the_ask_your_owner_message(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now(),
            'suspension_reason' => 'Subscription expired',
            'suspended_by'      => null,
        ])->save();
        $staff = $this->staffUser($shop);

        $this->actingAs($staff)
            ->get(route('dashboard'))
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['mobile_number' => 'Your shop owner must renew the subscription to restore access.']);
    }

    /** Owners keep every route. */
    public function test_owner_still_reaches_the_plan_picker_and_checkout(): void
    {
        [$user, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);

        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
        $this->actingAs($user)
            ->post(route('subscription.choose'), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('subscription.payment'));
    }

    /**
     * ONBOARDING TRAP. A brand-new signup has no shop and therefore no role at
     * all. The guard must not fire for them — plan selection comes BEFORE shop
     * creation, which is exactly why role:owner middleware cannot be used here.
     */
    public function test_a_user_without_a_shop_can_still_reach_plan_selection(): void
    {
        $plan = $this->createPlan('manufacturer');
        $user = User::factory()->create([
            'shop_id'              => null,
            'role_id'              => null,
            'is_active'            => true,
            'onboarding_shop_type' => 'manufacturer',
        ]);

        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
        $this->actingAs($user)
            ->post(route('subscription.choose'), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('subscription.payment'));
    }

    /**
     * ROLE-LESS OWNER TRAP. ShopController assigns the owner role as
     * `$ownerRole?->id`, so a failed ensureDefaultsForShop() (or a legacy /
     * admin-created row) leaves a shop's ONLY human with role_id = null.
     *
     * Staff can never be in that state — StaffController requires role_id and
     * excludes the owner role — so a role-less user is always the owner. Denying
     * them commerce would rebuild the exact payment dead end this P0 removes, on
     * the one person able to pay. The owner-only guard must therefore fail OPEN
     * on a missing role and CLOSED only on a proven non-owner role.
     *
     * subscription.status is included deliberately: every failure path in
     * paymentCallback() lands there, so a 403 would make refund references and
     * signature errors invisible again — the very regression commit c9190aa fixed.
     */
    public function test_a_role_less_owner_is_not_locked_out_of_renewal(): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);

        $owner = User::factory()->create([
            'shop_id'   => $shop->id,
            'role_id'   => null,
            'is_active' => true,
        ]);

        $this->actingAs($owner)->get(route('subscription.plans'))->assertOk();
        $this->actingAs($owner)
            ->post(route('subscription.choose'), ['plan_id' => $plan->id, 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('subscription.payment'));
        // The invariant is "not DENIED", not a particular destination: an entitled
        // shop legitimately forwards status to the Settings tab, a locked one
        // renders in place. Either is fine; 403 is not.
        $this->assertNotSame(
            403,
            $this->actingAs($owner)->get(route('subscription.status'))->getStatusCode(),
            'A role-less owner must never be denied the renewal-feedback page.'
        );
    }

    /** The unauthenticated, signature-verified webhook must stay reachable. */
    public function test_the_signature_verified_webhook_is_unchanged_and_reachable(): void
    {
        $response = $this->postJson(route('subscription.payment.webhook'), ['event' => 'payment.captured']);

        // It must reach the SIGNATURE check — not an auth or role gate.
        $this->assertContains(
            $response->getStatusCode(),
            [400, 500],
            'The webhook must remain unauthenticated and reach signature verification.'
        );
        $this->assertNotSame(403, $response->getStatusCode());
    }

    // ════════════════════════════════════════════════════════════════════════
    // GROUP 6 — redirect-chain termination
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Walk a redirect chain in ONE session (no re-authentication between hops,
     * or a middleware logout would be silently undone and the loop hidden).
     *
     * @return array{0: array<int, string>, 1: ?\Illuminate\Testing\TestResponse}
     */
    private function followChain(string $url, int $max = 5): array
    {
        $visited = [];

        for ($hop = 0; $hop < $max; $hop++) {
            $response = $this->get($url);
            $visited[] = $url;

            if (! $response->isRedirect()) {
                return [$visited, $response];
            }

            $url = $response->headers->get('Location');
        }

        return [$visited, null];
    }

    public function test_administratively_held_shop_without_a_subscription_terminates_the_chain(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, , $admin] = $this->tenant();
        $this->lockAsAdministrativeReadOnly($shop, $admin);
        $this->assertNull(ShopSubscription::where('shop_id', $shop->id)->first());

        $this->actingAs($user);
        [$visited, $final] = $this->followChain(route('subscription.status'));

        $this->assertNotNull(
            $final,
            'status ⇄ plans never terminated. Chain: ' . implode(' → ', $visited)
        );
        $this->assertSame(
            count($visited),
            count(array_unique($visited)),
            'The chain revisited a URL, which is a loop. Chain: ' . implode(' → ', $visited)
        );
        $final->assertOk();
    }

    public function test_the_terminal_page_exposes_no_purchasable_controls_and_keeps_logout(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, , $admin] = $this->tenant();
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->actingAs($user);
        [, $final] = $this->followChain(route('subscription.status'));

        $this->assertNotNull($final);
        $final->assertOk();
        $final->assertDontSee(route('subscription.payment.initiate'));
        $final->assertDontSee(route('subscription.choose'));
        $final->assertSee(route('logout'));
    }

    public function test_administratively_held_owner_is_told_it_is_a_hold_not_an_active_subscription(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, , $admin] = $this->tenant();
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->actingAs($user)->get(route('subscription.plans'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', fn (string $message) => str_contains(strtolower($message), 'administrative hold')
                && ! str_contains(strtolower($message), 'already has an active paid subscription'));
    }

    /**
     * The same must hold when the held shop DOES have a subscription — the old
     * message claimed the owner "already has an active paid subscription", which
     * is both wrong and actively misleading during a compliance hold.
     */
    public function test_administratively_held_owner_with_a_subscription_gets_the_hold_message(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->actingAs($user)->get(route('subscription.plans'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', fn (string $message) => str_contains(strtolower($message), 'administrative hold'));
    }

    /** An administratively SUSPENDED shop terminates on the contact-support deny. */
    public function test_administratively_suspended_shop_without_a_subscription_terminates(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, , $admin] = $this->tenant();
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now(),
            'suspension_reason' => 'Fraud investigation',
            'suspended_by'      => $admin->id,
            'suspended_until'   => null,
        ])->save();

        $this->actingAs($user);
        [$visited, $final] = $this->followChain(route('subscription.status'));

        $this->assertNotNull($final, 'Chain: ' . implode(' → ', $visited));
        $this->assertSame(count($visited), count(array_unique($visited)), 'Chain: ' . implode(' → ', $visited));
        $this->assertGuest();
    }

    /** An administrative hold must never be purchasable around. */
    public function test_administratively_held_shop_cannot_initiate_payment(): void
    {
        [$user, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->actingAs($user)
            ->postJson(route('subscription.payment.initiate'), ['plan_id' => $plan->id])
            ->assertStatus(403);
    }
}
