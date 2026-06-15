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

    /**
     * The owner account must never be manageable through staff management, no
     * matter who holds staff.manage. This is the load-bearing guard against a
     * manager (once granted staff.manage via the Roles UI) demoting, locking
     * out, or password-resetting the shop owner.
     */
    public function test_owner_cannot_be_terminated_via_staff_management(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantStaffManage($owner);

        // The acting user IS the owner; target the owner row itself.
        $this->actingAs($owner)->delete(route('staff.destroy', $owner))->assertForbidden();

        $fresh = User::find($owner->id);
        $this->assertSame('active', $fresh->employment_status ?? 'active', 'owner must stay active');
        $this->assertTrue((bool) $fresh->is_active, 'owner must never be terminated via staff management');
    }

    public function test_owner_cannot_be_edited_via_staff_management(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantStaffManage($owner);

        $this->actingAs($owner)->get(route('staff.edit', $owner))->assertForbidden();

        $staffRole = Role::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', '!=', 'owner')->first();
        if (! $staffRole) {
            $staffRole = tap(new Role())->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shop->id]);
            $staffRole->save();
        }

        // Attempt to DEMOTE the owner to a non-owner role via update — must 403.
        $this->actingAs($owner)->put(route('staff.update', $owner), [
            'name'          => 'Hacked Owner',
            'mobile_number' => $owner->mobile_number,
            'role_id'       => $staffRole->id,
        ])->assertForbidden();

        // role_id must be unchanged (still the owner role) — verify via raw column
        // to avoid the tenant-scoped Role relation returning null in test context.
        $this->assertSame($owner->role_id, (int) User::find($owner->id)->role_id,
            'owner role must be unchanged — no demotion via staff management');
    }

    /**
     * A second owner (if one ever exists) is equally protected — the guard keys
     * on the role name, not on "is this the current user".
     */
    public function test_a_second_owner_is_also_protected(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantStaffManage($owner);

        $ownerRole = Role::withoutGlobalScopes()->where('shop_id', $shop->id)->where('name', 'owner')->first();
        $this->assertNotNull($ownerRole);

        $secondOwner = User::create([
            'name'          => 'Second Owner',
            'mobile_number' => '9876511122',
            'password'      => Hash::make('Secret123!'),
            'shop_id'       => $shop->id,
            'role_id'       => $ownerRole->id,
        ]);

        $this->actingAs($owner)->delete(route('staff.destroy', $secondOwner))->assertForbidden();
        $this->assertTrue((bool) User::find($secondOwner->id)->is_active);
    }

    public function test_cannot_remove_your_own_account(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantStaffManage($owner);

        // Make a non-owner self to isolate the self-removal guard from the
        // owner guard: a manager-self who holds staff.manage.
        $managerRole = Role::where('shop_id', $shop->id)->where('name', '!=', 'owner')->first()
            ?? tap(new Role())->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shop->id]);
        if (! $managerRole->exists) { $managerRole->save(); }
        $managerRole->permissions()->syncWithoutDetaching([
            \App\Models\Permission::firstOrCreate(['name' => 'staff.manage'], ['display_name' => 'Manage Staff', 'group' => 'staff'])->id,
        ]);

        $self = User::create([
            'name'          => 'Manager Self',
            'mobile_number' => '9876522233',
            'password'      => Hash::make('Secret123!'),
            'shop_id'       => $shop->id,
            'role_id'       => $managerRole->id,
        ]);

        $this->actingAs($self)->delete(route('staff.destroy', $self));

        $this->assertSame('active', User::find($self->id)->employment_status ?? 'active',
            'a user must not be able to remove their own account');
    }

    public function test_new_staff_is_active_and_can_be_listed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantStaffManage($owner);

        $role = Role::where('shop_id', $shop->id)->where('name', '!=', 'owner')->first()
            ?? tap(new Role())->forceFill(['name' => 'staff', 'display_name' => 'Staff', 'shop_id' => $shop->id]);
        if (! $role->exists) { $role->save(); }

        $this->actingAs($owner)->post(route('staff.store'), [
            'name'                  => 'Fresh Hire',
            'mobile_number'         => '9876533344',
            'password'              => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'role_id'               => $role->id,
        ])->assertRedirect();

        $created = User::where('mobile_number', '9876533344')->first();
        $this->assertNotNull($created);
        $this->assertSame($shop->id, $created->shop_id, 'new staff must be scoped to the creating shop');
        $this->assertTrue((bool) $created->is_active, 'new staff must be active (DB default)');
        $this->assertSame('active', $created->employment_status ?? 'active');
    }
}
