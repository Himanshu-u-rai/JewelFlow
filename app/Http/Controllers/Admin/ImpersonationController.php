<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Models\User;
use App\Services\AdminImpersonationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use LogicException;

class ImpersonationController extends Controller
{
    public function __construct(private AdminImpersonationService $impersonation)
    {
    }

    public function start(Request $request, Shop $shop): RedirectResponse
    {
        $admin = auth('platform_admin')->user();
        if (!$admin || !$admin->isSuperAdmin()) {
            abort(403, 'Only super admins can impersonate tenants.');
        }

        $userId = $request->integer('user_id');
        if ($userId) {
            $user = User::query()
                ->where('shop_id', $shop->id)
                ->active()
                ->findOrFail($userId);
        } else {
            $user = User::query()
                ->where('shop_id', $shop->id)
                ->active()
                ->whereHas('role', fn ($q) => $q->where('name', 'owner'))
                ->first();

            if (!$user) {
                $user = User::query()
                    ->where('shop_id', $shop->id)
                    ->active()
                    ->orderBy('id')
                    ->first();
            }
        }

        if (!$user) {
            throw new LogicException('No active user found to impersonate.');
        }

        $this->impersonation->start($admin, $user);

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        $admin = auth('platform_admin')->user();
        if (!$admin) {
            abort(403, 'Not authorized.');
        }

        $this->impersonation->stop(null, $admin, 'manual');

        return redirect()->route('admin.dashboard')
            ->with('success', 'Impersonation ended.');
    }
}
