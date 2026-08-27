<?php

namespace Tests\Feature;

use App\Console\Commands\RepairTrialTermSubscriptions;
use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 — SUBSCRIPTION EXPIRY MUST LOCK THE SHOP OUT, NEVER DOWNGRADE IT TO
 * ADMINISTRATIVE READ-ONLY.
 *
 * Two orthogonal axes are conflated by the pre-fix code through a single
 * `read_only` token:
 *
 *   subscription entitlement : trial | active | grace | expired | cancelled
 *   administrative access    : active | read_only | suspended
 *
 * `read_only` is reserved EXCLUSIVELY for a JewelFlows administrator action.
 * A lapse of the paid/trial term must produce `expired` + a suspended shop,
 * which is a RECOVERABLE state (owner → plan picker, staff → logout message).
 *
 * `suspended_by` is the proof-positive discriminator: every administrative
 * writer stamps it, nothing else in the application ever does.
 */
class SubscriptionExpiryLockoutTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Subscription expiry lockout tests require PostgreSQL.');
        }

        // Enforcement is force-disabled by phpunit.xml. This whole contract is
        // about what happens when the platform DOES enforce subscriptions, so
        // turn it on per-test (same pattern as SubscriptionServiceEnforcementTest).
        config(['platform.enforce_subscriptions' => true]);

        // Control routes carrying the real access boundary but none of the
        // ERP-specific gates (realm / edition / pricing), so a failure here is
        // unambiguously the subscription boundary and nothing else.
        Route::middleware(['web', 'auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])
            ->group(function () {
                Route::get('/_p0/read', fn () => response('ok'))->name('p0.read');
                Route::post('/_p0/write', fn () => response('ok'))->name('p0.write');
            });

        Route::middleware(['api', 'auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])
            ->group(function () {
                Route::get('/api/_p0/read', fn () => response()->json(['ok' => true]));
            });
    }

    // ────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ────────────────────────────────────────────────────────────────────────

    /**
     * A manufacturer tenant whose plan carries downgrade_to_read_only_on_due=true
     * (the trait's default, and what every real seeder writes). That flag is the
     * root cause: it must no longer be able to produce read-only on expiry.
     *
     * @return array{0: User, 1: Shop, 2: Plan, 3: PlatformAdmin}
     */
    private function tenant(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan  = $this->createPlan('manufacturer');
        $shop  = $this->createShop('manufacturer');
        $role  = $this->createOwnerRole($shop->id);
        $user  = $this->createOwnerUser($shop, $role);
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);

        $this->assertTrue(
            (bool) $plan->downgrade_to_read_only_on_due,
            'Fixture guard: the plan must carry downgrade_to_read_only_on_due=true, '
            . 'otherwise this suite cannot prove the flag is inert.'
        );

        return [$user, $shop, $plan, $admin];
    }

    private function subscription(Shop $shop, Plan $plan, PlatformAdmin $admin, array $attrs): ShopSubscription
    {
        return ShopSubscription::create(array_merge([
            'shop_id'             => $shop->id,
            'plan_id'             => $plan->id,
            'status'              => 'active',
            'starts_at'           => now()->subMonths(2)->toDateString(),
            'ends_at'             => now()->addMonth()->toDateString(),
            'grace_ends_at'       => now()->addDays(37)->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ], $attrs));
    }

    /** Put the shop in the state the (fixed) expiry job produces. */
    private function lockAsExpired(Shop $shop): void
    {
        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now(),
            'suspension_reason' => 'Subscription expired',
            'suspended_by'      => null,
        ])->save();
    }

    /** Put the shop in the LEGACY state the buggy expiry job produced. */
    private function lockAsLegacyExpiryReadOnly(Shop $shop): void
    {
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now(),
            'suspension_reason' => 'Subscription read_only',
            'suspended_by'      => null,
        ])->save();
    }

    /** Put the shop under a genuine platform-administrator read-only hold. */
    private function lockAsAdministrativeReadOnly(Shop $shop, PlatformAdmin $admin): void
    {
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => true,
            'suspended_at'      => now(),
            'suspension_reason' => 'Compliance review by platform admin',
            'suspended_by'      => $admin->id,
        ])->save();
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

    // ────────────────────────────────────────────────────────────────────────
    // 1. Scheduler — expiry can never produce read-only
    // ────────────────────────────────────────────────────────────────────────

    public function test_term_end_with_downgrade_flag_set_expires_and_suspends(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $sub = $this->subscription($shop, $plan, $admin, [
            'status'        => 'active',
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => null,
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertSame('suspended', $shop->fresh()->access_mode);
        $this->assertFalse((bool) $shop->fresh()->is_active);
        $this->assertNull($shop->fresh()->suspended_by, 'Expiry must never look administrative.');
    }

    public function test_grace_period_end_with_downgrade_flag_set_expires_and_suspends(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $sub = $this->subscription($shop, $plan, $admin, [
            'status'        => 'grace',
            'ends_at'       => now()->subDays(10)->toDateString(),
            'grace_ends_at' => now()->subDay()->toDateString(),
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('expired', $sub->fresh()->status);
        $this->assertSame('suspended', $shop->fresh()->access_mode);
        $this->assertNull($shop->fresh()->suspended_by);
    }

    public function test_grace_window_keeps_the_shop_fully_active(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $sub = $this->subscription($shop, $plan, $admin, [
            'status'        => 'active',
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->addDays(4)->toDateString(),
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        // Grace is a deliberate, plan-configured entitlement — full ERP access.
        $this->assertSame('grace', $sub->fresh()->status);
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    public function test_expiry_never_overrides_an_administrative_read_only_hold(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $sub = $this->subscription($shop, $plan, $admin, [
            'status'        => 'active',
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => null,
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('expired', $sub->fresh()->status, 'Entitlement still lapses.');
        $this->assertSame('read_only', $shop->fresh()->access_mode, 'Admin hold is untouched.');
        $this->assertSame($admin->id, $shop->fresh()->suspended_by);
    }

    public function test_repair_command_never_resolves_a_lapsed_term_to_read_only(): void
    {
        [, , $plan] = $this->tenant();

        $command = new RepairTrialTermSubscriptions();
        $method  = new \ReflectionMethod($command, 'resolveStatus');
        $method->setAccessible(true);

        $now   = Carbon::now();
        $ended = $now->copy()->subDays(30);
        $grace = $now->copy()->subDays(20);

        [$status, $shopMode] = $method->invoke($command, $now, $ended, $grace, $plan);

        $this->assertSame('expired', $status);
        $this->assertSame('suspended', $shopMode);
    }

    public function test_a_newer_read_only_row_does_not_shield_a_lapsing_row(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();

        $lapsing = $this->subscription($shop, $plan, $admin, [
            'status'        => 'active',
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => null,
        ]);

        // A stale legacy read_only row is NOT a live subscription; it must not
        // suppress the downgrade of the row that actually lapsed.
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'read_only',
            'ends_at'       => now()->subDays(60)->toDateString(),
            'grace_ends_at' => null,
        ]);

        $this->artisan('subscription:check-expiry')->assertExitCode(0);

        $this->assertSame('expired', $lapsing->fresh()->status);
        $this->assertSame('suspended', $shop->fresh()->access_mode);
    }

    // ────────────────────────────────────────────────────────────────────────
    // 2. Access — an expired shop gets no operational page
    // ────────────────────────────────────────────────────────────────────────

    public function test_expired_owner_is_redirected_to_plan_selection(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'expired',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsExpired($shop);

        $this->actingAs($owner)->get('/_p0/read')
            ->assertRedirect(route('subscription.plans', absolute: false));
    }

    public function test_expired_staff_is_logged_out_with_an_owner_renewal_message(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $staff = $this->staffUser($shop);
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'expired',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsExpired($shop);

        $this->actingAs($staff)->get('/_p0/read')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_expired_api_request_gets_the_subscription_required_code(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'expired',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsExpired($shop);

        $this->actingAs($owner)->getJson('/api/_p0/read')
            ->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_legacy_expiry_read_only_locks_out_and_reconciles_to_suspended(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'read_only',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsLegacyExpiryReadOnly($shop);

        // A read (which the buggy contract allowed) must now be refused …
        $this->actingAs($owner)->get('/_p0/read')
            ->assertRedirect(route('subscription.plans', absolute: false));

        // … and the shop row must self-heal onto the correct axis, with the
        // administrative discriminator left NULL.
        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode);
        $this->assertNull($fresh->suspended_by);
        $this->assertStringStartsWith('Subscription', (string) $fresh->suspension_reason);
    }

    public function test_legacy_expiry_read_only_write_is_locked_out_not_merely_refused(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'read_only',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsLegacyExpiryReadOnly($shop);

        // The pre-fix code short-circuits a non-GET into a "read-only mode"
        // validation error and never reconciles the row. It must reach recovery.
        $this->actingAs($owner)->post('/_p0/write')
            ->assertRedirect(route('subscription.plans', absolute: false));

        $this->assertSame('suspended', $shop->fresh()->access_mode);
    }

    /**
     * FOCUSED REGRESSION — subscription reconciliation must never clear an
     * administrator restriction.
     *
     * EnsureSubscriptionIsActive reconciles shops.access_mode whenever the
     * resolved subscription mode disagrees with the stored one. With a healthy
     * subscription the resolver says `active`, so on the FIRST read of any page
     * the reconciler force-fills the shop back to active — silently deleting a
     * JewelFlows administrator's read-only hold (access_mode, suspended_at,
     * suspension_reason all wiped) without an admin ever acting.
     *
     * The locked contract reserves `read_only` exclusively for administrator
     * action and forbids subscription reconciliation or renewal from lifting it.
     * A single GET must therefore leave every administrative field untouched.
     */
    public function test_a_read_never_clears_an_administrative_read_only_hold(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, ['status' => 'active']);
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->actingAs($owner)->get('/_p0/read')->assertOk();

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode, 'Reconciliation must not lift an admin hold.');
        $this->assertSame($admin->id, $fresh->suspended_by, 'The administrative discriminator must survive.');
        $this->assertNotNull($fresh->suspended_at, 'The admin hold timestamp must survive.');
        $this->assertSame('Compliance review by platform admin', $fresh->suspension_reason);
    }

    public function test_administrative_read_only_still_allows_reads_and_blocks_writes(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, ['status' => 'active']);
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        // Reads stay open under an administrator read-only hold …
        $this->actingAs($owner)->get('/_p0/read')->assertOk();
        // … and writes stay blocked, even after a read has passed through.
        $this->actingAs($owner)->post('/_p0/write')->assertSessionHasErrors();

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode, 'The admin hold must survive.');
        $this->assertSame($admin->id, $fresh->suspended_by);
    }

    public function test_enforcement_off_never_locks_out_an_expired_shop(): void
    {
        config(['platform.enforce_subscriptions' => false]);

        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'expired',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsExpired($shop);

        $this->actingAs($owner)->get('/_p0/read')->assertOk();
        $this->assertSame('active', $shop->fresh()->access_mode);
    }

    // ────────────────────────────────────────────────────────────────────────
    // 3. Commerce — one predicate decides who may start a new paid term
    // ────────────────────────────────────────────────────────────────────────

    public function test_expired_shop_may_buy_a_new_term(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan, $admin, [
            'status'        => 'expired',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsExpired($shop);

        $this->assertFalse(ShopSubscription::blocksNewPaidTerm($sub, $shop->fresh()));

        $this->actingAs($owner)->get(route('subscription.plans', absolute: false))->assertOk();
    }

    public function test_active_shop_cannot_buy_a_duplicate_term(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan, $admin, ['status' => 'active']);

        $this->assertTrue(ShopSubscription::blocksNewPaidTerm($sub, $shop));

        $this->actingAs($owner)->get(route('subscription.plans', absolute: false))
            ->assertRedirect(route('subscription.status', absolute: false));
    }

    public function test_grace_shop_cannot_buy_a_duplicate_term(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan, $admin, [
            'status'        => 'grace',
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->addDays(4)->toDateString(),
        ]);

        $this->assertTrue(ShopSubscription::blocksNewPaidTerm($sub, $shop));
    }

    public function test_legacy_expiry_read_only_shop_may_buy(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan, $admin, [
            'status'        => 'read_only',
            'ends_at'       => now()->subDays(40)->toDateString(),
        ]);
        $this->lockAsLegacyExpiryReadOnly($shop);

        $this->assertFalse(
            ShopSubscription::blocksNewPaidTerm($sub, $shop->fresh()),
            'A legacy expiry read-only row is a lapse, not a live paid term — renewal must be possible.'
        );
    }

    public function test_administrative_hold_blocks_a_new_paid_term(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan, $admin, [
            'status'  => 'expired',
            'ends_at' => now()->subDays(40)->toDateString(),
        ]);
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->assertTrue(
            ShopSubscription::blocksNewPaidTerm($sub, $shop->fresh()),
            'Payment must never be able to lift an administrative restriction.'
        );
    }

    public function test_trial_shop_may_still_upgrade_early(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->subscription($shop, $plan, $admin, [
            'status'        => 'trial',
            'starts_at'     => now()->subDays(3)->toDateString(),
            'ends_at'       => now()->addDays(4)->toDateString(),
            'grace_ends_at' => null,
        ]);

        $this->assertFalse(ShopSubscription::blocksNewPaidTerm($sub, $shop));

        $this->actingAs($owner)->get(route('subscription.plans', absolute: false))->assertOk();
    }

    public function test_no_subscription_with_administrative_hold_still_blocks_purchase(): void
    {
        [, $shop, , $admin] = $this->tenant();
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->assertTrue(ShopSubscription::blocksNewPaidTerm(null, $shop->fresh()));
    }

    // ────────────────────────────────────────────────────────────────────────
    // 4. Visibility — no invisible errors, no bounce through a locked route
    // ────────────────────────────────────────────────────────────────────────

    public function test_status_page_renders_for_an_unentitled_owner_without_bouncing(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'expired',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsExpired($shop);

        // Must NOT bounce through settings.edit, which lives inside the ERP
        // middleware group and would immediately reject an unentitled shop.
        $this->actingAs($owner)->get(route('subscription.status', absolute: false))
            ->assertOk()
            ->assertSee('Renew', false);
    }

    public function test_status_page_renders_a_flash_error(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'expired',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsExpired($shop);

        $this->actingAs($owner)
            ->withSession(['error' => 'Refund ref: pay_P0VISIBLE'])
            ->get(route('subscription.status', absolute: false))
            ->assertOk()
            ->assertSee('Refund ref: pay_P0VISIBLE', false);
    }

    public function test_payment_page_renders_a_flash_error(): void
    {
        [$owner, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, $admin, [
            'status'        => 'expired',
            'ends_at'       => now()->subDays(40)->toDateString(),
            'grace_ends_at' => now()->subDays(33)->toDateString(),
        ]);
        $this->lockAsExpired($shop);

        // paymentCallback() redirects every failure back here with a flash the
        // page has never rendered — the money path's errors were invisible.
        $this->actingAs($owner)
            ->withSession([
                'pending_plan_id'       => $plan->id,
                'pending_billing_cycle' => 'monthly',
                'error'                 => 'Payment signature invalid. Contact support with ref: pay_P0INVISIBLE',
            ])
            ->get(route('subscription.payment', absolute: false))
            ->assertOk()
            ->assertSee('pay_P0INVISIBLE', false);
    }
}
