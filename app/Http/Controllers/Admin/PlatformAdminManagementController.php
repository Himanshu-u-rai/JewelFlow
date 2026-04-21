<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Illuminate\View\View;

class PlatformAdminManagementController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(): View
    {
        $platformAdmins = PlatformAdmin::query()->latest()->paginate(25);
        return view('super-admin.platform-admins.index', compact('platformAdmins'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255', 'unique:platform_admins,email'],
            'mobile_number' => ['required', 'string', 'digits:10', 'unique:platform_admins,mobile_number'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'in:super_admin,platform_operator'],
        ]);

        $platformAdmin = PlatformAdmin::query()->create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
            'email' => $validated['email'] ?? null,
            'mobile_number' => $validated['mobile_number'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'is_active' => $this->dbBool(true),
            'password_changed_at' => now(),
        ]);

        $this->audit->log(
            auth('platform_admin')->user(),
            'platform_admin.created',
            PlatformAdmin::class,
            $platformAdmin->id,
            null,
            ['role' => $platformAdmin->role, 'mobile_number' => $platformAdmin->mobile_number],
            null,
            $request
        );

        return back()->with('success', 'Platform admin created.');
    }

    public function updateRole(Request $request, PlatformAdmin $platformAdmin): RedirectResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'in:super_admin,platform_operator'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $actor = auth('platform_admin')->user();
        if ($actor && $actor->id === $platformAdmin->id && $validated['role'] !== 'super_admin') {
            $this->audit->log(
                $actor,
                'platform_admin.role_change_blocked',
                PlatformAdmin::class,
                $platformAdmin->id,
                ['role' => $platformAdmin->role],
                ['role' => $validated['role']],
                'Self-demotion is blocked.',
                $request
            );
            return back()->withErrors(['role' => 'You cannot demote your own platform admin account.']);
        }

        $before = ['role' => $platformAdmin->role];
        try {
            $platformAdmin->update(['role' => $validated['role']]);
        } catch (LogicException $e) {
            $this->audit->log(
                $actor,
                'platform_admin.role_change_blocked',
                PlatformAdmin::class,
                $platformAdmin->id,
                $before,
                ['role' => $validated['role']],
                $e->getMessage(),
                $request
            );
            return back()->withErrors(['role' => $e->getMessage()]);
        }

        $this->audit->log(
            $actor,
            'platform_admin.role_changed',
            PlatformAdmin::class,
            $platformAdmin->id,
            $before,
            ['role' => $platformAdmin->fresh()->role],
            $validated['reason'] ?? null,
            $request
        );

        return back()->with('success', 'Platform admin role updated.');
    }

    public function resetPassword(Request $request, PlatformAdmin $platformAdmin): RedirectResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $platformAdmin->update([
            'password' => Hash::make($validated['password']),
            'password_changed_at' => now(),
        ]);

        $this->audit->log(
            auth('platform_admin')->user(),
            'platform_admin.password_reset',
            PlatformAdmin::class,
            $platformAdmin->id,
            null,
            ['password_reset' => true],
            $validated['reason'] ?? null,
            $request
        );

        return back()->with('success', 'Platform admin password reset.');
    }

    public function updateStatus(Request $request, PlatformAdmin $platformAdmin): RedirectResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $actor = auth('platform_admin')->user();
        if ($actor && $actor->id === $platformAdmin->id && !(bool) $validated['is_active']) {
            $this->audit->log(
                $actor,
                'platform_admin.status_change_blocked',
                PlatformAdmin::class,
                $platformAdmin->id,
                ['is_active' => (bool) $platformAdmin->is_active],
                ['is_active' => false],
                'Self-deactivation is blocked.',
                $request
            );
            return back()->withErrors(['status' => 'You cannot deactivate your own platform admin account.']);
        }

        $before = ['is_active' => (bool) $platformAdmin->is_active];
        try {
            $platformAdmin->update(['is_active' => $this->dbBool((bool) $validated['is_active'])]);
        } catch (LogicException $e) {
            $this->audit->log(
                $actor,
                'platform_admin.status_change_blocked',
                PlatformAdmin::class,
                $platformAdmin->id,
                $before,
                ['is_active' => (bool) $validated['is_active']],
                $e->getMessage(),
                $request
            );
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        $this->audit->log(
            $actor,
            'platform_admin.status_changed',
            PlatformAdmin::class,
            $platformAdmin->id,
            $before,
            ['is_active' => (bool) $platformAdmin->fresh()->is_active],
            $validated['reason'] ?? null,
            $request
        );

        return back()->with('success', $platformAdmin->is_active ? 'Platform admin activated.' : 'Platform admin deactivated.');
    }

    public function destroy(Request $request, PlatformAdmin $platformAdmin): RedirectResponse
    {
        $actor = auth('platform_admin')->user();
        if ($actor && $actor->id === $platformAdmin->id) {
            $this->audit->log(
                $actor,
                'platform_admin.delete_blocked',
                PlatformAdmin::class,
                $platformAdmin->id,
                null,
                null,
                'Self-deletion is blocked.',
                $request
            );
            return back()->withErrors(['delete' => 'You cannot delete your own platform admin account.']);
        }

        $before = $platformAdmin->only(['id', 'role', 'is_active', 'mobile_number', 'email']);
        try {
            $platformAdmin->delete();
        } catch (LogicException $e) {
            $this->audit->log(
                $actor,
                'platform_admin.delete_blocked',
                PlatformAdmin::class,
                $platformAdmin->id,
                $before,
                null,
                $e->getMessage(),
                $request
            );
            return back()->withErrors(['delete' => $e->getMessage()]);
        }

        $this->audit->log(
            $actor,
            'platform_admin.deleted',
            PlatformAdmin::class,
            (int) $before['id'],
            $before,
            null,
            $request->input('reason'),
            $request
        );

        return back()->with('success', 'Platform admin deleted.');
    }

    private function dbBool(bool $value)
    {
        return $value;
    }
}
