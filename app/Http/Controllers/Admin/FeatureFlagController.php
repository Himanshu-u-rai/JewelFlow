<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformFeatureFlag;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FeatureFlagController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(): View
    {
        $globalFlags = PlatformFeatureFlag::where('scope', 'global')
            ->orderBy('key')
            ->get();

        $shopOverrides = PlatformFeatureFlag::where('scope', 'shop')
            ->orderBy('key')
            ->orderBy('scope_id')
            ->get()
            ->groupBy('key');

        return view('super-admin.feature-flags.index', compact('globalFlags', 'shopOverrides'));
    }

    public function upsert(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'key'         => ['required', 'string', 'max:100'],
            'enabled'     => ['required', 'boolean'],
            'scope'       => ['required', 'string', 'in:global,shop'],
            'scope_id'    => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $admin = auth('platform_admin')->user();

        $scopeId = $validated['scope'] === 'shop' ? ($validated['scope_id'] ?? null) : null;

        $existing = PlatformFeatureFlag::where('key', $validated['key'])
            ->where('scope', $validated['scope'])
            ->where('scope_id', $scopeId)
            ->first();

        $before = $existing ? $existing->only(['key', 'enabled', 'scope', 'scope_id', 'description']) : null;

        $flag = PlatformFeatureFlag::updateOrCreate(
            [
                'key'      => $validated['key'],
                'scope'    => $validated['scope'],
                'scope_id' => $scopeId,
            ],
            [
                'enabled'    => (bool) $validated['enabled'],
                'description' => $validated['description'] ?? null,
                'updated_by' => $admin?->id,
            ]
        );

        PlatformFeatureFlag::forgetCache($validated['key'], $scopeId);

        $this->audit->log(
            $admin,
            $existing ? 'feature_flag.update' : 'feature_flag.create',
            PlatformFeatureFlag::class,
            $flag->id,
            $before,
            $flag->only(['key', 'enabled', 'scope', 'scope_id', 'description']),
            null,
            $request
        );

        return back()->with('success', 'Feature flag saved.');
    }

    public function destroy(PlatformFeatureFlag $flag): RedirectResponse
    {
        $admin  = auth('platform_admin')->user();
        $before = $flag->only(['key', 'enabled', 'scope', 'scope_id', 'description']);

        $flag->delete();

        PlatformFeatureFlag::forgetCache($before['key'], $before['scope_id'] ?? null);

        $this->audit->log(
            $admin,
            'feature_flag.delete',
            PlatformFeatureFlag::class,
            $flag->id,
            $before,
            null,
            null,
            request()
        );

        return back()->with('success', 'Feature flag deleted.');
    }
}
