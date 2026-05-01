<?php

namespace App\Services\Mobile;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MobileSessionSeatService
{
    private const TOKEN_NAME = 'mobile-app';
    private const SEAT_SCOPE = 'non_owner';
    private const ACTIVE_WINDOW_HOURS = 24;

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

        $this->pruneStaleTokens();

        // Owners always allowed — one web + one mobile simultaneously.
        if ($this->isOwner($loginUser)) {
            return [
                'allowed'         => true,
                'active_sessions' => 0,
                'session_limit'   => $sessionLimit,
                'reason_code'     => null,
                'seat_scope'      => self::SEAT_SCOPE,
            ];
        }

        // Non-owners: kill any active web sessions for this user so only
        // one session (the new mobile one) remains.
        $this->killWebSessionsForUser($loginUser);

        $activeOtherUsers = $this->activeSeatUsersQuery($shop)
            ->where('users.id', '!=', $loginUser->id)
            ->distinct('users.id')
            ->count('users.id');

        $allowed = $sessionLimit === -1 || $activeOtherUsers < $sessionLimit;

        return [
            'allowed'         => $allowed,
            'active_sessions' => $activeOtherUsers,
            'session_limit'   => $sessionLimit,
            'reason_code'     => $allowed ? null : 'mobile_session_limit_reached',
            'seat_scope'      => self::SEAT_SCOPE,
        ];
    }

    /**
     * Kill all web sessions for a non-owner user so their web browser
     * is logged out the moment they sign in on mobile.
     */
    private function killWebSessionsForUser(User $user): void
    {
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();
    }

    private function pruneStaleTokens(): void
    {
        DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('name', self::TOKEN_NAME)
            ->whereRaw('COALESCE(last_used_at, created_at) < ?', [$this->activeWindowStart()])
            ->delete();
    }

    private function activeSeatUsersQuery(Shop $shop)
    {
        return DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join('personal_access_tokens', function ($join): void {
                $join->on('personal_access_tokens.tokenable_id', '=', 'users.id')
                    ->where('personal_access_tokens.tokenable_type', User::class)
                    ->where('personal_access_tokens.name', self::TOKEN_NAME);
            })
            ->where('users.shop_id', $shop->id)
            ->where('roles.name', '!=', 'owner')
            ->whereRaw('COALESCE(personal_access_tokens.last_used_at, personal_access_tokens.created_at) >= ?', [$this->activeWindowStart()]);
    }

    private function activeWindowStart()
    {
        return now()->subHours(self::ACTIVE_WINDOW_HOURS);
    }

    private function isOwner(User $user): bool
    {
        return DB::table('roles')
            ->where('id', $user->role_id)
            ->where('name', 'owner')
            ->exists();
    }
}
