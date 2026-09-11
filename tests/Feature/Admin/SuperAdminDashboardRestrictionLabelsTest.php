<?php

namespace Tests\Feature\Admin;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Guards the Super Admin dashboard's RESTRICTION SUMMARY: the read-only KPI card
 * and the two alert tables. Sibling of SuperAdminAccessModeBadgeTest, which pins
 * the per-shop access-mode badge; this file pins the two things that badge work
 * deliberately left open.
 *
 * Two separate defects, both on /admin:
 *
 * 1. THE REASON COLUMN ALWAYS RENDERED "—".  Both alert tables were built with
 *    Eloquent's get(['col', ...]), which restricts the SELECT list. An unselected
 *    column reads back as null with NO error, so suspension_reason — the whole
 *    point of a column headed "Reason" — was silently absent on every row. A
 *    compliance hold and a blank reason looked identical.
 *
 * 2. THE READ-ONLY KPI WAS ONE UNDIFFERENTIATED NUMBER described as "Writes
 *    blocked". It merged two situations an admin must answer differently: a shop
 *    an admin deliberately held (NOT self-recoverable — the owner cannot buy
 *    their way out) and a shop restricted with no administrative attribution at
 *    all (the JF-0001 legacy shape).
 *
 * The distinction is read off Shop::suspensionIsAdministrative() and
 * Shop::suspensionIsSubscriptionManaged() — the same classifiers the session and
 * subscription middlewares act on — rather than re-derived from a second SQL
 * predicate, so the dashboard cannot drift away from the owner's real experience.
 */
class SuperAdminDashboardRestrictionLabelsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeAdmin(string $prefix = 'labels'): PlatformAdmin
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
     * createShop() names every shop "Test Shop", so a per-row assertion needs a
     * distinguishable name or it could pass on a different shop's row.
     */
    private function namedShop(string $name): Shop
    {
        $shop = $this->createShop('retailer');
        $shop->forceFill(['name' => $name])->save();

        return $shop;
    }

    /** A deliberate administrator hold: an actor is recorded on the row. */
    private function adminHeldShop(string $name, string $reason): Shop
    {
        $shop = $this->namedShop($name);

        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => $reason,
            'suspended_by'      => $this->makeAdmin('holder')->id,
        ])->save();

        return $shop;
    }

    /**
     * The JF-0001 shape: read_only on the shop, a corroborating read_only
     * subscription row, no live term, and no administrative attribution.
     */
    private function lapsedShop(string $name): Shop
    {
        $plan = $this->createPlan('retailer');
        $shop = $this->namedShop($name);

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

    // ════════════════════════════════════════════════════════════════
    // Defect 1 — the Reason column
    // ════════════════════════════════════════════════════════════════

    public function test_read_only_table_renders_the_stored_suspension_reason(): void
    {
        $this->adminHeldShop('Readonly Reason Shop', 'Compliance hold pending KYC docs');

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Readonly Reason Shop')
            // Pre-fix this row rendered the em-dash fallback: suspension_reason was
            // never in the SELECT list, so it read back as null.
            ->assertSee('Compliance hold pending KYC docs');
    }

    public function test_suspended_table_renders_the_stored_suspension_reason(): void
    {
        $shop = $this->namedShop('Suspended Reason Shop');
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Chargeback investigation open',
            'suspended_by'      => $this->makeAdmin('suspender')->id,
        ])->save();

        // The sibling table had the identical missing-column bug. Both fixed.
        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Suspended Reason Shop')
            ->assertSee('Chargeback investigation open');
    }

    // ════════════════════════════════════════════════════════════════
    // Defect 2 — the read-only KPI split
    // ════════════════════════════════════════════════════════════════

    public function test_read_only_kpi_splits_admin_holds_from_unattributed_shops(): void
    {
        $this->adminHeldShop('Kpi Held Shop', 'Compliance hold');
        $this->lapsedShop('Kpi Lapsed Shop');

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            // Total unchanged; the description is what carries the split.
            ->assertSee('1 admin hold · 1 unattributed');
    }

    public function test_read_only_kpi_counts_only_read_only_shops(): void
    {
        $this->adminHeldShop('Kpi Held Shop', 'Compliance hold');

        // A suspended shop is a different KPI card. If the split predicate ever
        // drops its access_mode filter this goes red instead of quietly inflating.
        $suspended = $this->namedShop('Kpi Suspended Shop');
        $suspended->forceFill([
            'access_mode'  => 'suspended',
            'is_active'    => false,
            'suspended_by' => $this->makeAdmin('other')->id,
        ])->save();

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('1 admin hold · 0 unattributed');
    }

    // ════════════════════════════════════════════════════════════════
    // The per-row Cause column
    //
    // The KPI is deliberately coarse — it splits on suspended_by alone, which
    // needs no joins. The table is where the real classifier runs, so a lapse and
    // an unattributed legacy row are told apart here and nowhere else.
    // ════════════════════════════════════════════════════════════════

    public function test_read_only_table_marks_an_administrative_hold(): void
    {
        $shop = $this->adminHeldShop('Cause Held Shop', 'Compliance hold');

        $this->assertTrue($shop->fresh()->suspensionIsAdministrative());

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Cause Held Shop')
            ->assertSee('Admin hold')
            ->assertDontSee('Subscription lapse');
    }

    public function test_read_only_table_marks_a_subscription_lapse(): void
    {
        $shop = $this->lapsedShop('Cause Lapsed Shop');

        $this->assertTrue($shop->fresh()->suspensionIsSubscriptionManaged());

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Cause Lapsed Shop')
            ->assertSee('Subscription lapse')
            // The expensive direction of the error: a lapse presented as a hold
            // reads as "deliberate, leave it alone" for a shop that can already
            // recover unaided.
            ->assertDontSee('Admin hold');
    }

    public function test_read_only_table_marks_an_unattributed_shop_as_unattributed(): void
    {
        // read_only, no actor, and no corroborating subscription row — the
        // classifier fails closed, so the dashboard must not invent an attribution.
        $shop = $this->namedShop('Cause Unknown Shop');
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Manual lockout',
            'suspended_by'      => null,
        ])->save();

        $shop = $shop->fresh();
        $this->assertFalse($shop->suspensionIsAdministrative());
        $this->assertFalse($shop->suspensionIsSubscriptionManaged());

        $this->actingAsSuperAdmin()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Cause Unknown Shop')
            ->assertSee('Unattributed')
            ->assertSee('Manual lockout')
            ->assertDontSee('Admin hold')
            ->assertDontSee('Subscription lapse');
    }
}
