<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\QuickBill;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Owner-controlled per-shop "Close Shop" switch (shop_access_enabled, default
 * true). When closed, only the owner may use the app; manager/staff/cashier are
 * blocked from web + mobile + POS operational routes. No data is deleted.
 */
class ShopAccessTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // CSRF + Authorize off so requests reach the controller without per-route
        // permission wiring; the shopaccess.open middleware is NOT disabled, so its
        // behaviour is exercised for real.
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

    private function setShopAccess(int $shopId, bool $open): void
    {
        $p = ShopPreferences::withoutGlobalScopes()->firstOrNew(['shop_id' => $shopId]);
        $p->forceFill(['shop_id' => $shopId, 'shop_access_enabled' => $open])->save();
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

    // ── 1. default open for new + existing shops ────────────────────────────────

    public function test_shop_access_defaults_open(): void
    {
        [, $shop] = $this->createManufacturerTenant();

        $this->assertFalse(ShopPreferences::withoutGlobalScopes()->where('shop_id', $shop->id)->exists());
        $this->assertTrue(($this->ensurePrefs($shop->id)->shop_access_enabled ?? true));
        $this->assertTrue((bool) $this->prefs($shop->id)->shop_access_enabled);
    }

    // ── 2. owner sees the control ───────────────────────────────────────────────

    public function test_owner_sees_close_shop_control(): void
    {
        [$owner] = $this->createManufacturerTenant();

        $this->actingAs($owner->fresh())->get(route('settings.edit', ['tab' => 'preferences']))
            ->assertOk()
            ->assertSee('Shop is open (staff can use the app)');
    }

    // ── 3. non-owner does not see the control ───────────────────────────────────

    public function test_non_owner_does_not_see_control(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        // Manager CAN view the preferences tab (settings.view) yet must NOT see the
        // owner-only Close Shop control.
        $manager = $this->makeUser($shop, 'manager', ['settings.view']);

        $this->actingAs($manager)->get(route('settings.edit', ['tab' => 'preferences']))
            ->assertOk()
            ->assertDontSee('Shop is open (staff can use the app)');
    }

    // ── 4. owner can close ──────────────────────────────────────────────────────

    public function test_owner_can_close_shop(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        $this->actingAs($owner)
            ->patch(route('settings.update.preferences'), $this->prefPayload(['shop_access_enabled' => '0']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->prefs($shop->id)->shop_access_enabled);
    }

    // ── 5. owner can reopen ─────────────────────────────────────────────────────

    public function test_owner_can_reopen_shop(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);

        $this->actingAs($owner->fresh())
            ->patch(route('settings.update.preferences'), $this->prefPayload(['shop_access_enabled' => '1']))
            ->assertRedirect();

        $this->assertTrue((bool) $this->prefs($shop->id)->shop_access_enabled);
    }

    // ── 6. non-owner cannot change the flag ─────────────────────────────────────

    public function test_non_owner_cannot_change_shop_access(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->ensurePrefs($shop->id); // starts open
        $manager = $this->makeUser($shop, 'manager');

        $this->actingAs($manager)
            ->patch(route('settings.update.preferences'), $this->prefPayload(['shop_access_enabled' => '0']))
            ->assertRedirect();

        $this->assertTrue((bool) $this->prefs($shop->id)->shop_access_enabled, 'non-owner must not close shop');
    }

    // ── 7. owner keeps access when closed ───────────────────────────────────────

    public function test_owner_keeps_access_when_closed(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);

        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk();
        $this->actingAs($owner->fresh())->get(route('settings.edit'))->assertOk();
        // POS must not be blocked by the shop-closed gate for the owner: the gate
        // redirects blocked users to the dashboard, so the owner must NOT land there.
        $res = $this->actingAs($owner->fresh())->get(route('pos.index'));
        $this->assertNotSame(route('dashboard'), $res->headers->get('Location'));
    }

    // ── 8. non-owner blocked from operational web route when closed ─────────────

    public function test_non_owner_blocked_from_web_when_closed(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        $cashier = $this->makeUser($shop, 'cashier');

        $this->actingAs($cashier)->get(route('inventory.items.index'))
            ->assertRedirect(route('dashboard'));
    }

    // ── 9. quick bill route blocked when closed (non-owner) ─────────────────────

    public function test_quick_bill_blocked_when_closed(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        $cashier = $this->makeUser($shop, 'cashier');

        $this->actingAs($cashier)->get(route('quick-bills.index'))
            ->assertRedirect(route('dashboard'));
    }

    // ── 10. POS route blocked when closed (non-owner) ───────────────────────────

    public function test_pos_blocked_when_closed(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        $cashier = $this->makeUser($shop, 'cashier');

        $this->actingAs($cashier)->get(route('pos.index'))
            ->assertRedirect(route('dashboard'));
    }

    // ── 11. mobile operational API blocked with 403 shop_closed ─────────────────

    public function test_mobile_api_blocked_when_closed(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        $cashier = $this->makeUser($shop, 'cashier');
        Sanctum::actingAs($cashier);

        $this->getJson('/api/mobile/quick-bills')
            ->assertStatus(403)
            ->assertExactJson(['error' => 'shop_closed', 'message' => 'Shop is currently closed by the owner.']);
    }

    // ── 12. mobile scan connect/send/status blocked with 403 shop_closed ────────

    public function test_mobile_scan_blocked_when_closed(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        $cashier = $this->makeUser($shop, 'cashier');
        Sanctum::actingAs($cashier);

        foreach (['/api/mobile/scan/connect', '/api/mobile/scan/send', '/api/mobile/scan/status'] as $url) {
            $this->postJson($url, [])
                ->assertStatus(403)
                ->assertJson(['error' => 'shop_closed']);
        }
    }

    // ── 13. reopening restores access ───────────────────────────────────────────

    public function test_reopening_restores_access(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $cashier = $this->makeUser($shop, 'cashier');

        $this->setShopAccess($shop->id, false);
        $this->actingAs($cashier->fresh())->get(route('quick-bills.index'))->assertRedirect(route('dashboard'));

        $this->setShopAccess($shop->id, true);
        $this->actingAs($cashier->fresh())->get(route('quick-bills.index'))->assertOk();
    }

    // ── 14. closing preserves records ───────────────────────────────────────────

    public function test_closing_preserves_records(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $bill = $this->makeQuickBill($shop->id);

        $this->actingAs($owner)
            ->patch(route('settings.update.preferences'), $this->prefPayload(['shop_access_enabled' => '0']))
            ->assertRedirect();

        $this->assertDatabaseHas('quick_bills', ['id' => $bill->id, 'shop_id' => $shop->id]);
    }

    // ── 15. one shop's closure does not affect another ──────────────────────────

    public function test_closing_one_shop_does_not_affect_another(): void
    {
        [, $shopA] = $this->createManufacturerTenant();
        [, $shopB] = $this->createManufacturerTenant();
        $cashierA = $this->makeUser($shopA, 'cashier');
        $cashierB = $this->makeUser($shopB, 'cashier');

        $this->setShopAccess($shopA->id, false);

        $this->actingAs($cashierA)->get(route('quick-bills.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($cashierB)->get(route('quick-bills.index'))->assertOk();
    }

    // ── 16. existing Quick Bill toggle still works ──────────────────────────────

    public function test_quick_bill_toggle_still_works(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        // Shop open, Quick Bill disabled: owner bypasses shop-access but NOT the
        // Quick Bill feature gate, so the route is still blocked.
        $this->setShopAccess($shop->id, true);
        ShopPreferences::withoutGlobalScopes()->firstOrNew(['shop_id' => $shop->id])
            ->forceFill(['shop_id' => $shop->id, 'quick_bill_enabled' => false])->save();

        $this->actingAs($owner->fresh())->get(route('quick-bills.index'))
            ->assertRedirect(route('dashboard'));
    }

    // ── 17. daily price / dashboard barrier not broken ──────────────────────────

    public function test_dashboard_accessible_when_open(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, true);
        $cashier = $this->makeUser($shop, 'cashier');

        // Open shop: both owner and staff reach the dashboard normally.
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk();
        $this->actingAs($cashier)->get(route('dashboard'))->assertOk();
    }

    // ── 18. public signed scan ingest blocked when closed ───────────────────────

    private function makeScanSession(int $shopId): \App\Models\ScanSession
    {
        $s = new \App\Models\ScanSession();
        $s->forceFill([
            'shop_id'    => $shopId,
            'token'      => str_repeat('a', 48),
            'status'     => 'active',
            'expires_at' => now()->addHour(),
        ])->save();

        return $s;
    }

    public function test_public_scan_ingest_blocked_when_closed(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $session = $this->makeScanSession($shop->id);
        $this->setShopAccess($shop->id, false);

        $this->postJson('/api/scan', ['token' => $session->token, 'barcode' => 'X-1'])
            ->assertStatus(403)
            ->assertExactJson(['error' => 'shop_closed', 'message' => 'Shop is currently closed by the owner.']);

        // Nothing ingested.
        $this->assertDatabaseMissing('scan_events', ['scan_session_id' => $session->id]);
    }

    // ── 19. public signed scan ingest works when open ───────────────────────────

    public function test_public_scan_ingest_works_when_open(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $session = $this->makeScanSession($shop->id);
        $this->setShopAccess($shop->id, true);

        $this->postJson('/api/scan', ['token' => $session->token, 'barcode' => 'X-1'])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('scan_events', ['scan_session_id' => $session->id, 'barcode' => 'X-1']);
    }

    // ── 20. old signed scan URL cannot bypass a later closure ───────────────────

    public function test_old_scan_url_cannot_bypass_closure(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        // URL minted while open, then the owner closes the shop.
        $session = $this->makeScanSession($shop->id);
        $this->postJson('/api/scan', ['token' => $session->token, 'barcode' => 'OK-1'])->assertOk();

        $this->setShopAccess($shop->id, false);

        $this->postJson('/api/scan', ['token' => $session->token, 'barcode' => 'NO-1'])
            ->assertStatus(403)
            ->assertJson(['error' => 'shop_closed']);
        $this->assertDatabaseMissing('scan_events', ['scan_session_id' => $session->id, 'barcode' => 'NO-1']);
    }

    // ── 22. dashboard quick-toggle: owner closes via the dedicated endpoint ──────

    public function test_owner_can_close_shop_via_dashboard_toggle(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->ensurePrefs($shop->id); // open

        $this->actingAs($owner->fresh())
            ->patch(route('settings.update.shop-access'), ['shop_access_enabled' => '0'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse((bool) $this->prefs($shop->id)->shop_access_enabled);
    }

    // ── 23. dashboard toggle must NOT clobber other preferences ──────────────────

    public function test_dashboard_toggle_preserves_other_preferences(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        // Quick Bill explicitly ON before closing the shop from the dashboard.
        ShopPreferences::withoutGlobalScopes()->firstOrNew(['shop_id' => $shop->id])
            ->forceFill(['shop_id' => $shop->id, 'quick_bill_enabled' => true, 'shop_access_enabled' => true])
            ->save();

        $this->actingAs($owner->fresh())
            ->patch(route('settings.update.shop-access'), ['shop_access_enabled' => '0'])
            ->assertRedirect();

        $fresh = $this->prefs($shop->id);
        $this->assertFalse((bool) $fresh->shop_access_enabled);
        $this->assertTrue((bool) $fresh->quick_bill_enabled, 'dashboard toggle must not wipe quick_bill_enabled');
    }

    // ── 24. non-owner cannot use the dashboard quick-toggle ──────────────────────

    public function test_non_owner_cannot_use_dashboard_toggle(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->ensurePrefs($shop->id); // open
        $manager = $this->makeUser($shop, 'manager', ['settings.edit']);

        $this->actingAs($manager)
            ->patch(route('settings.update.shop-access'), ['shop_access_enabled' => '0'])
            ->assertForbidden();

        $this->assertTrue((bool) $this->prefs($shop->id)->shop_access_enabled, 'non-owner must not close shop');
    }

    // ── 26. bootstrap carries shop_access when open ─────────────────────────────

    public function test_bootstrap_includes_shop_access_when_open(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->ensurePrefs($shop->id); // open
        Sanctum::actingAs($owner);

        $this->getJson('/api/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('shop_access.is_open', true)
            ->assertJsonPath('shop_access.shop_access_enabled', true)
            ->assertJsonPath('shop_access.closed_by_owner', false)
            ->assertJsonPath('shop_access.message', null)
            ->assertJsonPath('shop_access.can_current_user_access', true);
    }

    // ── 27. bootstrap carries shop_access when closed (non-owner) ────────────────

    public function test_bootstrap_shop_access_closed_for_non_owner(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        $cashier = $this->makeUser($shop, 'cashier');
        Sanctum::actingAs($cashier);

        $this->getJson('/api/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('shop_access.is_open', false)
            ->assertJsonPath('shop_access.closed_by_owner', true)
            ->assertJsonPath('shop_access.message', 'Shop is currently closed by the owner.')
            ->assertJsonPath('shop_access.can_current_user_access', false);
    }

    // ── 28. owner keeps can_current_user_access even when closed ─────────────────

    public function test_bootstrap_owner_can_access_when_closed(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        Sanctum::actingAs($owner->fresh());

        $this->getJson('/api/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('shop_access.is_open', false)
            ->assertJsonPath('shop_access.can_current_user_access', true);
    }

    // ── 29. GET /api/mobile/shop/access (open) ───────────────────────────────────

    public function test_mobile_shop_access_get_when_open(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->ensurePrefs($shop->id);
        Sanctum::actingAs($owner);

        $this->getJson('/api/mobile/shop/access')
            ->assertOk()
            ->assertExactJson([
                'shop_access_enabled'     => true,
                'status'                  => 'open',
                'message'                 => null,
                'can_current_user_access' => true,
            ]);
    }

    // ── 30. GET reachable + correct for non-owner while closed ──────────────────

    public function test_mobile_shop_access_get_when_closed(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        $cashier = $this->makeUser($shop, 'cashier');
        Sanctum::actingAs($cashier);

        $this->getJson('/api/mobile/shop/access')
            ->assertOk()
            ->assertExactJson([
                'shop_access_enabled'     => false,
                'status'                  => 'closed',
                'message'                 => 'Shop is currently closed by the owner.',
                'can_current_user_access' => false,
            ]);
    }

    // ── 31. owner closes via PATCH ──────────────────────────────────────────────

    public function test_mobile_owner_can_close(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->ensurePrefs($shop->id);
        Sanctum::actingAs($owner);

        $this->patchJson('/api/mobile/shop/access', ['shop_access_enabled' => false])
            ->assertOk()
            ->assertJson(['shop_access_enabled' => false, 'status' => 'closed', 'can_current_user_access' => true]);

        $this->assertFalse((bool) $this->prefs($shop->id)->shop_access_enabled);
    }

    // ── 32. owner reopens via PATCH (while closed — endpoint stays reachable) ────

    public function test_mobile_owner_can_reopen_while_closed(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->setShopAccess($shop->id, false);
        Sanctum::actingAs($owner->fresh());

        $this->patchJson('/api/mobile/shop/access', ['shop_access_enabled' => true])
            ->assertOk()
            ->assertJson(['shop_access_enabled' => true, 'status' => 'open']);

        $this->assertTrue((bool) $this->prefs($shop->id)->shop_access_enabled);
    }

    // ── 33. non-owner PATCH is forbidden ────────────────────────────────────────

    public function test_mobile_non_owner_cannot_update(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $this->ensurePrefs($shop->id); // open
        $manager = $this->makeUser($shop, 'manager');
        Sanctum::actingAs($manager);

        $this->patchJson('/api/mobile/shop/access', ['shop_access_enabled' => false])
            ->assertStatus(403)
            ->assertExactJson([
                'error' => 'forbidden',
                'message' => 'Only the shop owner can change shop access.',
            ]);

        $this->assertTrue((bool) $this->prefs($shop->id)->shop_access_enabled, 'non-owner must not close shop');
    }

    // ── 34. PATCH flips only shop_access_enabled, never other preferences ────────

    public function test_mobile_patch_preserves_quick_bill(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        ShopPreferences::withoutGlobalScopes()->firstOrNew(['shop_id' => $shop->id])
            ->forceFill(['shop_id' => $shop->id, 'quick_bill_enabled' => true, 'shop_access_enabled' => true])
            ->save();
        Sanctum::actingAs($owner);

        $this->patchJson('/api/mobile/shop/access', ['shop_access_enabled' => false])->assertOk();

        $fresh = $this->prefs($shop->id);
        $this->assertFalse((bool) $fresh->shop_access_enabled);
        $this->assertTrue((bool) $fresh->quick_bill_enabled, 'mobile toggle must not wipe quick_bill_enabled');
    }

    // ── 35. feature stays out of Dhiran ─────────────────────────────────────────

    public function test_middleware_does_not_touch_dhiran(): void
    {
        $src = file_get_contents(app_path('Http/Middleware/EnsureShopAccessOpen.php'));
        $this->assertStringNotContainsStringIgnoringCase('dhiran', $src);
    }
}
