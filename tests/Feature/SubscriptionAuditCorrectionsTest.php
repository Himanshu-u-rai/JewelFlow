<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 OPUS-AUDIT CORRECTIONS — groups 1, 2 and 3.
 *
 *   1. The EXACT unattributed legacy incident state
 *        access_mode=read_only, suspended_by=NULL, suspension_reason=NULL,
 *        subscription status=read_only
 *      under BOTH enforcement values.
 *
 *   2. A stale administrative marker: a time-boxed admin suspension whose
 *      suspended_until has passed must restore CLEANLY — including suspended_by —
 *      or the shop is permanently classified administrative and can never renew.
 *
 *   3. RepairTrialTermSubscriptions must never lift an administrator hold, and
 *      its row lock must be transaction-scoped to mean anything.
 *
 * Everything is proved through the real middleware boundary, not by calling the
 * classifier directly, because the classifier is exactly what is being corrected.
 */
class SubscriptionAuditCorrectionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();

        // Full access boundary — proves what an ERP request actually gets.
        Route::middleware(['web', 'auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])
            ->group(function () {
                Route::get('/_ac/erp-read', fn () => response('ok'))->name('ac.erp.read');
                Route::post('/_ac/erp-write', fn () => response('ok'))->name('ac.erp.write');
            });

        Route::middleware(['api', 'auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])
            ->group(function () {
                Route::get('/api/_ac/erp-read', fn () => response()->json(['ok' => true]));
            });

        // account.active ALONE. The commerce/Dhiran route groups carry exactly
        // this stack, and it is the only place EnsureAccountIsActive's expired
        // time-box auto-clear is reachable (for the full ERP stack,
        // EnsureSubscriptionIsActive denies an administrative suspension first).
        Route::middleware(['web', 'auth', 'tenant', 'account.active'])
            ->group(function () {
                Route::get('/_ac/acct-read', fn () => response('ok'))->name('ac.acct.read');
            });
    }

    // ────────────────────────────────────────────────────────────────────────
    // Fixtures
    // ────────────────────────────────────────────────────────────────────────

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

    private function subscription(Shop $shop, Plan $plan, array $attrs): ShopSubscription
    {
        return ShopSubscription::create(array_merge([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at'   => now()->addMonth()->toDateString(),
        ], $attrs));
    }

    /**
     * THE INCIDENT STATE, reproduced exactly as production carries it.
     *
     * The buggy expiry fork wrote access_mode=read_only and left EVERY
     * attribution field null — no admin id, and (in the oldest rows) no reason
     * text either. That unattributed shape is the whole point of this fixture;
     * adding a suspension_reason would launder it into a state the current
     * classifier already recognises and would prove nothing.
     */
    private function lockAsUnattributedLegacyReadOnly(Shop $shop): void
    {
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now()->subDays(3),
            'suspension_reason' => null,
            'suspended_by'      => null,
            'suspended_until'   => null,
        ])->save();
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
    // GROUP 1 — exact unattributed legacy incident state
    // ════════════════════════════════════════════════════════════════════════

    public function test_enforcement_on_unattributed_legacy_read_only_denies_erp_and_reconciles_to_suspended(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'read_only', 'ends_at' => now()->subDays(20)->toDateString()]);
        $this->lockAsUnattributedLegacyReadOnly($shop);

        $this->actingAs($user)->get('/_ac/erp-read')->assertRedirect(route('subscription.plans'));

        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode, 'Lapse must reconcile onto the entitlement axis, not stay read-only.');
        $this->assertNull($fresh->suspended_by, 'Reconciliation must never fabricate an administrative marker.');
        $this->assertTrue($fresh->suspensionIsSubscriptionManaged(), 'Reconciled lapse must stay owner-recoverable.');
    }

    public function test_enforcement_on_unattributed_legacy_read_only_blocks_writes_with_recovery_not_a_dead_end(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'read_only', 'ends_at' => now()->subDays(20)->toDateString()]);
        $this->lockAsUnattributedLegacyReadOnly($shop);

        $this->actingAs($user)->post('/_ac/erp-write')->assertRedirect(route('subscription.plans'));
        $this->assertTrue(auth()->check(), 'A lapse must never log the owner out.');
    }

    public function test_enforcement_on_unattributed_legacy_read_only_returns_subscription_required_json(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'read_only', 'ends_at' => now()->subDays(20)->toDateString()]);
        $this->lockAsUnattributedLegacyReadOnly($shop);

        $this->actingAs($user)
            ->getJson('/api/_ac/erp-read')
            ->assertStatus(Response::HTTP_FORBIDDEN)
            ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED');
    }

    public function test_enforcement_on_unattributed_legacy_read_only_owner_reaches_working_plans_page(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan] = $this->tenant();
        $sub = $this->subscription($shop, $plan, ['status' => 'read_only', 'ends_at' => now()->subDays(20)->toDateString()]);
        $this->lockAsUnattributedLegacyReadOnly($shop);

        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
        $this->assertFalse(
            ShopSubscription::blocksNewPaidTerm($sub, $shop->fresh()),
            'A legacy read_only row is a lapse, so renewal must be permitted.'
        );
    }

    /**
     * ENFORCEMENT OFF — the deployed default (config/platform.php ships false).
     *
     * The intentional ERP bypass must survive, but the shop must NOT be left
     * wearing read_only: EnsureAccountIsActive bounces every write with a 423
     * that carries no recovery path at all. That is the exact JF-0001 trap.
     */
    public function test_enforcement_off_unattributed_legacy_read_only_is_healed_not_trapped_behind_423(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'read_only', 'ends_at' => now()->subDays(20)->toDateString()]);
        $this->lockAsUnattributedLegacyReadOnly($shop);

        $this->actingAs($user)->get('/_ac/erp-read')->assertOk()->assertSee('ok');

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode, 'Enforcement off: a lapse must never hold ERP access.');
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspension_reason);
        $this->assertNull($fresh->suspended_by);

        $this->actingAs($user)->post('/_ac/erp-write')
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_enforcement_off_unattributed_legacy_read_only_owner_can_reach_renewal(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();
        $sub = $this->subscription($shop, $plan, ['status' => 'read_only', 'ends_at' => now()->subDays(20)->toDateString()]);
        $this->lockAsUnattributedLegacyReadOnly($shop);

        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
        $this->assertFalse(ShopSubscription::blocksNewPaidTerm($sub, $shop->fresh()));
    }

    /** The correction must not touch a genuine administrator read-only hold. */
    public function test_enforcement_off_administrative_read_only_is_untouched(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'active']);
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        // Read-only browsing survives …
        $this->actingAs($user)->get('/_ac/erp-read')->assertOk();
        // … writes do not.
        $this->actingAs($user)->post('/_ac/erp-write')->assertSessionHasErrors('message');

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertSame($admin->id, $fresh->suspended_by);
        $this->assertFalse($fresh->suspensionIsSubscriptionManaged());
    }

    public function test_enforcement_on_administrative_read_only_is_untouched(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'active']);
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->actingAs($user)->get('/_ac/erp-read')->assertOk();
        $this->actingAs($user)->post('/_ac/erp-write')->assertSessionHasErrors('message');

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertSame($admin->id, $fresh->suspended_by);
    }

    // ════════════════════════════════════════════════════════════════════════
    // GROUP 2 — stale administrative marker
    // ════════════════════════════════════════════════════════════════════════

    /**
     * A time-boxed admin suspension that has run out restores access — but the
     * restore payload never nulls suspended_by, so suspensionIsAdministrative()
     * stays true forever. That single stale integer then blocks every purchase
     * entry point through ShopSubscription::blocksNewPaidTerm(), and freezes the
     * subscription reconciler (which refuses to touch an administrative row).
     */
    public function test_expired_time_boxed_admin_suspension_clears_every_field_including_suspended_by(): void
    {
        [$user, $shop, $plan, $admin] = $this->tenant();
        // Lapsed term, so "can this shop buy a new one?" is answered by the
        // administrative axis alone — a live term would legitimately block a
        // duplicate purchase and mask the stale-marker bug.
        $sub = $this->subscription($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'deactivated_at'    => now()->subDays(10),
            'suspended_at'      => now()->subDays(10),
            'suspension_reason' => 'Temporary compliance hold',
            'suspended_by'      => $admin->id,
            'suspended_until'   => now()->subDay(),
        ])->save();

        $this->actingAs($user)->get('/_ac/acct-read')->assertOk();

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspended_at);
        $this->assertNull($fresh->suspension_reason);
        $this->assertNull($fresh->suspended_until);
        $this->assertNull($fresh->suspended_by, 'An expired hold that no longer restricts anything must not keep its actor stamp.');
        $this->assertFalse($fresh->suspensionIsAdministrative());

        // Renewal eligibility is the thing the stale stamp actually destroyed.
        $this->assertFalse(ShopSubscription::blocksNewPaidTerm($sub->fresh(), $fresh));
    }

    public function test_restored_shop_regains_erp_and_plans_access(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDays(10),
            'suspension_reason' => 'Temporary compliance hold',
            'suspended_by'      => $admin->id,
            'suspended_until'   => now()->subDay(),
        ])->save();

        $this->actingAs($user)->get('/_ac/acct-read')->assertOk();
        $this->assertNull($shop->fresh()->suspended_by);

        // Expired entitlement now routes to renewal instead of the admin dead end.
        $this->actingAs($user)->get('/_ac/erp-read')->assertRedirect(route('subscription.plans'));
        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
    }

    /** A PERMANENT administrator hold has no suspended_until and must survive. */
    public function test_permanent_admin_suspension_is_never_cleared(): void
    {
        [$user, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'active']);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDays(10),
            'suspension_reason' => 'Fraud investigation',
            'suspended_by'      => $admin->id,
            'suspended_until'   => null,
        ])->save();

        $this->actingAs($user)->get('/_ac/acct-read')->assertRedirect('/login');

        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode);
        $this->assertSame($admin->id, $fresh->suspended_by);
        $this->assertTrue($fresh->suspensionIsAdministrative());
    }

    /** A still-running time-boxed hold must also survive. */
    public function test_unexpired_time_boxed_admin_suspension_is_never_cleared(): void
    {
        [$user, $shop, $plan, $admin] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'active']);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Temporary compliance hold',
            'suspended_by'      => $admin->id,
            'suspended_until'   => now()->addDays(5),
        ])->save();

        $this->actingAs($user)->get('/_ac/acct-read')->assertRedirect('/login');
        $this->assertSame($admin->id, $shop->fresh()->suspended_by);
    }

    /**
     * The OTHER two restore payloads changed by this correction:
     * EnsureSubscriptionIsActive::modeUpdates('active') and
     * ::restoreIfSubscriptionManagedSuspension(). Both are reachable only for a
     * NON-administrative row, so the invariant they must uphold is that a
     * restore leaves suspended_by null — never resurrects one.
     */
    public function test_subscription_managed_restore_paths_leave_no_administrative_marker(): void
    {
        // modeUpdates('active') is reachable only for a shop wearing a NON-suspended
        // restricted mode with a healthy subscription — i.e. exactly the legacy
        // unattributed read_only row. (An access_mode of `suspended` is answered by
        // the recovery branch higher up and never reaches the reconciler.)
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan] = $this->tenant();
        $this->subscription($shop, $plan, ['status' => 'active']);
        $this->lockAsUnattributedLegacyReadOnly($shop);

        $this->actingAs($user)->get('/_ac/erp-read')->assertOk();
        $this->assertSame('active', $shop->fresh()->access_mode);
        $this->assertNull($shop->fresh()->suspended_by);

        // restoreIfSubscriptionManagedSuspension(): enforcement OFF.
        config(['platform.enforce_subscriptions' => false]);
        [$user2, $shop2, $plan2] = $this->tenant();
        $this->subscription($shop2, $plan2, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);
        $shop2->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Subscription expired',
            'suspended_by'      => null,
        ])->save();

        $this->actingAs($user2)->get('/_ac/erp-read')->assertOk();
        $this->assertSame('active', $shop2->fresh()->access_mode);
        $this->assertNull($shop2->fresh()->suspended_by);
    }

    // ════════════════════════════════════════════════════════════════════════
    // GROUP 3 — RepairTrialTermSubscriptions
    // ════════════════════════════════════════════════════════════════════════

    /** A paid yearly term wrongly shrunk to a trial window — the repair target. */
    private function shrunkPaidSubscription(Shop $shop, Plan $plan): ShopSubscription
    {
        $startsAt = now()->subDays(3);

        return $this->subscription($shop, $plan, [
            'status'              => 'expired',
            'billing_cycle'       => 'yearly',
            'starts_at'           => $startsAt->toDateString(),
            'ends_at'             => $startsAt->copy()->addDays(7)->toDateString(),
            'grace_ends_at'       => $startsAt->copy()->addDays(12)->toDateString(),
            'price_paid'          => 9999,
            'razorpay_payment_id' => 'pay_' . fake()->unique()->numerify('##########'),
        ]);
    }

    public function test_repair_restores_a_genuinely_subscription_managed_shop(): void
    {
        [, $shop, $plan] = $this->tenant();
        $sub = $this->shrunkPaidSubscription($shop, $plan);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Subscription expired',
            'suspended_by'      => null,
        ])->save();

        $this->artisan('subscription:repair-trial-terms', ['--commit' => true])->assertSuccessful();

        $this->assertSame('active', $sub->fresh()->status);
        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode);
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspension_reason);
    }

    public function test_repair_never_lifts_an_administrator_suspension(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->shrunkPaidSubscription($shop, $plan);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => 'Fraud investigation',
            'suspended_by'      => $admin->id,
        ])->save();

        $this->artisan('subscription:repair-trial-terms', ['--commit' => true])->assertSuccessful();

        // The entitlement is repaired …
        $this->assertSame('active', $sub->fresh()->status);
        // … the administrative hold is not touched.
        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode);
        $this->assertFalse((bool) $fresh->is_active);
        $this->assertSame($admin->id, $fresh->suspended_by);
        $this->assertSame('Fraud investigation', $fresh->suspension_reason);
    }

    public function test_repair_never_lifts_an_administrator_read_only_hold(): void
    {
        [, $shop, $plan, $admin] = $this->tenant();
        $sub = $this->shrunkPaidSubscription($shop, $plan);
        $this->lockAsAdministrativeReadOnly($shop, $admin);

        $this->artisan('subscription:repair-trial-terms', ['--commit' => true])->assertSuccessful();

        $this->assertSame('active', $sub->fresh()->status);
        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertSame($admin->id, $fresh->suspended_by);
        $this->assertSame('Compliance review by platform admin', $fresh->suspension_reason);
    }

    /**
     * A lock outside a transaction is released the instant the statement ends,
     * so it guards nothing. Assert the SELECT … FOR UPDATE actually runs with an
     * open transaction — the only property that makes the lock real.
     */
    public function test_repair_row_lock_is_transaction_scoped(): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->shrunkPaidSubscription($shop, $plan);

        $shop->forceFill([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspension_reason' => 'Subscription expired',
            'suspended_by'      => null,
        ])->save();

        $lockedInsideTransaction = false;
        $sawLock = false;

        DB::listen(function ($query) use (&$sawLock, &$lockedInsideTransaction) {
            if (stripos($query->sql, 'for update') === false) {
                return;
            }
            $sawLock = true;
            if (DB::transactionLevel() > 0) {
                $lockedInsideTransaction = true;
            }
        });

        $this->artisan('subscription:repair-trial-terms', ['--commit' => true])->assertSuccessful();

        $this->assertTrue($sawLock, 'The repair must take a row lock before mutating shop access.');
        $this->assertTrue($lockedInsideTransaction, 'A row lock outside a transaction is released immediately and guards nothing.');
    }
}
