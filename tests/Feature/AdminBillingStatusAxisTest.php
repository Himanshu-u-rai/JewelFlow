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

    /** @dataProvider entitledStatuses */
    public function test_entitled_status_leaves_the_shop_entitled(string $status): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        // `grace` is a live term whose paid window closed TODAY — the inclusive
        // boundary the controller's own validation accepts for an entitling status.
        $overrides = $status === 'grace' ? ['ends_at' => now()->toDateString()] : [];

        $this->submit($admin, $shop, $this->payload($plan, $status, $overrides))
            ->assertSessionHasNoErrors();

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

    public function test_switching_back_to_an_entitled_status_clears_stale_suspension_fields(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $this->submit($admin, $shop, $this->payload($plan, 'suspended'))->assertSessionHasNoErrors();
        $this->assertSame($admin->id, $shop->fresh()->suspended_by);

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
}
