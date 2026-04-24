<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformAdmin;
use App\Services\PlatformAuditService;
use App\Services\TenantActivityMonitorService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TenantActivityController extends Controller
{
    public function __construct(
        private TenantActivityMonitorService $monitor,
        private PlatformAuditService $audit
    ) {
    }

    public function index(Request $request): View
    {
        $snapshot = $this->monitor->snapshot();

        $admin = auth('platform_admin')->user();
        if ($admin) {
            $this->audit->log(
                $admin,
                'admin.tenant_activity_viewed',
                PlatformAdmin::class,
                $admin->id,
                null,
                ['date' => $snapshot['date']],
                null,
                $request
            );
        }

        return view('super-admin.tenant-activity.index', [
            'snapshot' => $snapshot,
        ]);
    }
}
