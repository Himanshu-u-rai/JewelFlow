<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 OPUS-AUDIT CORRECTION — the legacy read-only rule must be CORROBORATED.
 *
 * The first cut of the JF-0001 correction classified a shop as a legacy
 * subscription lapse on the ACCESS axis alone:
 *
 *     access_mode === 'read_only' && suspended_by === null   →  "legacy lapse"
 *
 * That is too wide, and dangerously so. `platform.enforce_subscriptions`
 * defaults to FALSE, and on that path EnsureSubscriptionIsActive calls
 * restoreIfSubscriptionManagedSuspension(), which force-fills the row back to
 * access_mode = 'active'. So the wide rule silently HEALED every unstamped
 * read-only shop into a fully writable one — destroying the read-only ERP
 * contract that Masters/Historical rely on, and lifting any administrator hold
 * old enough to predate suspended_by stamping.
 *
 * The incident state the audit actually described has FOUR components, not
 * three; the fourth is the corroborating subscription row:
 *
 *     access_mode = read_only
 *     suspended_by = NULL
 *     suspension_reason = NULL
 *     subscription status = read_only      ← the corroboration
 *
 * Only the conjunction is the incident. `read_only` access with no lapsed
 * subscription behind it is an ordinary read-only hold and must keep behaving
 * like one: reads pass, writes are refused, and nothing heals it implicitly.
 *
 * Everything below is proved through the real middleware boundary.
 */
class LegacyReadOnlyCorroborationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();

        Route::middleware(['web', 'auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])
            ->group(function () {
                Route::get('/_ro/read', fn () => response('ok'))->name('ro.read');
                Route::post('/_ro/write', fn () => response('ok'))->name('ro.write');
            });
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

    /**
     * Exactly how every pre-existing read-only fixture in this codebase is
     * built (Masters\CategoryAccessTest, Masters\KarigarLifecycleTest,
     * Masters\PurityMastersAccessTest, Masters\ProductRetailerBoundaryTest,
     * Historical\HistoricalModuleHttpTest): the access flag and nothing else.
     */
    private function bareReadOnly(Shop $shop): void
    {
        $shop->forceFill(['access_mode' => 'read_only'])->save();
    }

    // ════════════════════════════════════════════════════════════════════
    // The regression: an uncorroborated read-only row must NOT be healed
    // ════════════════════════════════════════════════════════════════════

    public function test_bare_read_only_with_no_subscription_row_is_not_healed_to_active(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop] = $this->tenant();
        $this->bareReadOnly($shop);

        $this->actingAs($user)->get('/_ro/read')->assertOk();

        $this->assertSame(
            'read_only',
            $shop->fresh()->access_mode,
            'An uncorroborated read-only hold must survive a request; healing it hands the shop write access.'
        );
    }

    public function test_bare_read_only_with_no_subscription_row_still_refuses_writes(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop] = $this->tenant();
        $this->bareReadOnly($shop);

        // EnsureAccountIsActive::deny() bounces a web write back with an error
        // bag rather than rendering 423 — the handler must simply never run.
        $this->actingAs($user)
            ->from('/_ro/read')
            ->post('/_ro/write')
            ->assertRedirect('/_ro/read')
            ->assertSessionHasErrors('shop');

        $this->assertSame('read_only', $shop->fresh()->access_mode);
    }

    /** The same refusal, on the JSON surface, where the status code is explicit. */
    public function test_bare_read_only_with_no_subscription_row_refuses_json_writes_with_423(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop] = $this->tenant();
        $this->bareReadOnly($shop);

        $this->actingAs($user)
            ->postJson('/_ro/write')
            ->assertStatus(Response::HTTP_LOCKED);

        $this->assertSame('read_only', $shop->fresh()->access_mode);
    }

    public function test_bare_read_only_backed_by_a_healthy_subscription_still_refuses_writes(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();
        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => now()->subMonth()->toDateString(),
            'ends_at'   => now()->addMonth()->toDateString(),
        ]);
        $this->bareReadOnly($shop);

        $this->actingAs($user)
            ->postJson('/_ro/write')
            ->assertStatus(Response::HTTP_LOCKED);

        $this->assertSame('read_only', $shop->fresh()->access_mode);
    }

    public function test_the_classifier_refuses_an_uncorroborated_read_only_row(): void
    {
        [, $shop] = $this->tenant();
        $this->bareReadOnly($shop);

        $this->assertFalse(
            $shop->fresh()->suspensionIsSubscriptionManaged(),
            'read_only access alone is not evidence of a subscription lapse.'
        );
    }

    // ════════════════════════════════════════════════════════════════════
    // …but the genuine JF-0001 row must still be recognised and healed
    // ════════════════════════════════════════════════════════════════════

    private function legacyIncident(Shop $shop, Plan $plan): void
    {
        ShopSubscription::create([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'read_only',
            'starts_at' => now()->subMonths(14)->toDateString(),
            'ends_at'   => now()->subDays(20)->toDateString(),
        ]);

        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => false,
            'suspended_at'      => now()->subDays(3),
            'suspension_reason' => null,
            'suspended_by'      => null,
            'suspended_until'   => null,
        ])->save();
    }

    public function test_the_corroborated_legacy_incident_is_still_classified_as_a_lapse(): void
    {
        [, $shop, $plan] = $this->tenant();
        $this->legacyIncident($shop, $plan);

        $this->assertTrue(
            $shop->fresh()->suspensionIsSubscriptionManaged(),
            'The four-component incident row must stay owner-recoverable.'
        );
    }

    public function test_the_corroborated_legacy_incident_is_still_healed_when_enforcement_is_off(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();
        $this->legacyIncident($shop, $plan);

        $this->actingAs($user)->get('/_ro/read')->assertOk();

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode, 'A lapse must never lock ERP while enforcement is off.');
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspended_by);
    }

    // ════════════════════════════════════════════════════════════════════
    // Control: a stamped administrator hold is untouched either way
    // ════════════════════════════════════════════════════════════════════

    public function test_an_administrator_stamped_read_only_hold_is_never_healed(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, , $admin] = $this->tenant();
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => true,
            'suspended_at'      => now(),
            'suspension_reason' => 'Compliance review by platform admin',
            'suspended_by'      => $admin->id,
            'suspended_until'   => null,
        ])->save();

        $this->actingAs($user)->get('/_ro/read')->assertOk();

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertSame($admin->id, $fresh->suspended_by);
    }
}
