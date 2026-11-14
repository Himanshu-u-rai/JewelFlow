<?php

namespace App\Observers;

use App\Models\User;

/**
 * is_active (what login and EnsureAccountIsActive read) and employment_status (why) must agree:
 *   employment_status = 'active'                  <-> is_active = true
 *   employment_status = 'suspended'|'terminated'  <-> is_active = false
 *
 * A save that would break this is REFUSED, not corrected (audit N23): silently rewriting is_active
 * undid any "disable by is_active only" call. Callers set both columns together. The database CHECK
 * users_active_matches_employment_check refuses the same thing for raw updates that skip this observer.
 */
class UserObserver
{
    public function saving(User $user): void
    {
        if (! $user->isDirty(['is_active', 'employment_status'])) {
            return;
        }

        // A new row that leaves employment_status unset gets the column default, 'active'.
        $status = $user->employment_status ?? 'active';

        if ((bool) $user->is_active !== ($status === 'active')) {
            throw new \DomainException(
                "User {$user->id}: is_active=" . ($user->is_active ? 'true' : 'false')
                . " contradicts employment_status={$status}; set both together."
            );
        }
    }
}
