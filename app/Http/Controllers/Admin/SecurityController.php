<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use App\Services\PlatformSecurityService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityController extends Controller
{
    public function __construct(
        private PlatformSecurityService $security,
        private PlatformAuditService $audit
    ) {
    }

    public function index(Request $request): View
    {
        $snapshot = $this->security->snapshot();

        $admin = auth('platform_admin')->user();
        if ($admin) {
            $this->audit->log(
                $admin,
                'admin.security_viewed',
                PlatformAdmin::class,
                $admin->id,
                null,
                ['since' => $snapshot['since']->toDateTimeString()],
                null,
                $request
            );
        }

        return view('super-admin.security.index', [
            'snapshot' => $snapshot,
        ]);
    }
}
