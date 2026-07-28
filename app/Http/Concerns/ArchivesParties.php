<?php

namespace App\Http\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MASTERS PART 3 — the explicit archive / reactivate action shared by the
 * Customer and Vendor controllers.
 *
 * Deliberately NOT a toggle. The caller states the target state, so a stale
 * page, a double submit or a replayed request can never flip a party the wrong
 * way; it just no-ops.
 *
 * Three guarantees live here so neither controller can drift from the other:
 *
 *   1. The row is re-read under FOR UPDATE inside the transaction. That is the
 *      same lock ArchivableParty::lockActiveOrFail() takes on the write paths,
 *      so an archive and a concurrent new commitment serialise: whichever locks
 *      first wins and the loser sees the committed state, never a stale read.
 *   2. Idempotency. If the party is already in the requested state we return
 *      before writing, so there is no second state change and no duplicate
 *      audit row.
 *   3. The audit row is written inside the same transaction as the state
 *      change, so a rollback loses both — an audit trail that can disagree with
 *      the data is worse than none.
 */
trait ArchivesParties
{
    /**
     * @param  Model  $party  a Customer or Vendor (uses the ArchivableParty trait)
     * @return bool  true when the state actually changed, false when it was already there
     */
    protected function setPartyActive(Request $request, Model $party, bool $active): bool
    {
        $reason = $request->validate([
            'reason' => 'nullable|string|max:500',
        ])['reason'] ?? null;

        return DB::transaction(function () use ($party, $active, $reason) {
            // Re-read through the model's own query (tenant scope applies) so the
            // decision is made on the committed row, not on the instance route
            // binding resolved before the lock.
            $locked = $party->newQuery()
                ->whereKey($party->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked || (bool) $locked->is_active === $active) {
                return false;
            }

            // is_active is not fillable — lifecycle changes only happen here.
            $locked->forceFill(['is_active' => $active])->save();

            AuditLog::create([
                'shop_id'     => (int) $locked->shop_id,
                'user_id'     => auth()->id(),
                'action'      => $party::partyLabel() . ($active ? '_reactivated' : '_archived'),
                'model_type'  => class_basename($party),
                'model_id'    => $locked->getKey(),
                // Customer exposes name via an accessor, Vendor as a column.
                'description' => ($active ? 'Reactivated ' : 'Archived ')
                    . $party::partyLabel() . ': ' . (string) $locked->name,
                'target'      => ['type' => $party::partyLabel(), 'id' => $locked->getKey()],
                'before'      => ['is_active' => ! $active],
                'after'       => ['is_active' => $active],
                'data'        => $reason ? ['reason' => $reason] : null,
            ]);

            $party->setAttribute('is_active', $active);

            return true;
        });
    }
}
