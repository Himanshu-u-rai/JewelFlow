<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\QuickBill;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Models\User;
use App\Services\Mobile\CapabilityResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Owner-controlled per-shop Quick Bill toggle (quick_bill_enabled, default true).
 *
 * Covers: default-on for new + existing shops, owner-only mutation, sidebar
 * visibility, web + mobile/API access blocking, data preservation on disable,
 * restore on re-enable, per-tenant isolation, and that RBAC still applies when
 * the feature is on.
 */
class QuickBillToggleTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // CSRF + Authorize off so requests reach the controller without per-route
        // permission wiring; the quick_bill toggle middleware is NOT disabled, so
        // its behaviour is exercised for real.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    }

    private function prefs(int $shopId): ShopPreferences
    {
        return ShopPreferences::withoutGlobalScopes()->where('shop_id', $shopId)->firstOrFail();
    }

    private function ensurePrefs(int $shopId): ShopPreferences
    {
        $p = ShopPreferences::withoutGlobalScopes()->firstOrNew(['shop_id' => $shopId]);
        $p->forceFill(['shop_id' => $shopId])->save();

        return $p->refresh();
    }

    private function setQuickBill(int $shopId, bool $enabled): void
    {
        $p = ShopPreferences::withoutGlobalScopes()->firstOrNew(['shop_id' => $shopId]);
        $p->forceFill(['shop_id' => $shopId, 'quick_bill_enabled' => $enabled])->save();
    }

    private function makeUser(Shop $shop, string $roleName, array $permNames = []): User
    {
        $role = new Role();
        $role->forceFill([
            'name' => $roleName,
            'display_name' => ucfirst($roleName),
            'shop_id' => $shop->id,
        ])->save();

        if ($permNames) {
            $role->permissions()->sync(Permission::query()->whereIn('name', $permNames)->pluck('id'));
        }

        return User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    private function prefPayload(array $extra = []): array
    {
        return array_merge([
            'low_stock_threshold'        => 5,
            'loyalty_points_per_hundred' => 2,
            'loyalty_point_value'        => 0.5,
            'loyalty_expiry_months'      => 12,
        ], $extra);
    }

    private function makeQuickBill(int $shopId, int $sequence = 1): QuickBill
    {
        $bill = new QuickBill();
        $bill->forceFill([
            'shop_id'       => $shopId,
            'bill_sequence' => $sequence,
            'bill_number'   => 'QB-' . $sequence,
            'bill_date'     => now()->toDateString(),
            'status'        => QuickBill::STATUS_ISSUED,
        ])->save();

        return $bill;
    }

    // ── 1. default true for new + existing shops ────────────────────────────────

    public function test_quick_bill_defaults_enabled_for_new_and_existing_shops(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        // New shop: no preferences row yet → reads as enabled.
        $this->assertFalse(ShopPreferences::withoutGlobalScopes()->where('shop_id', $shop->id)->exists());
        $this->assertTrue(($this->ensurePrefs($shop->id)->quick_bill_enabled ?? true));

        // Existing row created without touching the column → DB default is true.
        $this->assertTrue((bool) $this->prefs($shop->id)->quick_bill_enabled);
    }

    // ── 2. owner can disable ────────────────────────────────────────────────────

    public function test_owner_can_disable_quick_bill(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        $this->actingAs($owner)
            ->patch(route('settings.update.preferences'), $this->prefPayload(['quick_bill_enabled' => '0']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->prefs($shop->id)->quick_bill_enabled);
    }

    // ── 3. manager/cashier cannot disable ───────────────────────────────────────

    public function test_non_owner_cannot_disable_quick_bill(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->ensurePrefs($shop->id); // starts enabled
        $cashier = $this->makeUser($shop, 'cashier');

        // Cashier submits an attempt to disable; the owner-only field is ignored.
        $this->actingAs($cashier)
            ->patch(route('settings.update.preferences'), $this->prefPayload(['quick_bill_enabled' => '0']))
            ->assertRedirect();

        $this->assertTrue((bool) $this->prefs($shop->id)->quick_bill_enabled, 'non-owner must not disable');
    }

    // ── 4. sidebar hidden when disabled ─────────────────────────────────────────

    public function test_sidebar_link_hidden_when_disabled_and_shown_when_enabled(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $url = route('quick-bills.index');

        $this->setQuickBill($shop->id, true);
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->assertSee($url);

        $this->setQuickBill($shop->id, false);
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->assertDontSee($url);
    }

    // ── 5. direct web route blocked ─────────────────────────────────────────────

    public function test_direct_web_route_blocked_when_disabled(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->setQuickBill($shop->id, false);

        $this->actingAs($owner)->get(route('quick-bills.index'))
            ->assertRedirect(route('dashboard'));
    }

    // ── 6. create POST blocked ──────────────────────────────────────────────────

    public function test_create_post_blocked_when_disabled(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->setQuickBill($shop->id, false);

        $this->actingAs($owner)->post(route('quick-bills.store'), [])
            ->assertRedirect(route('dashboard'));

        // Nothing was written.
        $this->assertSame(0, QuickBill::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    // ── 7. API/mobile blocked with JSON 403 ─────────────────────────────────────

    public function test_mobile_api_blocked_when_disabled(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->setQuickBill($shop->id, false);
        Sanctum::actingAs($owner);

        $this->getJson('/api/mobile/quick-bills')
            ->assertStatus(403)
            ->assertExactJson(['error' => 'quick_bill_disabled']);
    }

    // ── 8. disabling does not delete records ────────────────────────────────────

    public function test_disabling_preserves_existing_records(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $bill = $this->makeQuickBill($shop->id);

        $this->actingAs($owner)
            ->patch(route('settings.update.preferences'), $this->prefPayload(['quick_bill_enabled' => '0']))
            ->assertRedirect();

        $this->assertDatabaseHas('quick_bills', ['id' => $bill->id, 'shop_id' => $shop->id]);
    }

    // ── 9. re-enabling restores access ──────────────────────────────────────────

    public function test_re_enabling_restores_access(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        $this->setQuickBill($shop->id, false);
        $this->actingAs($owner->fresh())->get(route('quick-bills.index'))->assertRedirect(route('dashboard'));

        $this->setQuickBill($shop->id, true);
        $this->actingAs($owner->fresh())->get(route('quick-bills.index'))->assertOk();
    }

    // ── 10. one shop's toggle does not affect another ───────────────────────────

    public function test_disabling_one_shop_does_not_affect_another(): void
    {
        [$userA, $shopA] = $this->createManufacturerTenant();
        [$userB, $shopB] = $this->createManufacturerTenant();
        $this->setQuickBill($shopA->id, false);

        $resolver = app(CapabilityResolver::class);
        // Resolve within each tenant's context (mirrors the live bootstrap path,
        // where TenantContext is set to the requesting shop).
        $aQuickBill = TenantContext::runFor($shopA->id, fn () => $resolver->resolve($shopA->fresh(), $userA)->quick_bill);
        $bQuickBill = TenantContext::runFor($shopB->id, fn () => $resolver->resolve($shopB->fresh(), $userB)->quick_bill);
        $this->assertFalse($aQuickBill);
        $this->assertTrue($bQuickBill);
    }

    // ── 11. RBAC still applies when enabled ─────────────────────────────────────

    public function test_permission_still_required_when_enabled(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->setQuickBill($shop->id, true);
        $resolver = app(CapabilityResolver::class);

        $cashierNoSales = $this->makeUser($shop, 'cashier');
        $cashierWithSales = $this->makeUser($shop, 'clerk', ['sales.pos']);

        [$noSales, $withSales] = TenantContext::runFor($shop->id, fn () => [
            $resolver->resolve($shop->fresh(), $cashierNoSales)->quick_bill,
            $resolver->resolve($shop->fresh(), $cashierWithSales)->quick_bill,
        ]);

        $this->assertFalse($noSales, 'enabled feature still gated by sales.pos permission');
        $this->assertTrue($withSales);
    }
}
