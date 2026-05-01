<?php

namespace App\Services\Web;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WebSessionSeatService
{
    private const SEAT_SCOPE = 'non_owner';
    private const MOBILE_TOKEN_NAME = 'mobile-app';

    /**
     * @return array{
     *     allowed: bool,
     *     active_sessions: int,
     *     session_limit: int,
     *     reason_code: string|null,
     *     seat_scope: string
     * }
     */
    public function evaluate(Shop $shop, User $loginUser): array
    {
        $sessionLimit = $shop->staffLimit();

        // Owners get one web + one mobile session simultaneously — no cross-channel block.
        if ($this->isOwner($loginUser)) {
            return [
                'allowed'         => true,
                'active_sessions' => 0,
                'session_limit'   => $sessionLimit,
                'reason_code'     => null,
                'seat_scope'      => self::SEAT_SCOPE,
            ];
        }

        // Non-owners: block web login if this user already has an active mobile session.
        $hasMobileSession = $this->hasActiveMobileToken($loginUser);
        if ($hasMobileSession) {
            return [
                'allowed'         => false,
                'active_sessions' => 1,
                'session_limit'   => 1,
                'reason_code'     => 'already_on_mobile',
                'seat_scope'      => self::SEAT_SCOPE,
            ];
        }

        // Also enforce the shop-wide web seat limit (other users).
        $activeWindowStart = now()->timestamp - (config('session.lifetime', 120) * 60);

        $activeOtherUsers = DB::table('sessions')
            ->join('users', 'users.id', '=', 'sessions.user_id')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('users.shop_id', $shop->id)
            ->where('roles.name', '!=', 'owner')
            ->where('sessions.user_id', '!=', $loginUser->id)
            ->where('sessions.last_activity', '>=', $activeWindowStart)
            ->distinct('sessions.user_id')
            ->count('sessions.user_id');

        $allowed = $sessionLimit === -1 || $activeOtherUsers < $sessionLimit;

        return [
            'allowed'         => $allowed,
            'active_sessions' => $activeOtherUsers,
            'session_limit'   => $sessionLimit,
            'reason_code'     => $allowed ? null : 'web_session_limit_reached',
            'seat_scope'      => self::SEAT_SCOPE,
        ];
    }

    private function hasActiveMobileToken(User $user): bool
    {
        $activeWindowStart = now()->subHours(24);

        return DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $user->id)
            ->where('name', self::MOBILE_TOKEN_NAME)
            ->where(function ($q) use ($activeWindowStart) {
                $q->where('last_used_at', '>=', $activeWindowStart)
                  ->orWhere(function ($q2) use ($activeWindowStart) {
                      $q2->whereNull('last_used_at')
                         ->where('created_at', '>=', $activeWindowStart);
                  });
            })
            ->exists();
    }

    private function isOwner(User $user): bool
    {
        return DB::table('roles')
            ->where('id', $user->role_id)
            ->where('name', 'owner')
            ->exists();
    }
}
