<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformProduct;
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

    // ════════════════════════════════════════════════════════════════════
    // F1 — CORROBORATION MUST BE CURRENT, NOT HISTORICAL
    //
    // The first corroboration used an UNBOUNDED history probe:
    //
    //     $this->subscriptions()->where('status', 'read_only')->exists()
    //
    // which answers "has this shop EVER held a read-only row" — not "is this
    // shop lapsed right now". Any shop that lapsed once and later renewed keeps
    // that row forever, so the probe stayed true for the rest of the shop's
    // life and healed its read-only access to writable `active` on the next
    // request. Every case below pins the difference.
    // ════════════════════════════════════════════════════════════════════

    /** Insertion order is id order, so these helpers control which row is "latest". */
    private function sub(Shop $shop, Plan $plan, string $status, array $overrides = []): ShopSubscription
    {
        return ShopSubscription::create(array_merge([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => $status,
            'starts_at' => now()->subMonths(14)->toDateString(),
            'ends_at'   => now()->subMonths(13)->toDateString(),
        ], $overrides));
    }

    /** The row the buggy expiry fork minted: a lapse wearing `read_only`. */
    private function legacyRow(Shop $shop, Plan $plan): ShopSubscription
    {
        return $this->sub($shop, $plan, 'read_only', [
            'ends_at' => now()->subDays(20)->toDateString(),
        ]);
    }

    /** A term that still covers today. */
    private function liveRow(Shop $shop, Plan $plan, string $status): ShopSubscription
    {
        return $this->sub($shop, $plan, $status, [
            'starts_at'     => now()->subMonth()->toDateString(),
            'ends_at'       => $status === 'grace' ? now()->subDays(3)->toDateString() : now()->addMonth()->toDateString(),
            'grace_ends_at' => $status === 'grace' ? now()->addDays(7)->toDateString() : null,
        ]);
    }

    /**
     * The shared proof for cases 1-6: the shop must survive a request still
     * read-only, and must still refuse writes. Healing any of these hands write
     * access to a shop that an operator (or the ERP contract) deliberately froze.
     */
    private function assertStaysReadOnlyAndUnwritable(User $user, Shop $shop, string $why): void
    {
        $this->assertFalse(
            $shop->fresh()->suspensionIsSubscriptionManaged(),
            $why
        );

        $this->actingAs($user)->get('/_ro/read')->assertOk();
        $this->assertSame('read_only', $shop->fresh()->access_mode, $why);

        $this->actingAs($user)->postJson('/_ro/write')->assertStatus(Response::HTTP_LOCKED);
        $this->assertSame('read_only', $shop->fresh()->access_mode, $why);
    }

    /** CASE 1 — historical read_only, newer CURRENT ACTIVE term. */
    public function test_case1_historical_read_only_behind_a_current_active_term_does_not_heal(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();

        $this->legacyRow($shop, $plan);
        $this->liveRow($shop, $plan, 'active');
        $this->bareReadOnly($shop);

        $this->assertStaysReadOnlyAndUnwritable(
            $user,
            $shop,
            'A shop holding a live active term is not lapsed; its old read-only row is history, not evidence.'
        );
    }

    /** CASE 2 — historical read_only, newer CURRENT TRIAL. */
    public function test_case2_historical_read_only_behind_a_current_trial_does_not_heal(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();

        $this->legacyRow($shop, $plan);
        $this->liveRow($shop, $plan, 'trial');
        $this->bareReadOnly($shop);

        $this->assertStaysReadOnlyAndUnwritable($user, $shop, 'A live trial is a live entitlement.');
    }

    /** CASE 3 — historical read_only, newer VALID GRACE (paid window closed, grace open). */
    public function test_case3_historical_read_only_behind_a_valid_grace_term_does_not_heal(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();

        $this->legacyRow($shop, $plan);
        $grace = $this->liveRow($shop, $plan, 'grace');
        $this->bareReadOnly($shop);

        $this->assertTrue(now()->lte($grace->grace_ends_at), 'fixture must be inside the grace window');
        $this->assertStaysReadOnlyAndUnwritable($user, $shop, 'Valid grace is still an entitlement.');
    }

    /**
     * CASE 4 — the ordering trap: the shop is put into a BARE read-only access
     * state AFTER the newer active term exists. This is how an operator freezing
     * a currently-entitled shop looks when the stamp predates suspended_by.
     */
    public function test_case4_bare_read_only_applied_after_a_current_active_term_does_not_heal(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();

        $this->legacyRow($shop, $plan);
        $this->liveRow($shop, $plan, 'active');

        // The freeze happens last, on an otherwise healthy shop.
        $shop->forceFill([
            'access_mode'       => 'read_only',
            'suspended_at'      => now(),
            'suspension_reason' => null,
            'suspended_by'      => null,
        ])->save();

        $this->assertStaysReadOnlyAndUnwritable($user, $shop, 'A freeze on an entitled shop is not a subscription lapse.');
    }

    /**
     * CASE 5 — the renewed JF-0001 shop. It really was stranded once, so the
     * read_only row is genuine history; it then paid and is current. The old row
     * must not follow it forever.
     */
    public function test_case5_a_renewed_jf0001_shop_is_not_healed_by_its_old_read_only_row(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();

        $this->sub($shop, $plan, 'active');          // the original paid term
        $this->legacyRow($shop, $plan);              // the fork's lapse row
        $this->liveRow($shop, $plan, 'active');      // the renewal
        $this->bareReadOnly($shop);

        $this->assertStaysReadOnlyAndUnwritable($user, $shop, 'A renewed shop is current, whatever its history says.');
    }

    /**
     * CASE 6 — cross-product. The legacy read_only belongs to a DIFFERENT
     * product and is the NEWEST row (the adversarial ordering: latest-row logic
     * alone would call it a lapse), while the relevant product is still live.
     */
    public function test_case6_a_read_only_row_for_another_product_does_not_heal_this_shop(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop] = $this->tenant();

        $retail = Plan::create([
            'code'                => 'retailer_f1_' . fake()->unique()->numberBetween(1000, 999999),
            'name'                => 'Retail F1',
            'platform_product_id' => PlatformProduct::where('code', 'retail')->value('id'),
            'price_monthly'       => 1499,
            'grace_days'          => 14,
            'is_active'           => true,
        ]);
        $manufacturing = Plan::create([
            'code'                => 'manufacturer_f1_' . fake()->unique()->numberBetween(1000, 999999),
            'name'                => 'Manufacturing F1',
            'platform_product_id' => PlatformProduct::where('code', 'manufacturing')->value('id'),
            'price_monthly'       => 1499,
            'grace_days'          => 14,
            'is_active'           => true,
        ]);

        $this->liveRow($shop, $retail, 'active');            // relevant product: LIVE
        $this->legacyRow($shop, $manufacturing);             // other product: legacy, and NEWEST
        $this->bareReadOnly($shop);

        $this->assertSame(
            'read_only',
            ShopSubscription::where('shop_id', $shop->id)->latest('id')->value('status'),
            'fixture must put the unrelated legacy row last, or the case proves nothing'
        );
        $this->assertStaysReadOnlyAndUnwritable(
            $user,
            $shop,
            'Another product lapsing does not make this shop lapsed while a live term stands.'
        );
    }

    /**
     * CASE 7a — the genuine incident, enforcement ON: reconciled onto the
     * entitlement axis and routed to renewal, never left stranded.
     *
     * The fixture deliberately carries an OLD, already-ended `active` term: a
     * real JF-0001 shop paid before it lapsed. This is what separates "no live
     * entitlement" from "never had one" — a presence-only guard would read that
     * dead row as an entitlement and refuse to recover the owner.
     */
    public function test_case7_genuine_current_legacy_read_only_recovers_when_enforcement_is_on(): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$user, $shop, $plan] = $this->tenant();

        $this->sub($shop, $plan, 'active');   // long-dead paid term
        $this->legacyRow($shop, $plan);       // the lapse, and the latest row
        $this->bareReadOnly($shop);

        $this->assertTrue(
            $shop->fresh()->suspensionIsSubscriptionManaged(),
            'The genuine four-component incident must stay owner-recoverable.'
        );

        $this->actingAs($user)->get('/_ro/read')->assertRedirect(route('subscription.plans'));
        $this->assertSame('suspended', $shop->fresh()->access_mode, 'It must be reconciled onto the entitlement axis.');
        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();
    }

    /** CASE 7b — the same row, enforcement OFF: the intentional ERP bypass still heals it. */
    public function test_case7_genuine_current_legacy_read_only_still_heals_when_enforcement_is_off(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();

        $this->sub($shop, $plan, 'active');
        $this->legacyRow($shop, $plan);
        $this->bareReadOnly($shop);

        $this->actingAs($user)->get('/_ro/read')->assertOk();

        $fresh = $shop->fresh();
        $this->assertSame('active', $fresh->access_mode, 'A lapse must never lock ERP while enforcement is off.');
        $this->assertTrue((bool) $fresh->is_active);
        $this->assertNull($fresh->suspended_by);
    }

    /** CASE 8 — administrator stamp wins over any amount of read_only history. */
    public function test_case8_an_administrator_stamped_hold_with_read_only_history_never_heals(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan, $admin] = $this->tenant();

        $this->legacyRow($shop, $plan);
        $this->legacyRow($shop, $plan);

        $shop->forceFill([
            'access_mode'       => 'read_only',
            'is_active'         => true,
            'suspended_at'      => now(),
            'suspension_reason' => 'Compliance review by platform admin',
            'suspended_by'      => $admin->id,
        ])->save();

        $this->assertFalse($shop->fresh()->suspensionIsSubscriptionManaged());

        $this->actingAs($user)->get('/_ro/read')->assertOk();

        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertSame($admin->id, $fresh->suspended_by, 'An administrator stamp is never cleared by this path.');
    }

    /**
     * CASE 9 — bare read-only whose latest row is expired/cancelled with NO
     * corroborating read_only row. Ambiguous: the access mode says one thing and
     * no legacy artefact backs it. Must fail CLOSED — refuse to heal.
     */
    public function test_case9_bare_read_only_with_an_expired_latest_row_fails_closed(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();

        $this->sub($shop, $plan, 'expired');
        $this->bareReadOnly($shop);

        $this->assertStaysReadOnlyAndUnwritable($user, $shop, 'No corroborating read_only row: fail closed.');
    }

    /** CASE 9b — the cancelled variant of the same ambiguity. */
    public function test_case9_bare_read_only_with_a_cancelled_latest_row_fails_closed(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$user, $shop, $plan] = $this->tenant();

        $this->sub($shop, $plan, 'cancelled');
        $this->bareReadOnly($shop);

        $this->assertStaysReadOnlyAndUnwritable($user, $shop, 'No corroborating read_only row: fail closed.');
    }
}
