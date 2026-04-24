<?php

namespace App\Services;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use Illuminate\Http\Request;

class PlatformAuditService
{
    public function log(
        ?PlatformAdmin $actor,
        string $action,
        string $targetType,
        int|string|null $targetId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
        ?Request $request = null
    ): void {
        $actorId = $actor?->id ?? $this->resolveSystemActorId();
        if (!$actorId) {
            return;
        }

        PlatformAuditLog::create([
            'actor_admin_id' => $actorId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => (int) ($targetId ?? 0),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    public function logFailedAdminLogin(string $mobileNumber, string $reason, ?Request $request = null): void
    {
        $targetAdminId = PlatformAdmin::query()
            ->where('mobile_number', $mobileNumber)
            ->value('id');
        $actorId = $targetAdminId ?: $this->resolveSystemActorId();
        if (!$actorId) {
            return;
        }

        PlatformAuditLog::create([
            'actor_admin_id' => $actorId,
            'action' => 'platform_admin.login_failed',
            'target_type' => PlatformAdmin::class,
            'target_id' => (int) ($targetAdminId ?: $actorId),
            'before' => null,
            'after' => ['mobile_number' => $mobileNumber, 'reason' => $reason],
            'reason' => $reason,
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'created_at' => now(),
        ]);
    }

    private function resolveSystemActorId(): ?int
    {
        return PlatformAdmin::query()
            ->where('role', 'super_admin')
            ->orderBy('id')
            ->value('id');
    }
}
