<?php

namespace App\Services\Web;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WebSessionSeatService
{
    private const SEAT_SCOPE = 'non_owner';

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

        // Active = last touched within Laravel's session lifetime window.
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

        $allowed = $sessionLimit === -1
            || $this->isOwner($loginUser)
            || $activeOtherUsers < $sessionLimit;

        return [
            'allowed'         => $allowed,
            'active_sessions' => $activeOtherUsers,
            'session_limit'   => $sessionLimit,
            'reason_code'     => $allowed ? null : 'web_session_limit_reached',
            'seat_scope'      => self::SEAT_SCOPE,
        ];
    }

    private function isOwner(User $user): bool
    {
        return DB::table('roles')
            ->where('id', $user->role_id)
            ->where('name', 'owner')
            ->exists();
    }
}
