<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Platform-admin recovery codes — the MFA lockout fallback. Only hashes are
 * stored; the plaintext is flashed to the session and shown exactly once.
 * Regenerating replaces the whole set, invalidating any old codes.
 *
 * These routes live behind the MFA gate, so the admin is fully authenticated
 * (password + MFA) before they can view or rotate codes.
 */
class RecoveryCodeController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    private function admin(): PlatformAdmin
    {
        return Auth::guard('platform_admin')->user();
    }

    public function show(Request $request): View
    {
        $admin = $this->admin();

        return view('super-admin.auth.recovery-codes', [
            'plainCodes' => $request->session()->get('recovery_codes_plain'), // present only right after (re)generate
            'remaining' => count($admin->recovery_codes ?? []),
        ]);
    }

    public function regenerate(Request $request): RedirectResponse
    {
        $admin = $this->admin();
        $plain = $admin->generateRecoveryCodes();

        $this->audit->log($admin, 'platform_admin.recovery_codes_generated', PlatformAdmin::class, $admin->id, null, ['count' => count($plain)], null, $request);

        // Flash plaintext for a single render; never persisted in plaintext.
        return redirect()->route('admin.recovery-codes.show')
            ->with('recovery_codes_plain', $plain)
            ->with('status', 'Recovery codes generated. Save them now — they will not be shown again.');
    }
}
