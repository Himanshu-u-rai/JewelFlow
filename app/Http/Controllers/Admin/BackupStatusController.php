<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\TriggerBackupJob;
use App\Services\PlatformAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BackupStatusController extends Controller
{
    public function __construct(private PlatformAuditService $audit)
    {
    }

    public function index(): View
    {
        $logs = DB::table('platform_backup_log')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $latestSuccess = DB::table('platform_backup_log')
            ->where('status', 'success')
            ->orderByDesc('completed_at')
            ->first();

        $isOverdue = true;
        if ($latestSuccess && $latestSuccess->completed_at) {
            $isOverdue = now()->diffInHours($latestSuccess->completed_at) >= 25;
        }

        return view('super-admin.backup.index', compact('logs', 'latestSuccess', 'isOverdue'));
    }

    public function trigger(Request $request): RedirectResponse
    {
        $logId = DB::table('platform_backup_log')->insertGetId([
            'status'                 => 'pending',
            'type'                   => 'manual',
            'triggered_by_admin_id'  => auth('platform_admin')->id(),
            'created_at'             => now(),
            'updated_at'             => now(),
        ]);

        TriggerBackupJob::dispatch($logId);

        $this->audit->log(
            auth('platform_admin')->user(),
            'backup.trigger',
            'platform_backup_log',
            $logId,
            null,
            ['type' => 'manual'],
            null,
            $request
        );

        return back()->with('success', 'Backup job dispatched. Check status shortly.');
    }
}
