<?php

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Enforces the invariant that is_active and employment_status are always
 * consistent on the User model.
 *
 * Valid combinations:
 *   employment_status = 'active'     ↔ is_active = true
 *   employment_status = 'suspended'  ↔ is_active = false
 *   employment_status = 'terminated' ↔ is_active = false
 *
 * If any save() would create an inconsistent state, this observer corrects
 * is_active to match employment_status and logs a warning so the offending
 * call site can be found and fixed.  Throwing an exception here would break
 * the request silently in production; logging + correcting is safer and still
 * surfaces the bug during development/code-review.
 */
class UserObserver
{
    public function saving(User $user): void
    {
        // Only enforce when either field is actually being changed.
        if (! $user->isDirty(['is_active', 'employment_status'])) {
            return;
        }

        $status   = $user->employment_status;
        $isActive = $user->is_active;

        // 'active' employment must have is_active = true
        if ($status === 'active' && $isActive === false) {
            Log::warning('UserObserver: is_active=false with employment_status=active — correcting to is_active=true.', [
                'user_id' => $user->id,
                'trace'   => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);
            $user->is_active = true;
        }

        // 'terminated' or 'suspended' must have is_active = false
        if (in_array($status, ['terminated', 'suspended'], true) && $isActive === true) {
            Log::warning("UserObserver: is_active=true with employment_status={$status} — correcting to is_active=false.", [
                'user_id' => $user->id,
                'trace'   => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5),
            ]);
            $user->is_active = false;
        }
    }
}
