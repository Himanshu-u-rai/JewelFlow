<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformProduct;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\ShopEditionAssignment;
use App\Services\SubscriptionGateService;
use App\Support\ShopEdition;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression coverage for the seed-edition write-gate bypass.
 *
 * BUG (pre-fix): SubscriptionGateService::resolveWriteState() short-circuited
 * to "always writable" for BOTH source='admin_grant' AND source='seed'. Because
 * every normally-onboarded shop (Shop::created auto-seeds a 'seed' edition from
 * its shop_type) and every pre-Phase-2 row (backfilled to 'admin_grant') held
 * such a row, expired/unpaid shops could still write — the paywall was bypassed.
 *
 * DESIGN INTENT proven here:
 *   - admin_grant  = deliberate platform comp → ALWAYS writable w/o a sub (kept).
 *   - seed         = default onboarding row → writable ONLY when a writable
 *                    subscription (active/trial/grace) backs that product.
 *
 * The existing MultiProductSubscriptionTest deliberately used "bare shops with
 * NO seeded editions", so it never exercised the seed path — exactly where the
 * bug lived. These tests use REAL onboarded shops (created WITH a shop_type) so
 * the Shop::created seed hook fires.
 */
class SeedEditionEnforcementTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        config(['platform.enforce_subscriptions' => true]);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * A REAL onboarded shop: created WITH a shop_type so Shop::created fires and
     * auto-seeds a source='seed' edition row for that type. This is what every
     * normal production onboarding produces.
     */
    private function onboardedShop(string $shopType = 'retailer'): Shop
    {
        return Shop::create([
            'name'             => 'Onboarded Shop',
            'shop_type'        => $shopType,
            'phone'            => fake()->unique()->numerify('9#########'),
            'owner_first_name' => 'Real',
            'owner_last_name'  => 'Owner',
            'owner_mobile'     => fake()->unique()->numerify('9#########'),
            'is_active'        => true,
            'access_mode'      => 'active',
        ]);
    }

    private function seedEdition(Shop $shop, string $edition): ?ShopEditionAssignment
    {
        return ShopEditionAssignment::where('shop_id', $shop->id)
            ->where('edition', $edition)
            ->whereNull('deactivated_at')
            ->first();
    }

    private function planForProduct(string $productCode, array $overrides = []): Plan
    {
        $productId = PlatformProduct::where('code', $productCode)->value('id');

        $prefix = match ($productCode) {
            'retail'        => 'retailer',
            'manufacturing' => 'manufacturer',
            default         => $productCode,
        };

        return Plan::create(array_merge([
            'code'                => $prefix . '_test_' . fake()->unique()->numberBetween(1000, 999999),
            'name'                => ucfirst($productCode) . ' Test Plan',
            'platform_product_id' => $productId,
            'price_monthly'       => 1499,
            'price_yearly'        => 14999,
            'trial_days'          => 0,
            'grace_days'          => 14,
            'downgrade_to_read_only_on_due' => true,
            'is_active'           => true,
            'features'            => ['staff_limit' => 5],
        ], $overrides));
    }

    private function makeSubscription(Shop $shop, Plan $plan, string $status = 'active', array $overrides = []): ShopSubscription
    {
        $admin = PlatformAdmin::where('role', 'super_admin')->first() ?? $this->createPlatformAdmin();

        return ShopSubscription::create(array_merge([
            'shop_id'             => $shop->id,
            'plan_id'             => $plan->id,
            'status'              => $status,
            'starts_at'           => now()->subDay()->toDateString(),
            'ends_at'             => now()->addMonth()->toDateString(),
            'grace_ends_at'       => now()->addMonth()->addDays(14)->toDateString(),
            'billing_cycle'       => 'monthly',
            'price_paid'          => 1499,
            'razorpay_payment_id' => 'pay_' . fake()->unique()->bothify('??########'),
            'razorpay_order_id'   => 'order_' . fake()->unique()->bothify('??########'),
            'updated_by_admin_id' => $admin->id,
        ], $overrides));
    }

    private function assertWritable(Shop $shop): void
    {
        TenantContext::runFor($shop->id, fn () => SubscriptionGateService::assertShopWritable($shop->id));
        $this->assertTrue(true);
    }

    private function assertBlocked(Shop $shop): void
    {
        try {
            TenantContext::runFor($shop->id, fn () => SubscriptionGateService::assertShopWritable($shop->id));
            $this->fail('Expected write to be BLOCKED but it was allowed.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('blocked', strtolower($e->getMessage()));
        }
    }

    /**
     * Run the re-source data migration's up() directly.
     *
     * RefreshDatabase already runs every migration once during DB setup (when
     * there is no data, so it's a no-op). We invoke up() again here AFTER the
     * test's admin_grant fixtures exist — the migration's `where source =
     * admin_grant` filter makes this safe/idempotent and mirrors what running it
     * on the live, populated DB does.
     */
    private function runResourceMigration(): void
    {
        // Resolve relative to THIS test file so it works regardless of how the
        // app base_path() is configured under phpunit (vendor may be symlinked).
        $path = dirname(__DIR__, 2)
            . '/database/migrations/2026_08_17_010000_resource_backfilled_admin_grant_editions.php';
        $migration = require $path;
        $migration->up();
    }

    // ── 1) The bug-catcher ──────────────────────────────────────────

    /**
     * A real onboarded shop holds a 'seed' edition. Its ONLY subscription is
     * expired. The write gate MUST block. (Pre-fix this PASSED the gate because
     * the seed row short-circuited to always-writable — the exact bypass.)
     */
    public function test_seed_edition_with_expired_subscription_is_blocked(): void
    {
        $shop = $this->onboardedShop('retailer');

        // Confirm the seed edition exists and is a 'seed' row (not subscription).
        $seed = $this->seedEdition($shop, ShopEdition::RETAILER);
        $this->assertNotNull($seed, 'onboarded shop must auto-hold a seed retailer edition');
        $this->assertSame(ShopEditionAssignment::SOURCE_SEED, $seed->source);

        // Its only subscription is expired (a customer who stopped paying).
        $plan = $this->planForProduct('retail', ['downgrade_to_read_only_on_due' => false]);
        $this->makeSubscription($shop, $plan, 'expired', [
            'ends_at'       => now()->subMonth()->toDateString(),
            'grace_ends_at' => now()->subMonth()->addDays(14)->toDateString(),
        ]);

        $this->assertBlocked($shop);
    }

    // ── 2) seed + active sub → writable ─────────────────────────────

    public function test_seed_edition_with_active_subscription_is_writable(): void
    {
        $shop = $this->onboardedShop('retailer');
        $this->assertSame(ShopEditionAssignment::SOURCE_SEED, $this->seedEdition($shop, ShopEdition::RETAILER)->source);

        $plan = $this->planForProduct('retail');
        $this->makeSubscription($shop, $plan, 'active');

        $this->assertWritable($shop);
    }

    // ── 3) seed + grace sub → writable ──────────────────────────────

    public function test_seed_edition_with_grace_subscription_is_writable(): void
    {
        $shop = $this->onboardedShop('retailer');

        $plan = $this->planForProduct('retail');
        $this->makeSubscription($shop, $plan, 'grace', [
            'ends_at'       => now()->subDay()->toDateString(),
            'grace_ends_at' => now()->addDays(10)->toDateString(),
        ]);

        $this->assertWritable($shop);
    }

    // ── 4) seed + NO subscription at all → blocked ──────────────────

    public function test_seed_edition_with_no_subscription_is_blocked(): void
    {
        $shop = $this->onboardedShop('retailer');
        $this->assertSame(ShopEditionAssignment::SOURCE_SEED, $this->seedEdition($shop, ShopEdition::RETAILER)->source);

        // No subscription created at all — a seed shop that never paid.
        $this->assertSame(0, ShopSubscription::where('shop_id', $shop->id)->count());

        $this->assertBlocked($shop);
    }

    // ── 5) admin_grant + expired/no sub → STILL writable ────────────

    public function test_admin_grant_edition_stays_writable_without_subscription(): void
    {
        // Bare onboarded shop, then upgrade its edition source to admin_grant
        // (a deliberate platform comp). No subscription at all.
        $shop = $this->onboardedShop('retailer');
        ShopEdition::grantTo($shop, ShopEdition::RETAILER, null); // source → admin_grant
        $this->assertSame(
            ShopEditionAssignment::SOURCE_ADMIN_GRANT,
            $this->seedEdition($shop, ShopEdition::RETAILER)->source
        );

        // Frozen requirement: admin_grant is always writable without a sub.
        $this->assertWritable($shop);
    }

    public function test_admin_grant_edition_stays_writable_even_with_expired_subscription(): void
    {
        $shop = $this->onboardedShop('retailer');
        ShopEdition::grantTo($shop, ShopEdition::RETAILER, null);

        $plan = $this->planForProduct('retail', ['downgrade_to_read_only_on_due' => false]);
        $this->makeSubscription($shop, $plan, 'expired', [
            'ends_at'       => now()->subMonth()->toDateString(),
            'grace_ends_at' => now()->subMonth()->addDays(14)->toDateString(),
        ]);

        $this->assertWritable($shop);
    }

    // ── 6) Dhiran-only seed shop on dhiran sub lifecycle ────────────

    public function test_dhiran_only_seed_shop_writable_on_active_dhiran_then_blocked_on_expiry(): void
    {
        $shop = $this->onboardedShop('dhiran');
        $seed = $this->seedEdition($shop, ShopEdition::DHIRAN);
        $this->assertNotNull($seed, 'dhiran onboarded shop must auto-hold a seed dhiran edition');
        $this->assertSame(ShopEditionAssignment::SOURCE_SEED, $seed->source);
        $this->assertNull($this->seedEdition($shop, ShopEdition::RETAILER));

        // Writable on an active dhiran subscription.
        $plan = $this->planForProduct('dhiran', ['downgrade_to_read_only_on_due' => false]);
        $sub  = $this->makeSubscription($shop, $plan, 'active');
        $this->assertWritable($shop);

        // After the dhiran sub expires (lapse), the seed edition no longer has a
        // writable sub backing it → blocked. The seed ROW survives the lapse
        // (lapse-immunity) but does NOT grant free writes.
        $sub->update([
            'ends_at'       => now()->subMonth()->toDateString(),
            'grace_ends_at' => now()->subMonth()->addDays(14)->toDateString(),
        ]);
        $this->artisan('subscription:check-expiry')->assertExitCode(0);
        $sub->refresh();
        $this->assertSame('expired', $sub->status);

        // The seed row is intentionally NOT auto-revoked (orphan protection).
        $this->assertNotNull(
            $this->seedEdition($shop, ShopEdition::DHIRAN),
            'seed edition row must survive the lapse (row-level immunity is intentional)'
        );

        // But the gate must block — seed alone does not grant writes.
        $this->assertBlocked($shop);
    }

    // ── 7) data-migration relabel behaviour ─────────────────────────

    public function test_migration_relabels_admin_grant_with_subscription_to_seed(): void
    {
        // Shop A: an admin_grant retailer edition WITH a retail subscription for
        // that product → migration should relabel it to 'seed'.
        $shopWithSub = $this->onboardedShop('retailer');
        // Force the seed row to admin_grant to simulate the Phase-2 backfill.
        DB::table('shop_editions')
            ->where('shop_id', $shopWithSub->id)
            ->where('edition', ShopEdition::RETAILER)
            ->update(['source' => DB::raw("'admin_grant'")]);
        $retailPlan = $this->planForProduct('retail');
        $this->makeSubscription($shopWithSub, $retailPlan, 'expired', [
            'ends_at'       => now()->subMonth()->toDateString(),
            'grace_ends_at' => now()->subMonth()->addDays(14)->toDateString(),
        ]);

        // Shop B: an admin_grant edition with NO subscription at all → stays
        // admin_grant (genuine free-access / comp ambiguity, human decides).
        $shopNoSub = $this->onboardedShop('manufacturer');
        DB::table('shop_editions')
            ->where('shop_id', $shopNoSub->id)
            ->where('edition', ShopEdition::MANUFACTURER)
            ->update(['source' => DB::raw("'admin_grant'")]);
        $this->assertSame(0, ShopSubscription::where('shop_id', $shopNoSub->id)->count());

        // Shop C: an admin_grant edition for one product but the shop's only
        // subscription is for a DIFFERENT product → that edition stays
        // admin_grant (no subscription for ITS product).
        $shopMismatch = $this->onboardedShop('retailer');
        DB::table('shop_editions')
            ->where('shop_id', $shopMismatch->id)
            ->where('edition', ShopEdition::RETAILER)
            ->update(['source' => DB::raw("'admin_grant'")]);
        // Its only sub is dhiran, not retail.
        $dhiranPlan = $this->planForProduct('dhiran');
        $this->makeSubscription($shopMismatch, $dhiranPlan, 'active');

        // Run the data migration.
        $this->runResourceMigration();

        // Shop A: relabeled to seed.
        $this->assertSame(
            ShopEditionAssignment::SOURCE_SEED,
            DB::table('shop_editions')->where('shop_id', $shopWithSub->id)->where('edition', ShopEdition::RETAILER)->value('source'),
            'admin_grant row with a matching subscription must be relabeled to seed'
        );

        // Shop B: untouched.
        $this->assertSame(
            ShopEditionAssignment::SOURCE_ADMIN_GRANT,
            DB::table('shop_editions')->where('shop_id', $shopNoSub->id)->where('edition', ShopEdition::MANUFACTURER)->value('source'),
            'admin_grant row with NO subscription must stay admin_grant'
        );

        // Shop C: untouched (sub is for a different product).
        $this->assertSame(
            ShopEditionAssignment::SOURCE_ADMIN_GRANT,
            DB::table('shop_editions')->where('shop_id', $shopMismatch->id)->where('edition', ShopEdition::RETAILER)->value('source'),
            'admin_grant row whose shop has no subscription for ITS product must stay admin_grant'
        );
    }

    /**
     * After the migration relabels a backed admin_grant to seed, the gate then
     * correctly enforces the subscription on that shop — proving the end-to-end
     * effect: backfilled "free write" rows become paywall-enforced.
     */
    public function test_migration_relabel_then_gate_enforces_on_lapse(): void
    {
        $shop = $this->onboardedShop('retailer');
        DB::table('shop_editions')
            ->where('shop_id', $shop->id)
            ->where('edition', ShopEdition::RETAILER)
            ->update(['source' => DB::raw("'admin_grant'")]);

        $plan = $this->planForProduct('retail', ['downgrade_to_read_only_on_due' => false]);
        $this->makeSubscription($shop, $plan, 'expired', [
            'ends_at'       => now()->subMonth()->toDateString(),
            'grace_ends_at' => now()->subMonth()->addDays(14)->toDateString(),
        ]);

        // Pre-migration: admin_grant short-circuit → writable (the latent bug).
        $this->assertWritable($shop);

        $this->runResourceMigration();

        // Post-migration: relabeled to seed + expired sub → now blocked.
        $this->assertSame(
            ShopEditionAssignment::SOURCE_SEED,
            $this->seedEdition($shop, ShopEdition::RETAILER)->source
        );
        $this->assertBlocked($shop);
    }
}
