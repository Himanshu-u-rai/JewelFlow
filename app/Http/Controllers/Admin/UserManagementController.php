<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(Request $request): View
    {
        $query = User::query()
            ->with([
                'shop',
                'role' => fn ($builder) => $builder->withoutTenant(),
            ])
            ->when($request->filled('q'), function ($builder) use ($request) {
                $q = trim((string) $request->q);
                $builder->where(function ($inner) use ($q) {
                    $inner->where('mobile_number', 'like', "%{$q}%")
                        ->orWhere('name', 'ilike', "%{$q}%")
                        ->orWhere('email', 'ilike', "%{$q}%")
                        ->orWhere('first_name', 'ilike', "%{$q}%")
                        ->orWhere('last_name', 'ilike', "%{$q}%");
                });
            })
            ->when($request->filled('status'), function ($builder) use ($request) {
                if ($request->status === 'active') {
                    $builder->active();
                } elseif ($request->status === 'inactive') {
                    $builder->inactive();
                }
            })
            ->when($request->filled('scope'), function ($builder) use ($request) {
                if ($request->scope === 'shop_users') {
                    $builder->whereNotNull('shop_id');
                }
                if ($request->scope === 'super_admin') {
                    $builder->whereRaw('1 = 0');
                }
            });

        $users = $query->latest()->paginate(25)->withQueryString();

        return view('super-admin.users.index', compact('users'));
    }

    public function show(User $user): View
    {
        $user->load([
            'shop',
            'role' => fn ($builder) => $builder->withoutTenant(),
        ]);
        return view('super-admin.users.show', compact('user'));
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $before = ['is_active' => (bool) $user->is_active];

        $user->update([
            'is_active' => $this->dbBool((bool) $validated['is_active']),
        ]);
        $user->refresh();

        $this->audit->log(
            auth('platform_admin')->user(),
            'tenant_user.status_changed',
            User::class,
            $user->id,
            $before,
            ['is_active' => (bool) $user->fresh()->is_active],
            null,
            $request
        );

        return back()->with('success', $user->is_active ? 'User activated.' : 'User deactivated.');
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->audit->log(
            auth('platform_admin')->user(),
            'tenant_user.password_reset',
            User::class,
            $user->id,
            null,
            ['password_reset' => true],
            'Platform admin forced reset',
            $request
        );

        return back()->with('success', 'Password reset successfully.');
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $role = Role::withoutTenant()->findOrFail((int) $validated['role_id']);
        if ($user->shop_id !== $role->shop_id) {
            return back()->withErrors(['role_id' => 'Role does not belong to the user shop.']);
        }

        $before = ['role_id' => $user->role_id];
        $user->update(['role_id' => $role->id]);

        $this->audit->log(
            auth('platform_admin')->user(),
            'tenant_user.role_changed',
            User::class,
            $user->id,
            $before,
            ['role_id' => $role->id],
            $validated['reason'] ?? null,
            $request
        );

        return back()->with('success', 'User role updated.');
    }

    private function dbBool(bool $value)
    {
        return $value;
    }
}
