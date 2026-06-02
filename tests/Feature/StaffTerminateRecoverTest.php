<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression: the "solid staff removal and recovery" flow was lost — destroy()
 * had reverted to a hard delete, orphaning attribution on past invoices/cash/
 * audit and making recovery impossible. Removal must now soft-terminate
 * (employment_status='terminated', is_active=false) without deleting the row,
 * and reactivate() must restore a removed member.
 */
class StaffTerminateRecoverTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grantStaffManage(User $owner): void
    {
        // Test tenants seed an owner role with no permissions; production seeds
        // all 52. Grant the one this flow gates on.
        $perm = \App\Models\Permission::firstOrCreate(
            ['name' => 'staff.manage'],
            ['display_name' => 'Manage Staff', 'group' => 'staff'],
        );
        $role = Role::withoutGlobalScopes()->findOrFail($owner->role_id);
        $role->permissions()->syncWithoutDetaching([$perm->id]);
    }

    private function makeStaff(User $owner, int $shopId): User
    {
        $role = Role::where('shop_id', $shopId)->where('name', '!=', 'owner')->first();
        if (! $role) {
            $role = new Role();
            $role->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shopId]);
            $role->save();
        }

        return User::create([
            'name'          => 'Removable Staff',
            'mobile_number' => '9876500099',
            'password'      => Hash::make('Secret123!'),
            'shop_id'       => $shopId,
            'role_id'       => $role->id,
        ]);
    }

    public function test_routes_exist(): void
    {
        $this->assertTrue(Route::has('staff.destroy'));
        $this->assertTrue(Route::has('staff.reactivate'));
    }

    public function test_destroy_terminates_without_deleting(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantStaffManage($owner);
        $staff = $this->makeStaff($owner, $shop->id);

        $this->actingAs($owner)->delete(route('staff.destroy', $staff));

        $fresh = User::find($staff->id);
        $this->assertNotNull($fresh, 'staff row must NOT be hard-deleted');
        $this->assertSame('terminated', $fresh->employment_status);
        $this->assertFalse((bool) $fresh->is_active, 'terminated staff must be inactive (cannot log in)');
        $this->assertNotNull($fresh->terminated_at);
    }

    public function test_reactivate_recovers_a_terminated_member(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantStaffManage($owner);
        $staff = $this->makeStaff($owner, $shop->id);

        $staff->forceFill([
            'employment_status' => 'terminated',
            'terminated_at'     => now(),
            'is_active'         => false,
        ])->save();

        $this->actingAs($owner)->patch(route('staff.reactivate', $staff));

        $fresh = User::find($staff->id);
        $this->assertSame('active', $fresh->employment_status);
        $this->assertTrue((bool) $fresh->is_active, 'recovered staff must be active again');
        $this->assertNotNull($fresh->reactivated_at);
    }

    public function test_cannot_reactivate_across_shops(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB] = $this->createRetailerTenant();
        $this->grantStaffManage($ownerB); // so 403 comes from the shop guard, not the gate
        $staff = $this->makeStaff($ownerA, $shopA->id);
        $staff->forceFill(['employment_status' => 'terminated', 'is_active' => false])->save();

        $this->actingAs($ownerB)->patch(route('staff.reactivate', $staff))->assertForbidden();

        $this->assertSame('terminated', User::find($staff->id)->employment_status,
            'a foreign-shop owner must not recover another shop\'s staff');
    }
}
