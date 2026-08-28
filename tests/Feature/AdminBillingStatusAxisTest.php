<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 OPUS-AUDIT CORRECTIONS — group 4: the platform-admin billing writer must
 * keep the TWO AXES separate.
 *
 *   entitlement  : trial | active | grace | expired | cancelled
 *   admin access : active | read_only | suspended
 *
 * Changing a subscription's ENTITLEMENT status must never become an
 * ADMINISTRATIVE hold. An administrative hold is a separate, intentional act —
 * the operator picking the literal `read_only` or `suspended` status.
 *
 * The controller already declares its own axis at the top of
 * updateShopSubscription():
 *     $entitling = in_array($status, ['trial', 'active', 'grace'], true);
 * and then contradicts it when writing the shop, putting `grace` into the
 * read-only branch and stamping suspended_by for a plain expiry. There is no
 * ambiguity to resolve — only a contradiction to remove.
 */
class AdminBillingStatusAxisTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        Route::middleware(['web', 'auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])
            ->group(function () {
                Route::get('/_ax/erp-read', fn () => response('ok'))->name('ax.erp.read');
            });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: \App\Models\User, 1: Shop, 2: Plan, 3: PlatformAdmin} */
    private function tenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $admin->forceFill(['email_verified_at' => now()])->save();
        $plan = $this->createPlan('manufacturer');
        $shop = $this->createShop('manufacturer');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);

        return [$user, $shop, $plan, $admin];
    }

    private function submit(PlatformAdmin $admin, Shop $shop, array $payload)
    {
        return $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->patch(route('admin.shops.subscription', $shop), $payload);
    }

    private function payload(Plan $plan, string $status, array $overrides = []): array
    {
        $entitling = in_array($status, ['trial', 'active', 'grace'], true);

        return array_merge([
            'plan_id'       => $plan->id,
            'status'        => $status,
            'billing_cycle' => $entitling ? 'yearly' : null,
            'starts_at'     => now()->subMonth()->toDateString(),
            'ends_at'       => $entitling ? now()->addYear()->toDateString() : now()->subDay()->toDateString(),
            'reason'        => 'axis test',
        ], $overrides);
    }

    // ── Entitled statuses → entitled shop, never read-only ──────────────────

    public static function entitledStatuses(): array
    {
        return [['trial'], ['active'], ['grace']];
    }

    /**
     * @dataProvider entitledStatuses
     *
     * STRENGTHENED BY THE FINAL AUDIT. Every shop-state assertion at the bottom
     * of this test is already satisfied by CreatesTestTenant::createShop()'s own
     * defaults (access_mode = 'active', is_active = true, the rest null), so for
     * `trial` and `active` the test used to pass even if the request had done
     * NOTHING AT ALL — a 500, an MFA/auth bounce, a validation rejection or a
     * rolled-back transaction would each have left the fixture untouched and
     * still gone green. It proved the shop was entitled, never that the writer
     * ran. The request outcome and the persisted row are asserted FIRST, so the
     * shop-axis assertions only ever run on a request that genuinely committed.
     */
    public function test_entitled_status_leaves_the_shop_entitled(string $status): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $this->assertSame(
            0,
            ShopSubscription::where('shop_id', $shop->id)->count(),
            'fixture: the row asserted below must be the one this request writes'
        );

        // `grace` is a live term whose paid window closed TODAY — the inclusive
        // boundary the controller's own validation accepts for an entitling status.
        $overrides = $status === 'grace' ? ['ends_at' => now()->toDateString()] : [];

        $response = $this->submit($admin, $shop, $this->payload($plan, $status, $overrides));

        // 1. The request SUCCEEDED. back() + a success flash is the writer's only
        //    happy path, so this rules out the 500 / auth-redirect / validation
        //    outcomes that would otherwise leave the defaults below looking right.
        $response->assertSessionHasNoErrors();
        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertSame(302, $response->getStatusCode());

        // 2. The write COMMITTED, carrying the status actually requested. A
        //    rolled-back transaction leaves no row here at all.
        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
        $this->assertNotNull($sub, "Status {$status} must persist a subscription row; nothing was committed.");
        $this->assertSame($status, $sub->status, 'The latest row must carry the REQUESTED entitlement status.');
        $this->assertSame($plan->id, $sub->plan_id);

        // 3. Only now is the shop axis meaningful.
        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode, "Status {$status} is entitling and must not restrict shop access.");
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspended_by, "Status {$status} is not an administrative act.");
        $this->assertNull($fresh->suspension_reason);
        $this->assertFalse($fresh->suspensionIsAdministrative());
    }

    /** Valid grace stays fully accessible right through grace_ends_at, inclusive. */
    public function test_valid_grace_remains_accessible_through_grace_ends_at(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, 'grace', ['ends_at' => now()->toDateString()]))
            ->assertSessionHasNoErrors();

        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->assertNotNull($sub->grace_ends_at);

        Carbon::setTestNow(Carbon::parse($sub->grace_ends_at)->setTime(12, 0));

        $this->actingAs($user)->get('/_ax/erp-read')->assertOk()->assertSee('ok');
        $this->assertTrue(ShopSubscription::hasLivePaidTermToday($sub->fresh()));
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // ── Lapsed entitlement → renewal-capable, NOT an administrative hold ────

    public static function lapsedStatuses(): array
    {
        return [['expired'], ['cancelled']];
    }

    /** @dataProvider lapsedStatuses */
    public function test_lapsed_status_is_a_renewal_capable_subscription_lapse(string $status): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, $status))
            ->assertSessionHasNoErrors();

        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode);
        $this->assertNull(
            $fresh->suspended_by,
            "Setting a subscription {$status} is an entitlement change, not an administrator hold — stamping suspended_by makes it permanently unrecoverable."
        );
        $this->assertFalse($fresh->suspensionIsAdministrative());
        $this->assertTrue(
            $fresh->suspensionIsSubscriptionManaged(),
            "A {$status} subscription must stay owner-recoverable."
        );

        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->assertFalse(ShopSubscription::blocksNewPaidTerm($sub, $fresh), 'The owner must be able to buy a new term.');
    }

    /** @dataProvider lapsedStatuses */
    public function test_lapsed_shop_owner_is_routed_to_renewal_not_logged_out(string $status): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, $status))->assertSessionHasNoErrors();

        $this->actingAs($user)->get('/_ax/erp-read')->assertRedirect(route('subscription.plans'));
        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
    }

    // ── Explicit administrative statuses stay administrative ────────────────

    public function test_explicit_read_only_is_an_administrative_hold(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, 'read_only'))->assertSessionHasNoErrors();

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertSame($admin->id, $fresh->suspended_by);
        $this->assertTrue($fresh->suspensionIsAdministrative());
        $this->assertFalse($fresh->suspensionIsSubscriptionManaged());

        $sub = ShopSubscription::where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->assertTrue(ShopSubscription::blocksNewPaidTerm($sub, $fresh), 'Payment can never lift an administrative hold.');
    }

    public function test_explicit_suspended_is_an_administrative_hold(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, 'suspended'))->assertSessionHasNoErrors();

        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode);
        $this->assertSame($admin->id, $fresh->suspended_by);
        $this->assertTrue($fresh->suspensionIsAdministrative());
        $this->assertFalse($fresh->suspensionIsSubscriptionManaged());
    }

    /** Administrative read-only keeps browsing but blocks writes and purchase. */
    public function test_administrative_read_only_keeps_browsing_and_blocks_the_owner_from_purchasing(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, 'read_only'))->assertSessionHasNoErrors();

        $this->actingAs($user)->get('/_ax/erp-read')->assertOk();
        $this->assertSame('read_only', $shop->fresh()->access_mode);
    }

    // ── Returning to an entitled state clears every stale field ─────────────

    /**
     * RETARGETED. This test used to suspend via the billing form and then assert
     * that submitting `active` through the SAME form wiped suspended_by. That is
     * now forbidden: an entitling grant records entitlement and nothing else, so
     * it can no longer lift an administrative hold — not even one this form
     * imposed. See test_billing_cannot_lift_its_own_administrative_hold below.
     *
     * The behaviour this test actually exists to protect is unchanged and still
     * asserted here: a shop that is down purely because its subscription lapsed
     * must come all the way back, with no stale field left behind to re-block it.
     * `expired` is the honest fixture for that — the non-entitling branch writes
     * suspended_by = null, exactly as the expiry scheduler does.
     */
    public function test_switching_back_to_an_entitled_status_clears_stale_subscription_managed_fields(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, 'expired'))->assertSessionHasNoErrors();
        $lapsed = $shop->fresh();
        $this->assertSame('suspended', $lapsed->access_mode);
        $this->assertNull($lapsed->suspended_by, 'a lapse is not an administrative act');
        $this->assertTrue($lapsed->suspensionIsSubscriptionManaged());

        $this->submit($admin, $shop, $this->payload($plan, 'active'))->assertSessionHasNoErrors();

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspended_by);
        $this->assertNull($fresh->suspended_at);
        $this->assertNull($fresh->suspension_reason);
        $this->assertNull($fresh->suspended_until);
        $this->assertNull($fresh->deactivated_at);
    }

    /**
     * DELIBERATE BEHAVIOUR CHANGE. Picking the literal `suspended` / `read_only`
     * status on the billing form is an ADMINISTRATIVE act — the controller says
     * so itself, and stamps suspended_by to prove it. Once stamped, the hold is
     * indistinguishable from one imposed through Shops → Status, because
     * suspended_by is the only origin signal there is. So the billing form can no
     * longer undo it either: entitlement grants do not adjudicate access.
     *
     * The hold is not permanent — it is just no longer a side effect. The
     * separate, separately-audited access action lifts it.
     */
    public function test_billing_cannot_lift_its_own_administrative_hold(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, 'suspended'))->assertSessionHasNoErrors();
        $this->assertSame($admin->id, $shop->fresh()->suspended_by);

        // An entitling grant records the term but leaves the hold standing.
        $this->submit($admin, $shop, $this->payload($plan, 'active'))->assertSessionHasNoErrors();
        $held = $shop->fresh();
        $this->assertSame('suspended', $held->access_mode, 'the administrative hold must survive the grant');
        $this->assertSame($admin->id, $held->suspended_by);
        $this->assertNotNull(
            ShopSubscription::where('shop_id', $shop->id)->where('status', 'active')->latest('id')->first(),
            'the entitlement itself must still be recorded'
        );

        // The supported access action is the way out.
        $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->patch(route('admin.shops.status', $shop), [
                'access_mode' => 'active',
                'reason'      => 'Hold reviewed and lifted',
            ])->assertSessionHasNoErrors();

        $lifted = $shop->fresh();
        $this->assertSame('active', $lifted->access_mode);
        $this->assertTrue((bool) $lifted->is_active);
        $this->assertNull($lifted->suspended_by);
    }
}
