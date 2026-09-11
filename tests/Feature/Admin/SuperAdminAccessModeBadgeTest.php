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

    private function makeAdmin(string $prefix = 'badge'): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => ucfirst($prefix), 'last_name' => 'Test', 'name' => ucfirst($prefix) . ' Test',
            'email' => $prefix . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => \Illuminate\Support\Facades\Hash::make('password'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function actingAsSuperAdmin(): self
    {
        return $this->actingAs($this->makeAdmin(), 'platform_admin')
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
        $shop = $this->adminHeldShop($this->makeAdmin('holder'));

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

    // ════════════════════════════════════════════════════════════════
    // Whole-page consistency: header, Platform Control, subscription summary
    // ════════════════════════════════════════════════════════════════

    /**
     * read_only with NO recorded actor and NO corroborating lapse — the latest
     * subscription row is `active` (long expired), so check (1) of the classifier
     * fails and this is not a JF-0001 lapse either. Nobody can say who imposed
     * it, so it must read as unresolved, never as "no restriction".
     */
    private function unclassifiedReadOnlyShop(): Shop
    {
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');

        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => now()->subMonths(8)->toDateString(),
            'ends_at'   => now()->subMonths(4)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode'  => 'read_only',
            'is_active'    => false,
            'suspended_by' => null,
        ])->save();

        return $shop;
    }

    public function test_lapsed_shop_reads_ended_and_no_restriction_everywhere_on_the_page(): void
    {
        $shop = $this->lapsedShop();

        $html = $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Subscription ended')                       // header badge
            ->assertSee('Administrator restriction')                // Platform Control
            ->assertSee('None')
            ->assertSee('choose a plan to restore access', false)
            ->assertSee('Audit detail — stored access_mode: read_only', false)
            ->getContent();

        // The contradiction this change removes: the subscription summary must
        // not still present the raw "Read_only" as the current status.
        $this->assertStringNotContainsString('>Read_only<', $html);
        $this->assertStringContainsString('Audit detail — stored status: read_only', $html);
    }

    public function test_unclassified_read_only_is_not_presented_as_unrestricted(): void
    {
        $shop = $this->unclassifiedReadOnlyShop();

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Unresolved — needs review', false)
            ->assertSee('no administrator is recorded', false)
            // Not a confirmed lapse, so it must not claim self-recovery.
            ->assertDontSee('Subscription ended')
            ->assertDontSee('No action is needed here.', false);
    }

    public function test_admin_read_only_hold_is_identified_as_an_administrator_restriction(): void
    {
        $admin = $this->makeAdmin('holder');
        $shop  = $this->adminHeldShop($admin);

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Read Only')
            ->assertSee('deliberate <strong>administrator restriction</strong>', false)
            ->assertSee('The owner cannot lift this themselves.', false)
            ->assertDontSee('Subscription ended')
            ->assertDontSee('No action is needed here.', false);
    }

    /**
     * An administrator hold must survive a LIVE subscription: the entitlement
     * axis never overrides the administrative one.
     */
    public function test_a_live_subscription_does_not_override_an_administrator_hold(): void
    {
        $admin = $this->makeAdmin('livehold');
        $plan  = $this->createPlan('retailer');
        $shop  = $this->createShop('retailer');

        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at'   => now()->addMonths(6)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Compliance hold',
            'suspended_by'      => $admin->id,
        ])->save();

        $this->assertSame('admin_suspended', $shop->fresh()->accessClassification());

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Suspended')
            ->assertSee('deliberate <strong>administrator restriction</strong>', false)
            ->assertDontSee('Subscription ended');
    }

    public function test_active_shop_reports_no_restriction_and_no_audit_detail_line(): void
    {
        $shop = $this->createShop('retailer');
        $shop->forceFill(['access_mode' => 'active', 'is_active' => true])->save();

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Administrator restriction')
            ->assertSee('None')
            ->assertDontSee('Audit detail — stored access_mode', false)
            ->assertDontSee('Unresolved — needs review', false);
    }

    // ════════════════════════════════════════════════════════════════
    // The action form is an ACTION, not a mirror of current state
    // ════════════════════════════════════════════════════════════════

    public function test_fresh_form_preselects_nothing_and_prefills_no_reason(): void
    {
        $shop = $this->lapsedShop();

        $html = $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->getContent();

        // These three highlight classes are unique to the access-mode form and
        // render only for a chosen option.
        $this->assertStringNotContainsString('bg-emerald-500/20', $html, 'Active must never be the default choice.');
        $this->assertStringNotContainsString('bg-amber-500/20', $html, 'The stored read_only mode must not preselect Read-Only.');
        $this->assertStringNotContainsString('bg-rose-500/20', $html);

        // The new action's reason starts empty — not seeded from the legacy
        // subscription reason stored on the shop.
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringContainsString('value=""', $html);
        $this->assertStringNotContainsString('value="Subscription read_only"', $html);
    }

    public function test_an_untouched_form_cannot_apply_a_restriction(): void
    {
        $shop = $this->lapsedShop();

        $this->actingAsSuperAdmin()
            ->from(route('admin.shops.show', ['shop' => $shop->id]))
            ->patch(route('admin.shops.status', ['shop' => $shop->id]), [])
            ->assertSessionHasErrors('access_mode');

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertNull($fresh->suspended_by, 'A rejected submit must not stamp an administrator.');
    }

    public function test_a_submitted_choice_and_reason_survive_a_validation_failure(): void
    {
        config(['platform.enforce_subscriptions' => true]);

        $shop = $this->lapsedShop();
        $url  = route('admin.shops.show', ['shop' => $shop->id]);

        // Activation is refused: no term covers today. That guard is unchanged.
        $this->actingAsSuperAdmin()
            ->from($url)
            ->patch(route('admin.shops.status', ['shop' => $shop->id]), [
                'access_mode' => 'active',
                'reason'      => 'Goodwill restore',
            ])
            ->assertSessionHasErrors('access_mode');

        $this->assertSame('read_only', $shop->fresh()->access_mode, 'An unpaid shop must not be activated.');

        // Re-rendering keeps what the operator actually chose.
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('bg-emerald-500/20', $html, 'The submitted Active choice should be re-selected.');
        $this->assertStringContainsString('value="Goodwill restore"', $html);
        $this->assertStringContainsString('Cannot activate', $html, 'The rejection must be visible on screen.');
    }

    public function test_a_deliberate_suspension_still_applies_and_is_attributed(): void
    {
        $shop = $this->createShop('retailer');
        $shop->forceFill(['access_mode' => 'active', 'is_active' => true])->save();

        $this->actingAsSuperAdmin()
            ->from(route('admin.shops.show', ['shop' => $shop->id]))
            ->patch(route('admin.shops.status', ['shop' => $shop->id]), [
                'access_mode' => 'suspended',
                'reason'      => 'Compliance hold',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode);
        $this->assertNotNull($fresh->suspended_by, 'Attribution must still be stamped.');
        $this->assertSame('Compliance hold', $fresh->suspension_reason);
        $this->assertSame('admin_suspended', $fresh->accessClassification());
    }

    // ════════════════════════════════════════════════════════════════
    // `suspended` is NOT self-evidently administrative
    // ════════════════════════════════════════════════════════════════

    /**
     * The shape CheckSubscriptionExpiry::applyShopModeUnderLock() writes on a
     * grace-period lapse: access_mode=suspended, a 'Subscription…' reason, and
     * NO suspended_by (the scheduler never stamps an actor). EnsureAccountIsActive
     * already sends these owners to the plan picker, so the admin screen must not
     * call it an administrator hold.
     */
    public function test_scheduler_created_subscription_suspension_reads_as_an_expiry(): void
    {
        $shop = $this->createShop('retailer');
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Subscription grace period ended',
            'suspended_by'      => null,
        ])->save();

        $this->assertSame('subscription_lapse', $shop->fresh()->accessClassification());

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Subscription ended')
            ->assertSee('choose a plan to restore access', false)
            ->assertSee('No action is needed here.', false)
            // Neither of the two ways the page could get this wrong.
            ->assertDontSee('deliberate <strong>administrator restriction</strong>', false)
            ->assertDontSee('Unresolved — needs review', false);
    }

    /**
     * Suspended, no actor, and a reason the classifier does not recognise. We
     * cannot say who did it, so it must read as unresolved — never as an invented
     * administrator hold, and never as "None".
     */
    public function test_unattributed_suspension_is_not_presented_as_a_hold_or_as_unrestricted(): void
    {
        $shop = $this->createShop('retailer');
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Manual lockout',
            'suspended_by'      => null,
        ])->save();

        $this->assertSame('unclassified_suspended', $shop->fresh()->accessClassification());

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.show', ['shop' => $shop->id]))
            ->assertOk()
            ->assertSee('Unresolved — needs review', false)
            ->assertSee('no administrator is recorded', false)
            ->assertSee('Audit detail — stored access_mode', false)
            // Not a lapse, so no self-recovery promise; not attributed, so no hold.
            ->assertDontSee('Subscription ended')
            ->assertDontSee('choose a plan to restore access', false)
            ->assertDontSee('deliberate <strong>administrator restriction</strong>', false);
    }

    // ════════════════════════════════════════════════════════════════
    // Dashboard → Recent Tenants
    //
    // The shops index and detail screens were converted to the badge
    // component; the dashboard was missed and kept its own inline echo, so one
    // shop read "Suspended" on /admin and "Subscription ended" on /admin/shops.
    // That is the expensive direction of the error: "Suspended" is the state
    // whose operator response is "deliberate, leave it alone", so a customer
    // who can already self-recover was presented as a closed case on the
    // landing screen.
    //
    // The assertions lean on a real distinction in the markup rather than a
    // lucky one: the dashboard's KPI card, status chip and alert table all
    // spell the mode "Read-Only" (hyphen), while the BADGE label is "Read Only"
    // (space). An assertion on the spaced form therefore sees the badge and
    // nothing else. If a future edit re-spells that static furniture these
    // tests go red, rather than quietly stopping to test anything.
    //
    // Scope note: $stats['shops_read_only'] and $readOnlyShops still select on
    // access_mode alone, so the KPI card and the alert table continue to count
    // lapses as administrator holds. That is tracked separately — it needs the
    // subscription joins, not a label swap, and is deliberately NOT pinned here.
    // ════════════════════════════════════════════════════════════════

    public function test_dashboard_recent_tenants_shows_subscription_ended_for_a_lapse(): void
    {
        $shop = $this->lapsedShop();

        $this->assertSame('subscription_lapse', $shop->fresh()->accessClassification());

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee($shop->name)
            ->assertSee('Subscription ended')
            // The inline echo this replaced rendered the badge label "Read Only"
            // here. The hyphenated static furniture is untouched.
            ->assertDontSee('Read Only');
    }

    public function test_dashboard_recent_tenants_keeps_read_only_for_an_admin_hold(): void
    {
        $shop = $this->adminHeldShop($this->makeAdmin('holder'));

        $this->assertSame('admin_read_only', $shop->fresh()->accessClassification());

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee($shop->name)
            ->assertSee('Read Only')
            ->assertDontSee('Subscription ended');
    }

    public function test_dashboard_recent_tenants_agrees_with_the_shops_list_for_the_same_shop(): void
    {
        $shop = $this->lapsedShop();

        // The bug was not "the dashboard is wrong" in isolation — it was that
        // two admin screens disagreed about one shop. Pin the agreement.
        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Subscription ended');

        $this->actingAsSuperAdmin()
            ->get(route('admin.shops.index', ['q' => $shop->name]))
            ->assertOk()
            ->assertSee('Subscription ended');
    }

    public function test_dashboard_recent_tenants_labels_an_active_shop_unchanged(): void
    {
        $active = $this->createShop('retailer');
        $active->forceFill(['access_mode' => 'active', 'is_active' => true])->save();

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee($active->name)
            ->assertSee('Active');
    }
}
