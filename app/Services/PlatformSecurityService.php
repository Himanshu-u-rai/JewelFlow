<?php

namespace App\Services;

use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\PlatformImpersonationSession;
use Carbon\Carbon;

class PlatformSecurityService
{
    public function snapshot(?Carbon $since = null): array
    {
        $since = $since ?: now()->subDay();

        $failedLogins = PlatformAuditLog::query()
            ->where('action', 'platform_admin.login_failed')
            ->where('created_at', '>=', $since)
            ->count();

        $passwordResets = PlatformAuditLog::query()
            ->where('action', 'platform_admin.password_reset')
            ->where('created_at', '>=', $since)
            ->count();

        $billingBlocks = PlatformAuditLog::query()
            ->where('action', 'subscription.write_blocked')
            ->where('created_at', '>=', $since)
            ->count();

        $suspiciousActions = PlatformAuditLog::query()
            ->whereIn('action', [
                'platform_admin.role_change_blocked',
                'platform_admin.status_change_blocked',
                'platform_admin.delete_blocked',
            ])
            ->where('created_at', '>=', $since)
            ->latest('id')
            ->take(20)
            ->get();

        $recentImpersonations = PlatformImpersonationSession::query()
            ->latest('started_at')
            ->take(10)
            ->get();

        $recentAudit = PlatformAuditLog::query()
            ->latest('id')
            ->take(25)
            ->get();

        return [
            'since' => $since,
            'failed_logins' => $failedLogins,
            'password_resets' => $passwordResets,
            'billing_blocks' => $billingBlocks,
            'suspicious_actions' => $suspiciousActions,
            'recent_impersonations' => $recentImpersonations,
            'recent_audit' => $recentAudit,
        ];
    }
}
