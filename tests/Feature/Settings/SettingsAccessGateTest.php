<?php

namespace Tests\Feature\Settings;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Settings access gates (Module 19). Persistence, validation, cross-shop and
 * crafted-shop_id handling are already covered by GstTaxSettings/PaymentMethod/
 * Preferences/ReturnPolicy tests (which bypass the Authorize middleware to test
 * the write logic). This covers the one untested slice: the settings.view /
 * settings.edit middleware gates and guest redirect on the settings surface.
 */
class SettingsAccessGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    public function test_guest_cannot_reach_settings(): void
    {
        $res = $this->get(self::ERP . '/settings');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    public function test_user_without_settings_view_is_denied(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9824400001');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/settings'))->assertForbidden();
    }

    public function test_settings_view_user_can_open_index(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $viewer = $this->userWithPerms($shop, ['settings.view'], '9824400002');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/settings'))->assertOk();
    }

    public function test_user_without_settings_edit_cannot_update_shop(): void
    {
        [, $shop] = $this->createRetailerTenant();
        // Read-only on settings: can view, cannot write. The settings.edit gate
        // must block the write before any validation/persistence runs.
        $viewer = $this->userWithPerms($shop, ['settings.view'], '9824400003');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->patch(self::ERP . '/settings/shop', ['business_name' => 'Hacked']))->assertForbidden();
    }
}
