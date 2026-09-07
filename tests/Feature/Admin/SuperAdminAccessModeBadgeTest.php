<?php

namespace Tests\Feature\Admin;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Guards the Super Admin access-mode BADGE only. Presentation, nothing else.
 *
 * Both admin shop screens used to echo the raw shops.access_mode column, so a
 * subscription lapse and a deliberate administrator hold rendered the identical
 * amber "Read Only" chip. They need opposite operator responses:
 *
 *   • administrator hold — deliberate, and NOT self-service recoverable: while
 *     it stands the owner cannot buy or renew a plan.
 *   • subscription lapse — not a restriction anybody imposed; the owner is
 *     already routed to the plan picker and can restore access unaided.
 *
 * Reading the label off Shop::suspensionIsSubscriptionManaged() — the same
 * classifier AuthenticatedSessionController and EnsureSubscriptionIsActive act
 * on — is what stops the badge disagreeing with the owner's real experience.
 * The classifier is deliberately not re-derived here from a reason string or a
 * missing timestamp: only the classifier's own corroboration decides.
 */
class SuperAdminAccessModeBadgeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function actingAsSuperAdmin(): self
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'Badge', 'last_name' => 'Test', 'name' => 'Badge Test',
            'email' => 'badge' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return $this->actingAs($admin, 'platform_admin')
            ->withSession([\App\Http\Middleware\EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    /**
     * The JF-0001 shape the pre-2026-08-27 expiry fork left behind: read_only on
     * the shop, a corroborating read_only subscription row, no live term and no
     * administrator attribution.
     */
    private function lapsedShop(): Shop
    {
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');

        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'read_only',
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at'   => now()->subDays(25)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode'  => 'read_only',
            'is_active'    => false,
            'suspended_by' => null,
        ])->save();

        return $shop;
    }

    /** A deliberate administrator hold: same mode, but an actor is recorded. */
    private function adminHeldShop(PlatformAdmin $admin): Shop
    {
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');

        // Same corroborating row as the lapse, so the ONLY difference between
        // the two fixtures is the recorded actor. Without this the test could
        // pass for the wrong reason.
        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'read_only',
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at'   => now()->subDays(25)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Compliance hold',
            'suspended_by'      => $admin->id,
        ])->save();

        return $shop;
    }

    // ════════════════════════════════════════════════════════════════
    // Subscription-managed expiry
    // ════════════════════════════════════════════════════════════════

    public function test_lapsed_shop_shows_subscription_ended_on_the_detail_screen(): void
    {
        $shop = $this->lapsedShop();

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Subscription ended')
            ->assertDontSee('Read Only');
    }

    public function test_lapsed_shop_shows_subscription_ended_in_the_list(): void
    {
        $shop = $this->lapsedShop();

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.index', ['q' => $shop->name]))
            ->assertOk()
            ->assertSee('Subscription ended');
    }

    public function test_lapsed_shop_detail_tells_the_operator_the_owner_can_self_recover(): void
    {
        $shop = $this->lapsedShop();

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('not', false)
            ->assertSee('choose a plan to restore access', false)
            ->assertSee('No action is needed here.', false);
    }

    // ════════════════════════════════════════════════════════════════
    // Deliberate administrative restriction — unchanged
    // ════════════════════════════════════════════════════════════════

    public function test_admin_held_shop_keeps_the_read_only_label(): void
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'Holder', 'last_name' => 'Admin', 'name' => 'Holder Admin',
            'email' => 'holder' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $shop = $this->adminHeldShop($admin);

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Read Only')
            ->assertDontSee('Subscription ended');
    }

    public function test_active_and_suspended_labels_are_untouched(): void
    {
        $active = $this->createShop('retailer');
        $active->forceFill(['access_mode' => 'active', 'is_active' => true])->save();

        $suspended = $this->createShop('retailer');
        $suspended->forceFill(['access_mode' => 'suspended', 'is_active' => false])->save();

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $active->id]))
            ->assertOk()
            ->assertSee('Active');

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $suspended->id]))
            ->assertOk()
            ->assertSee('Suspended');
    }

    // ════════════════════════════════════════════════════════════════
    // The warning that guards the controls (shown on every shop)
    // ════════════════════════════════════════════════════════════════

    public function test_access_controls_warn_that_an_admin_restriction_blocks_renewal(): void
    {
        $shop = $this->createShop('retailer');

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('cannot buy or renew a plan', false);
    }
}
