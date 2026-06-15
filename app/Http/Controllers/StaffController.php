<?php

namespace App\Http\Controllers;

use App\Http\Concerns\RespondsDynamically;
use App\Models\User;
use App\Models\Role;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StaffController extends Controller
{
    use RespondsDynamically;

    public function index()
    {
        return redirect()->route('settings.edit', ['tab' => 'staff']);
    }
    
    public function create()
    {
        $shop = auth()->user()->shop;

        if (!$shop->canAddStaff()) {
            $limit = $shop->staffLimit();
            return redirect()->route('settings.edit', ['tab' => 'staff'])
                ->with('error', "Staff limit reached ({$limit} accounts allowed on your plan). Remove an existing member or upgrade your plan.");
        }

        // Scope roles to this shop only, exclude owner
        $roles = Role::where('shop_id', auth()->user()->shop_id)
            ->where('name', '!=', 'owner')
            ->get();

        return view('staff.create', compact('roles', 'shop'));
    }

    public function store(Request $request)
    {
        $shop = auth()->user()->shop;

        // Re-check limit at store time (prevents race condition / direct POST bypass)
        if (!$shop->canAddStaff()) {
            $limit = $shop->staffLimit();
            return back()->with('error', "Staff limit reached ({$limit} accounts allowed on your plan).");
        }

        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Mobile is the global login identity (DB has a global unique on
            // mobile_number, and login resolves users by mobile across all
            // shops). The check MUST be global — a per-shop check let a globally
            // duplicate mobile pass validation and then 500 on the DB constraint.
            'mobile_number' => ['required', 'digits:10', Rule::unique('users', 'mobile_number')],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('shop_id', $shopId)],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')
                    ->where('shop_id', auth()->user()->shop_id)
                    ->where('name', '!=', 'owner'),
            ],
        ], [
            'mobile_number.unique' => 'This mobile number is already registered. Each mobile number can belong to only one account.',
        ]);

        $role = Role::where('shop_id', $shopId)->find($validated['role_id']);
        
        $user = User::create([
            'name' => $validated['name'],
            'mobile_number' => $validated['mobile_number'],
            // email is nullable+optional, so it may be absent from $validated.
            'email' => $validated['email'] ?? null,
            'password' => Hash::make($validated['password']),
            'shop_id' => $shopId,
            'role_id' => $validated['role_id'],
        ]);
        
        AuditLog::create([
            'shop_id' => $shopId,
            'user_id' => auth()->id(),
            'action' => 'staff_created',
            'model_type' => 'User',
            'model_id' => $user->id,
            'description' => "Added staff: " . ($user->name ?? $user->mobile_number) . " ({$role->display_name})",
        ]);
        
        return redirect()->route('settings.edit', ['tab' => 'staff'])
            ->with('success', 'Staff member added successfully.');
    }
    
    /**
     * Guard every per-staff action against two attacks that route-model binding
     * cannot stop on its own (User has no BelongsToShop global scope):
     *
     *   1. Cross-shop access — the {staff} id could belong to another shop.
     *   2. Owner targeting — the owner must never be editable/removable through
     *      staff management, no matter who holds `staff.manage`. Today the
     *      permission is owner-only, but it is grantable to managers from the
     *      Roles UI; without this guard a manager could then demote, lock out,
     *      or reset the password of the shop owner. The owner's account is
     *      managed only via Profile (self) — never via staff management.
     *
     * Aborts 403/404 on violation; returns normally when the target is safe.
     */
    private function guardStaffTarget(User $staff): void
    {
        if ($staff->shop_id !== auth()->user()->shop_id) {
            abort(403);
        }

        if ($staff->role?->name === 'owner') {
            abort(403, 'The shop owner account cannot be managed from staff management.');
        }
    }

    public function edit(User $staff)
    {
        $this->guardStaffTarget($staff);

        // Scope roles to this shop only, exclude owner (can't promote to owner)
        $roles = Role::where('shop_id', auth()->user()->shop_id)
            ->where('name', '!=', 'owner')
            ->get();

        return view('staff.edit', compact('staff', 'roles'));
    }

    public function update(Request $request, User $staff)
    {
        $this->guardStaffTarget($staff);

        $shopId = auth()->user()->shop_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Global uniqueness — see store(): mobile is the global login identity.
            'mobile_number' => ['required', 'digits:10', Rule::unique('users', 'mobile_number')->ignore($staff->id)],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->where('shop_id', $shopId)->ignore($staff->id)],
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')
                    ->where('shop_id', auth()->user()->shop_id)
                    ->where('name', '!=', 'owner'),
            ],
            'password' => ['nullable', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ], [
            'mobile_number.unique' => 'This mobile number is already registered. Each mobile number can belong to only one account.',
        ]);
        
        $staff->name = $validated['name'];
        $staff->mobile_number = $validated['mobile_number'];
        // email is nullable+optional — absent from $validated when not supplied.
        // Coalesce to null so saving a staff member without an email clears it
        // rather than throwing an undefined-key error.
        $staff->email = $validated['email'] ?? null;
        $staff->role_id = $validated['role_id'];
        
        if (!empty($validated['password'])) {
            $staff->password = Hash::make($validated['password']);
        }
        
        $staff->save();
        
        AuditLog::create([
            'shop_id' => auth()->user()->shop_id,
            'user_id' => auth()->id(),
            'action' => 'staff_updated',
            'model_type' => 'User',
            'model_id' => $staff->id,
            'description' => "Updated staff: " . ($staff->name ?? $staff->mobile_number),
        ]);
        
        return redirect()->route('settings.edit', ['tab' => 'staff'])
            ->with('success', 'Staff member updated successfully.');
    }
    
    public function destroy(User $staff)
    {
        $this->guardStaffTarget($staff);

        // Cannot remove yourself
        if ($staff->id === auth()->id()) {
            return $this->dynamicRedirect('settings.edit', ['tab' => 'staff'], 'You cannot remove your own account.', 'error');
        }

        // Soft removal: TERMINATE the employment rather than hard-deleting the
        // user. A hard delete is irreversible and orphans the staff member's
        // attribution on past invoices/cash/audit (FK set-null). Termination
        // preserves the record, disables login (observer forces is_active=false
        // for 'terminated'), and is fully recoverable via reactivate().
        $name = $staff->name ?? $staff->mobile_number;
        $staff->tokens()->delete(); // revoke active mobile/API sessions immediately

        $staff->forceFill([
            'employment_status'         => 'terminated',
            'terminated_at'             => now(),
            'terminated_by_user_id'     => auth()->id(),
            'terminated_with_role_name' => $staff->role?->name,
            'is_active'                 => false,
        ])->save();

        AuditLog::create([
            'shop_id' => auth()->user()->shop_id,
            'user_id' => auth()->id(),
            'action' => 'staff_terminated',
            'model_type' => 'User',
            'model_id' => $staff->id,
            'description' => "Removed (terminated) staff: {$name}",
        ]);

        return $this->dynamicRedirect('settings.edit', ['tab' => 'staff'], 'Staff member removed. You can recover them anytime from the staff list.');
    }

    /**
     * Recover a previously-removed (terminated) staff member — restores login
     * access and re-counts them against the plan's staff limit.
     */
    public function reactivate(User $staff)
    {
        $this->guardStaffTarget($staff);

        if ($staff->employment_status !== 'terminated') {
            return $this->dynamicRedirect('settings.edit', ['tab' => 'staff'], 'This staff member is already active.', 'error');
        }

        // A recovered staff member counts toward the active-staff limit again.
        if (! $staff->shop->canAddStaff()) {
            $limit = $staff->shop->staffLimit();
            return $this->dynamicRedirect('settings.edit', ['tab' => 'staff'], "Staff limit reached ({$limit} accounts allowed on your plan). Remove another member before recovering this one.", 'error');
        }

        $name = $staff->name ?? $staff->mobile_number;
        $staff->forceFill([
            'employment_status' => 'active',
            'reactivated_at'    => now(),
            'is_active'         => true,
        ])->save();

        AuditLog::create([
            'shop_id' => auth()->user()->shop_id,
            'user_id' => auth()->id(),
            'action' => 'staff_reactivated',
            'model_type' => 'User',
            'model_id' => $staff->id,
            'description' => "Recovered staff: {$name}",
        ]);

        return $this->dynamicRedirect('settings.edit', ['tab' => 'staff'], 'Staff member recovered successfully.');
    }
}
