<?php

namespace Tests\Feature\Rbac;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Owner 2026-11-11 ("owner is not able to see /report/cash ... we don't want weak rbac"): there is no owner bypass in
 * Gate::before; an owner's access is exactly the owner role's permissions. A migration that adds a permission must
 * therefore hand it to every owner role outright, not only to roles that happen to hold a related older permission,
 * and every permission a route asks for must exist, or nobody (owner included) can ever pass that route.
 */
class OwnerHoldsEveryPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        '2026_11_07_100000_add_module_permissions.php',
        '2026_11_07_110000_add_shop_money_permission.php',
    ];

    public function test_permission_migrations_give_every_new_switch_to_every_owner(): void
    {
        $shop = Shop::create([
            'name' => 'Lean Owner', 'shop_type' => 'retailer', 'phone' => '9390014000',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9390014000',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        // An owner role whose older permissions do not include the "source" ones the migrations copy from.
        $owner = new Role();
        $owner->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id])->save();
        $owner->permissions()->sync(Permission::where('name', 'inventory.view')->pluck('id'));

        foreach (self::MIGRATIONS as $file) {
            (require database_path('migrations/' . $file))->up();
        }

        $added = ['reports.money', 'schemes.view', 'schemes.manage', 'quick_bills.view', 'installments.view'];
        $has = DB::table('role_permission')->join('permissions', 'permissions.id', '=', 'role_permission.permission_id')
            ->where('role_id', $owner->id)->pluck('permissions.name')->all();
        foreach ($added as $name) {
            $this->assertContains($name, $has, "owner gets {$name}");
        }
    }

    public function test_every_permission_a_route_requires_exists(): void
    {
        $existing = Permission::query()->pluck('name')->all();
        $missing = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && preg_match('/^can:([a-z_]+\.[a-z_.]+)$/', $mw, $m) && ! in_array($m[1], $existing, true)) {
                    $missing[$m[1]][] = $route->uri();
                }
            }
        }
        $this->assertSame([], array_map(fn ($uris) => array_slice(array_unique($uris), 0, 3), $missing),
            'routes require permissions that no migration creates: nobody, owner included, could open them');
    }
}
