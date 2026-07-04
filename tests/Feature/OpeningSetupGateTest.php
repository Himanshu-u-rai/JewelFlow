<?php

namespace Tests\Feature;

use App\Models\OnboardingBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShopPreferences;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Mandatory opening-setup gate (EnsureOpeningSetupCompleted + ShopOpeningSetupState).
 *
 * A shop cannot begin live transactional use until the owner explicitly chooses
 * Start Fresh or Migrate. Owners are driven to /onboarding; staff see a blocked
 * page. Existing shops with trading history are backfilled so they are never
 * locked out.
 *
 * `cashbook.index` is the representative transactional route (owner holds
 * cash.view via the full-permission owner role).
 */
class OpeningSetupGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';
    private const TXN_ROUTE = 'cashbook.index';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Return a shop to the "no decision made" state. */
    private function unstamp(int $shopId): void
    {
        // The tenant helper always seeds a shop_preferences row, so this is an
        // update — never an insert (which would drop the guarded shop_id).
        ShopPreferences::withoutTenant()->where('shop_id', $shopId)
            ->update(['opening_setup_skipped_at' => null]);
    }

    /** Run the one-off safety backfill migration against the current DB state. */
    private function runBackfill(): void
    {
        $migration = require database_path('migrations/2026_09_03_000000_backfill_opening_setup_for_active_shops.php');
        $migration->up();
    }

    private function skippedAt(int $shopId)
    {
        return ShopPreferences::withoutTenant()->where('shop_id', $shopId)->value('opening_setup_skipped_at');
    }

    private function makeStaff(int $shopId, string $roleName, array $permissionNames = []): User
    {
        $role = new Role();
        $role->forceFill(['name' => $roleName, 'display_name' => ucfirst($roleName), 'shop_id' => $shopId])->save();
        if ($permissionNames) {
            $role->permissions()->sync(Permission::whereIn('name', $permissionNames)->pluck('id'));
        }

        return User::factory()->create(['shop_id' => $shopId, 'role_id' => $role->id, 'is_active' => true]);
    }

    // ── owner gating ─────────────────────────────────────────────────────────

    /** 1. Fresh owner is redirected to /onboarding when hitting the dashboard. */
    public function test_fresh_owner_redirected_to_onboarding_from_dashboard(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($user)
            ->get(self::ERP . '/dashboard')
            ->assertRedirect(route('onboarding.index')));
    }

    /** 2. Fresh owner is blocked from a transactional route until they decide. */
    public function test_owner_blocked_from_transactional_route_until_decision(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);

        $this->actingAs($user)->get(route(self::TXN_ROUTE))
            ->assertRedirect(route('onboarding.index'));
    }

    /** 3. A blocked owner can still reach onboarding to make the decision. */
    public function test_owner_can_reach_onboarding_while_blocked(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);

        $this->actingAs($user)->get(route('onboarding.index'))->assertOk();
    }

    /** 4. Start Fresh stamps the flag and unlocks the transactional route. */
    public function test_start_fresh_unlocks_transactional_route(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);

        // Blocked before the decision.
        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertRedirect(route('onboarding.index'));

        $this->actingAs($user)->post(route('onboarding.start-clean'))->assertRedirect(route('onboarding.index'));
        $this->assertNotNull($this->skippedAt($shop->id));

        // Unlocked after Start Fresh.
        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertOk();
    }

    /** 5. An in-flight (draft/review/posting) migration blocks the transactional route. */
    public function test_active_migration_blocks_transactional_route(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        // Opening a batch supersedes any prior decision and marks migration_in_progress.
        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $this->assertNull($this->skippedAt($shop->id), 'Opening a batch clears the Start Fresh flag.');

        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertRedirect(route('onboarding.index'));
    }

    /** 6. A locked batch (migration completed) unlocks the transactional route. */
    public function test_locked_batch_unlocks_transactional_route(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch));
        TenantContext::clear();

        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertOk();
    }

    // ── staff blocked pages ──────────────────────────────────────────────────

    /** 7. Staff see the setup-pending page while a decision is still required. */
    public function test_staff_see_setup_pending_page_when_setup_required(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);
        $staff = $this->makeStaff($shop->id, 'staff', ['cash.view']);

        $this->actingAs($staff)->get(route(self::TXN_ROUTE))
            ->assertStatus(403)
            ->assertSee('Shop setup pending');
    }

    /** 8. Staff see the migration-in-progress page while the owner is migrating. */
    public function test_staff_see_migration_in_progress_page(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->actingAs($owner)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);

        $staff = $this->makeStaff($shop->id, 'manager', ['cash.view']);

        $this->actingAs($staff)->get(route(self::TXN_ROUTE))
            ->assertStatus(403)
            ->assertSee('Opening Balance setup in progress');
    }

    // ── existing-shop backfill safety ────────────────────────────────────────

    /** 9. Existing shop with a finalized invoice is backfilled and unlocked. */
    public function test_existing_shop_with_finalized_invoice_is_backfilled(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);
        $customer = $this->createCustomer($shop->id);

        DB::table('invoices')->insert([
            'invoice_number' => 'INV-TEST-' . $shop->id,
            'customer_id'    => $customer->id,
            'shop_id'        => $shop->id,
            'gold_rate'      => 7000,
            'subtotal'       => 100,
            'gst'            => 3,
            'total'          => 103,
            'status'         => 'finalized',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->runBackfill();

        $this->assertNotNull($this->skippedAt($shop->id));
        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertOk();
    }

    /** 10. Existing shop with a cash transaction is backfilled and unlocked. */
    public function test_existing_shop_with_cash_transaction_is_backfilled(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);

        DB::table('cash_transactions')->insert([
            'shop_id'     => $shop->id,
            'type'        => 'in',
            'amount'      => 500,
            'source_type' => 'opening_balance',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->runBackfill();

        $this->assertNotNull($this->skippedAt($shop->id));
        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertOk();
    }

    /** 11. Existing shop with a metal movement is backfilled and unlocked. */
    public function test_existing_shop_with_metal_movement_is_backfilled(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);

        DB::table('metal_movements')->insert([
            'shop_id'     => $shop->id,
            'fine_weight' => 10,
            'type'        => 'opening',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->runBackfill();

        $this->assertNotNull($this->skippedAt($shop->id));
        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertOk();
    }

    /** 12. Existing shop with a customer gold transaction is backfilled and unlocked. */
    public function test_existing_shop_with_customer_gold_transaction_is_backfilled(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);
        $customer = $this->createCustomer($shop->id);

        DB::table('customer_gold_transactions')->insert([
            'shop_id'     => $shop->id,
            'customer_id' => $customer->id,
            'fine_gold'   => 5,
            'type'        => 'advance',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->runBackfill();

        $this->assertNotNull($this->skippedAt($shop->id));
        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertOk();
    }

    /** 13. Zero-history shop is NOT backfilled and must still make the decision. */
    public function test_zero_history_shop_is_not_backfilled(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);

        $this->runBackfill();

        $this->assertNull($this->skippedAt($shop->id), 'A shop with no history must not be auto-stamped.');
        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertRedirect(route('onboarding.index'));
    }

    // ── unaffected surfaces ──────────────────────────────────────────────────

    /** 14. Logout stays reachable for a blocked owner (it lives outside the gate). */
    public function test_logout_reachable_while_blocked(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->unstamp($shop->id);

        $this->actingAs($user)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    /** 15. Public/guest routes are unaffected — the gate is ERP-authenticated only. */
    public function test_public_routes_unaffected(): void
    {
        $this->get(route('login'))->assertOk();
    }

    /** 16. A normally-provisioned tenant (Start Fresh via harness) reaches the ERP. */
    public function test_started_fresh_tenant_reaches_erp(): void
    {
        [$user] = $this->createManufacturerTenant();

        $this->actingAs($user)->get(route(self::TXN_ROUTE))->assertOk();
    }
}
