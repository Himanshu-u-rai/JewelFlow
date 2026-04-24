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
        $shop   = auth()->user()->shop;
        $shopId = $shop->id;

        $staff = User::with('role')
            ->where('shop_id', $shopId)
            ->orderByRaw('COALESCE(name, mobile_number) ASC')
            ->get();

        $staffLimit = $shop->staffLimit();
        $staffCount = $shop->currentStaffCount();

        return view('staff.index', compact('staff', 'staffLimit', 'staffCount'));
    }
    
    public function create()
    {
        $shop = auth()->user()->shop;

        if (!$shop->canAddStaff()) {
            $limit = $shop->staffLimit();
            return redirect()->route('staff.index')
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

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'mobile_number' => 'required|string|max:20|unique:users,mobile_number',
            'email' => 'nullable|email|max:255|unique:users,email',
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')
                    ->where('shop_id', auth()->user()->shop_id)
                    ->where('name', '!=', 'owner'),
            ],
        ]);

        $shopId = auth()->user()->shop_id;
        $role = Role::where('shop_id', $shopId)->find($validated['role_id']);
        
        $user = User::create([
            'name' => $validated['name'],
            'mobile_number' => $validated['mobile_number'],
            'email' => $validated['email'],
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
        
        // Check if coming from settings page
        if (str_contains(url()->previous(), 'settings')) {
            return redirect()->route('settings.edit', ['tab' => 'staff'])
                ->with('success', 'Staff member added successfully.');
        }
        
        return redirect()->route('staff.index')
            ->with('success', 'Staff member added successfully.');
    }
    
    public function edit(User $staff)
    {
        // Ensure staff belongs to same shop
        if ($staff->shop_id !== auth()->user()->shop_id) {
            abort(403);
        }

        // Scope roles to this shop only, exclude owner (can't promote to owner)
        $roles = Role::where('shop_id', auth()->user()->shop_id)
            ->where('name', '!=', 'owner')
            ->get();

        return view('staff.edit', compact('staff', 'roles'));
    }
    
    public function update(Request $request, User $staff)
    {
        // Ensure staff belongs to same shop
        if ($staff->shop_id !== auth()->user()->shop_id) {
            abort(403);
        }
        
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'mobile_number' => 'required|string|max:20|unique:users,mobile_number,' . $staff->id,
            'email' => 'nullable|email|max:255|unique:users,email,' . $staff->id,
            'role_id' => [
                'required',
                Rule::exists('roles', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'password' => ['nullable', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);
        
        $staff->name = $validated['name'];
        $staff->mobile_number = $validated['mobile_number'];
        $staff->email = $validated['email'];
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
        
        return redirect()->route('staff.index')
            ->with('success', 'Staff member updated successfully.');
    }
    
    public function destroy(User $staff)
    {
        // Ensure staff belongs to same shop
        if ($staff->shop_id !== auth()->user()->shop_id) {
            abort(403);
        }
        
        // Cannot delete yourself
        if ($staff->id === auth()->id()) {
            return $this->dynamicRedirect('staff.index', [], 'You cannot delete your own account.', 'error');
        }
        
        $name = $staff->name ?? $staff->mobile_number;
        $staff->delete();
        
        AuditLog::create([
            'shop_id' => auth()->user()->shop_id,
            'user_id' => auth()->id(),
            'action' => 'staff_deleted',
            'model_type' => 'User',
            'model_id' => $staff->id,
            'description' => "Removed staff: {$name}",
        ]);
        
        return $this->dynamicRedirect('staff.index', [], 'Staff member removed successfully.');
    }
}
