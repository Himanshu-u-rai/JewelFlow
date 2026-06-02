<?php

namespace Tests\Feature;

use App\Http\Controllers\SettingsController;
use App\Models\Permission;
use App\Models\Role;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Dhiran is a separate product (its own subdomain). It must not leak into the
 * JewelFlow product surface. The Roles settings tab was showing the Dhiran
 * permission group because a guard checked a label ('Dhiran (Gold Loans)') that
 * never matched the real group key ('dhiran').
 */
class DhiranIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grant(\App\Models\User $user, string ...$permissions): void
    {
        $role = Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($permissions as $p) {
            $role->givePermission($p);
        }
    }

    public function test_roles_tab_does_not_show_dhiran_permissions(): void
    {
        [$owner] = $this->createRetailerTenant(); // owner role → may see the Roles tab
        $this->grant($owner, 'settings.view'); // route gate (owner has all perms in prod)

        $response = $this->actingAs($owner)->get(route('settings.edit', ['tab' => 'roles']));
        $response->assertOk();

        // Dhiran permission display names must NOT appear in the JewelFlow Roles UI.
        $response->assertDontSee('Create Gold Loans', false);
        $response->assertDontSee('View Gold Loans', false);
        $response->assertDontSee('Manage Dhiran Settings', false);
        $response->assertDontSee('Forfeit Gold Loans', false);
    }

    public function test_saving_a_role_preserves_its_hidden_dhiran_permissions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($owner, $shop) {
            // A non-owner role seeded with a dhiran perm + a sales perm.
            $manager = new Role();
            $manager->forceFill(['name' => 'manager', 'display_name' => 'Manager', 'shop_id' => $shop->id]);
            $manager->save();

            $dhiranView  = Permission::where('name', 'dhiran.view')->firstOrFail();
            $salesView   = Permission::where('name', 'sales.view')->firstOrFail();
            $salesCreate = Permission::where('name', 'sales.create')->firstOrFail();
            $manager->permissions()->sync([$dhiranView->id, $salesView->id]);

            // Save the role from the JewelFlow UI with ONLY a non-dhiran perm
            // (dhiran is hidden from the form, so it's never submitted).
            $request = Request::create(route('settings.update.role', $manager), 'PATCH', [
                'permissions' => [$salesCreate->id],
            ]);
            $this->actingAs($owner);
            app(SettingsController::class)->updateRolePermissions($request, $manager);

            $names = $manager->fresh()->permissions()->pluck('name');
            // The hidden dhiran perm must survive the save…
            $this->assertTrue($names->contains('dhiran.view'), 'dhiran.view must be preserved across save');
            // …the submitted non-dhiran perm is set…
            $this->assertTrue($names->contains('sales.create'));
            // …and the unchecked non-dhiran perm is removed (normal sync behaviour).
            $this->assertFalse($names->contains('sales.view'));
        });
    }
}
