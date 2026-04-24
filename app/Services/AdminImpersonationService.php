<?php

namespace App\Services;

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformImpersonationSession;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use LogicException;

class AdminImpersonationService
{
    public const SESSION_KEY = 'platform_impersonation_id';
    public const SESSION_EXPIRES_AT = 'platform_impersonation_expires_at';
    public const SESSION_ADMIN_ID = 'platform_impersonation_admin_id';

    public function start(PlatformAdmin $admin, User $user, ?int $ttlMinutes = null): PlatformImpersonationSession
    {
        if (!$admin->isSuperAdmin()) {
            throw new LogicException('Only super admins can impersonate tenants.');
        }

        if (!$user->shop_id) {
            throw new LogicException('Selected user is not attached to a shop.');
        }

        $existing = $this->currentRaw();
        if ($existing && !$existing->ended_at) {
            $this->stop($existing, $admin, 'restarted');
        }

        $ttlMinutes = $ttlMinutes ?? (int) config('impersonation.ttl_minutes', 30);
        if ($ttlMinutes < 1) {
            $ttlMinutes = 30;
        }

        $now = now();
        $expiresAt = $now->copy()->addMinutes($ttlMinutes);

        $session = PlatformImpersonationSession::create([
            'admin_id' => $admin->id,
            'shop_id' => $user->shop_id,
            'user_id' => $user->id,
            'started_at' => $now,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        Session::put(self::SESSION_KEY, $session->id);
        Session::put(self::SESSION_EXPIRES_AT, $expiresAt->getTimestamp());
        Session::put(self::SESSION_ADMIN_ID, $admin->id);

        Auth::guard('web')->loginUsingId($user->id, false);

        app(PlatformAuditService::class)->log(
            $admin,
            'admin.impersonation_started',
            User::class,
            $user->id,
            null,
            [
                'shop_id' => $user->shop_id,
                'expires_at' => $expiresAt->toDateTimeString(),
                'session_id' => $session->id,
            ],
            null,
            request()
        );

        return $session;
    }

    public function current(): ?PlatformImpersonationSession
    {
        $sessionId = Session::get(self::SESSION_KEY);
        if (!$sessionId) {
            return null;
        }

        $session = PlatformImpersonationSession::query()->find($sessionId);
        if (!$session) {
            $this->forgetSession();
            return null;
        }

        if ($session->ended_at) {
            $this->forgetSession();
            return null;
        }

        $adminId = Session::get(self::SESSION_ADMIN_ID);
        if ($adminId && Auth::guard('platform_admin')->id() !== $adminId) {
            $this->stop($session, null, 'admin_mismatch');
            return null;
        }

        if (!Auth::guard('platform_admin')->check()) {
            $this->stop($session, null, 'admin_logged_out');
            return null;
        }

        if ($session->expires_at && now()->greaterThan($session->expires_at)) {
            $this->stop($session, Auth::guard('platform_admin')->user(), 'expired');
            return null;
        }

        return $session;
    }

    public function stop(?PlatformImpersonationSession $session = null, ?PlatformAdmin $actor = null, ?string $reason = null): void
    {
        $session = $session ?? $this->currentRaw();
        if (!$session) {
            $this->forgetSession();
            return;
        }

        if (!$session->ended_at) {
            $session->ended_at = now();
            $session->save();
        }

        if (Auth::guard('web')->check() && Auth::guard('web')->id() === $session->user_id) {
            Auth::guard('web')->logout();
        }

        $this->forgetSession();

        $actor = $actor ?? Auth::guard('platform_admin')->user();
        app(PlatformAuditService::class)->log(
            $actor,
            'admin.impersonation_ended',
            User::class,
            $session->user_id,
            null,
            [
                'shop_id' => $session->shop_id,
                'session_id' => $session->id,
                'reason' => $reason ?? 'manual',
            ],
            $reason,
            request()
        );
    }

    public function bannerPayload(): ?array
    {
        $session = $this->current();
        if (!$session) {
            return null;
        }

        $shop = Shop::query()->select('id', 'name')->find($session->shop_id);
        $user = User::query()->select('id', 'name', 'mobile_number')->find($session->user_id);

        return [
            'session_id' => $session->id,
            'shop_id' => $session->shop_id,
            'shop_name' => $shop?->name ?? 'Unknown Shop',
            'user_name' => $user?->name ?: ($user?->mobile_number ?? 'User'),
            'expires_at' => $session->expires_at,
        ];
    }

    private function currentRaw(): ?PlatformImpersonationSession
    {
        $sessionId = Session::get(self::SESSION_KEY);
        if (!$sessionId) {
            return null;
        }

        return PlatformImpersonationSession::query()->find($sessionId);
    }

    private function forgetSession(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::forget(self::SESSION_EXPIRES_AT);
        Session::forget(self::SESSION_ADMIN_ID);
    }
}
