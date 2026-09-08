<?php

namespace Tests\Feature\Admin;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Guards shop:withdraw-admin-restriction — the correction for an administrative
 * restriction that should never have been applied.
 *
 * The command has exactly one job and one column: it clears shops.suspended_by
 * so the shop returns to the entitlement axis, and it must NOT widen access
 * while doing so. Both halves are asserted: the shop stays suspended, and the
 * owner regains the ability to buy a plan.
 *
 * Everything else here is a REFUSAL test. This command writes to production
 * data and its guards are the whole safety argument, so each one is pinned
 * individually — a guard that silently stops firing is indistinguishable from a
 * guard that was never there.
 */
class WithdrawAdminRestrictionCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();

        // The command refuses outright unless enforcement is on; production runs
        // with PLATFORM_ENFORCE_SUBSCRIPTIONS=true and the refusal has its own
        // test below.
        config()->set('platform.enforce_subscriptions', true);
    }

    private function makeAdmin(): PlatformAdmin
    {
        return PlatformAdmin::create([
            'first_name' => 'Withdraw', 'last_name' => 'Test', 'name' => 'Withdraw Test',
            'email' => 'withdraw' . random_int(1000, 999999) . '@example.com',
            'mobile_number' => '9' . random_int(100000000, 999999999),
            'password' => Hash::make('password'),
            'role' => 'super_admin', 'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Shop #7's production shape: a lapsed term, an administrator stamp, and the
     * legacy "Subscription read_only" reason string the old pre-filled form left
     * behind. The reason already corroborates a lapse — suspended_by is the ONLY
     * thing making this an administrative hold.
     */
    private function mistakenlyHeldShop(?PlatformAdmin $admin = null, string $reason = 'Subscription read_only'): Shop
    {
        $plan = $this->createPlan('retailer');
        $shop = $this->createShop('retailer');

        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'expired',
            'starts_at' => now()->subMonths(14)->toDateString(),
            'ends_at'   => now()->subDays(30)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subHours(3),
            'suspension_reason' => $reason,
            'suspended_by'      => ($admin ?? $this->makeAdmin())->id,
        ])->save();

        return $shop->fresh();
    }

    private function latestSubscription(Shop $shop): ?ShopSubscription
    {
        return ShopSubscription::where('shop_id', $shop->id)->latest('id')->first();
    }

    // ════════════════════════════════════════════════════════════════
    // The correction itself
    // ════════════════════════════════════════════════════════════════

    public function test_it_withdraws_the_attribution_and_restores_the_owners_purchase_path(): void
    {
        $shop = $this->mistakenlyHeldShop();

        // Precondition, asserted rather than assumed: the hold is what blocks
        // the purchase. Without this the success assertion below could pass on a
        // shop that was never blocked in the first place.
        $this->assertTrue(ShopSubscription::blocksNewPaidTerm($this->latestSubscription($shop), $shop));
        $this->assertSame('admin_suspended', $shop->accessClassification());

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'      => $shop->id,
            '--reason'  => 'Applied in error while trying to clear a read-only state',
            '--commit'  => true,
        ])->assertExitCode(0);

        $shop = $shop->fresh();

        $this->assertNull($shop->suspended_by);
        $this->assertFalse(ShopSubscription::blocksNewPaidTerm($this->latestSubscription($shop), $shop));
        $this->assertSame('subscription_lapse', $shop->accessClassification());
    }

    public function test_it_does_not_grant_any_access_the_subscription_has_not_paid_for(): void
    {
        $shop = $this->mistakenlyHeldShop();

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'     => $shop->id,
            '--reason' => 'Applied in error',
            '--commit' => true,
        ])->assertExitCode(0);

        $shop = $shop->fresh();

        // The restriction is UNCHANGED. Only the attribution moved.
        $this->assertSame('suspended', $shop->access_mode);
        $this->assertFalse((bool) $shop->is_active);
        $this->assertFalse(ShopSubscription::entitlesAccessToday($shop));
        // The lapse is still on record; the term was not extended or altered.
        $this->assertSame('expired', $this->latestSubscription($shop)->status);
    }

    public function test_it_records_the_correction_in_the_platform_audit_log(): void
    {
        $shop = $this->mistakenlyHeldShop();
        $actorId = $shop->suspended_by;

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'     => $shop->id,
            '--reason' => 'Owner-confirmed misclick',
            '--commit' => true,
        ])->assertExitCode(0);

        $entry = \App\Models\Platform\PlatformAuditLog::query()
            ->where('action', 'shop.admin_restriction_withdrawn')
            ->where('target_id', $shop->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($entry, 'The correction must leave an audit record.');
        $this->assertSame('Owner-confirmed misclick', $entry->reason);
        // The withdrawn actor survives in the before-state — the record of who
        // imposed the restriction is preserved even though the column is cleared.
        $this->assertSame($actorId, $entry->before['suspended_by']);
        $this->assertNull($entry->after['suspended_by']);
    }

    // ════════════════════════════════════════════════════════════════
    // Refusals
    // ════════════════════════════════════════════════════════════════

    public function test_a_dry_run_writes_nothing(): void
    {
        $shop = $this->mistakenlyHeldShop();
        $actorId = $shop->suspended_by;

        $this->artisan('shop:withdraw-admin-restriction', ['shop' => $shop->id])
            ->assertExitCode(0);

        $this->assertSame($actorId, $shop->fresh()->suspended_by);
    }

    public function test_it_refuses_when_the_shop_was_modified_after_it_was_inspected(): void
    {
        $shop = $this->mistakenlyHeldShop();

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'               => $shop->id,
            '--reason'           => 'Applied in error',
            '--expect-updated-at' => (string) now()->subYear(),
            '--commit'           => true,
        ])->assertExitCode(1);

        $this->assertNotNull($shop->fresh()->suspended_by);
    }

    public function test_it_refuses_when_enforcement_is_off_because_the_shop_would_auto_restore(): void
    {
        config()->set('platform.enforce_subscriptions', false);
        $shop = $this->mistakenlyHeldShop();

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'     => $shop->id,
            '--reason' => 'Applied in error',
            '--commit' => true,
        ])->assertExitCode(1);

        $this->assertNotNull($shop->fresh()->suspended_by);
    }

    public function test_it_refuses_a_hold_whose_reason_does_not_corroborate_a_lapse(): void
    {
        // A genuine administrative hold. Withdrawing the attribution would leave
        // it unattributed-and-unexplained, so the command must not touch it —
        // and must not invent a "Subscription …" reason to make it fit.
        $shop = $this->mistakenlyHeldShop(reason: 'Compliance hold — fraud review');

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'     => $shop->id,
            '--reason' => 'Applied in error',
            '--commit' => true,
        ])->assertExitCode(1);

        $shop = $shop->fresh();
        $this->assertNotNull($shop->suspended_by);
        $this->assertSame('Compliance hold — fraud review', $shop->suspension_reason);
    }

    public function test_it_refuses_a_shop_that_is_not_administratively_held(): void
    {
        $shop = $this->mistakenlyHeldShop();
        $shop->forceFill(['suspended_by' => null])->save();

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'     => $shop->id,
            '--reason' => 'Applied in error',
            '--commit' => true,
        ])->assertExitCode(1);
    }

    public function test_it_refuses_a_shop_whose_term_still_covers_today(): void
    {
        $shop = $this->mistakenlyHeldShop();
        $this->latestSubscription($shop)->update([
            'status'  => 'active',
            'ends_at' => now()->addMonths(3)->toDateString(),
        ]);

        $this->artisan('shop:withdraw-admin-restriction', [
            'shop'     => $shop->id,
            '--reason' => 'Applied in error',
            '--commit' => true,
        ])->assertExitCode(1);

        $this->assertNotNull($shop->fresh()->suspended_by);
    }
}
