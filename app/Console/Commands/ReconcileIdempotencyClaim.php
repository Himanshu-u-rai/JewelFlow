<?php

namespace App\Console\Commands;

use App\Http\Middleware\EnsureIdempotency;
use App\Models\AuditLog;
use App\Models\IdempotencyKey;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * List, and reconcile one at a time, idempotency claims whose outcome the
 * server never recorded. Procedure: docs/runbooks/idempotency-unresolved-claims.md.
 *
 * Deliberately decides nothing. No age option and no bulk option exist, and
 * none should be added: an old claim is not a safe one, and the only thing
 * that makes a claim safe to release is evidence that its mutation did not
 * commit. The operator supplies that evidence and an approver; this records it.
 *
 *   not-committed  the claim is deleted, so the same key may run again.
 *   committed      the claim keeps refusing the key, now with a definite 409
 *                  instead of "in progress". The pruner retains it (it prunes
 *                  only claims that recorded a 2xx), so the refusal does not
 *                  lapse with age.
 *
 * Before acting it must acquire the claim's advisory lock, which the original
 * request holds until its claim is resolved (XR-02): a free lock proves that
 * writer can no longer commit. The change and its audit_logs row are then
 * written in one transaction, under a row lock, after re-checking that the
 * claim is still unresolved.
 */
class ReconcileIdempotencyClaim extends Command
{
    protected $signature = 'mobile:idempotency-claims
        {--reconcile= : Id of ONE unresolved claim to reconcile}
        {--outcome= : What the evidence shows: committed | not-committed}
        {--evidence= : Where the evidence is (ledger row ids, reconciliation reference)}
        {--approved-by= : Name of the person who approved this reconciliation}
        {--confirm : Write the change. Without it nothing is written}';

    protected $description = 'List unresolved idempotency claims, or reconcile one with evidence (dry run unless --confirm).';

    private const OUTCOMES = ['committed', 'not-committed'];

    public function handle(): int
    {
        if ($this->option('reconcile') === null) {
            return $this->listUnresolved();
        }

        $outcome = (string) $this->option('outcome');
        $evidence = trim((string) $this->option('evidence'));
        $approvedBy = trim((string) $this->option('approved-by'));

        if (! in_array($outcome, self::OUTCOMES, true) || $evidence === '' || $approvedBy === '') {
            $this->error('Refused: --outcome (committed | not-committed), --evidence and --approved-by are all required.');

            return self::FAILURE;
        }

        $claim = IdempotencyKey::find((int) $this->option('reconcile'));

        if ($claim === null || ! self::isUnresolved($claim)) {
            $this->error('Refused: no UNRESOLVED claim with that id. A resolved claim is never changed here.');

            return self::FAILURE;
        }

        // XR-02. The original request holds this lock from before it staked
        // the claim until the claim is resolved; PostgreSQL drops it if that
        // process dies. Acquiring it is the proof that the original writer
        // can no longer commit. Age is not.
        $lockKey = EnsureIdempotency::claimLockKey((int) $claim->shop_id, $claim->user_id, (string) $claim->key);

        if (! EnsureIdempotency::tryLockClaim($lockKey)) {
            $this->error('Refused: the original request still holds this claim and may still commit. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            return $this->reconcileLocked($claim, $outcome, $evidence, $approvedBy);
        } finally {
            EnsureIdempotency::unlockClaim($lockKey);
        }
    }

    private function reconcileLocked(IdempotencyKey $claim, string $outcome, string $evidence, string $approvedBy): int
    {
        $this->line('The original request has ended: its claim lock is free.');
        $this->table(['id', 'shop', 'user', 'key', 'request_hash', 'staked_at'], [$this->row($claim)]);
        $this->line($outcome === 'committed'
            ? 'Planned: keep refusing this key, with a definite "already recorded" answer.'
            : 'Planned: RELEASE this key, so the same request may run again.');

        if (! $this->option('confirm')) {
            $this->warn('DRY RUN — nothing written. Re-run with --confirm once the evidence is approved.');

            return self::SUCCESS;
        }

        $applied = DB::transaction(function () use ($claim, $outcome, $evidence, $approvedBy): bool {
            $locked = IdempotencyKey::whereKey($claim->id)->lockForUpdate()->first();

            if ($locked === null || ! self::isUnresolved($locked)) {
                return false;
            }

            $before = $locked->getAttributes();

            if ($outcome === 'not-committed') {
                $locked->delete();
                $after = null;
            } else {
                $locked->update([
                    'response_status' => 409,
                    'response_body' => ['errors' => [[
                        'code' => 'idempotency_outcome_reconciled',
                        'message' => 'An operator confirmed this request was recorded. Check the record; do not enter it again.',
                    ]]],
                ]);
                $after = $locked->fresh()->getAttributes();
            }

            TenantContext::runFor((int) $locked->shop_id, fn () => AuditLog::create([
                'user_id' => null,
                'action' => 'idempotency_claim_reconciled',
                'model_type' => 'IdempotencyKey',
                'model_id' => $locked->id,
                'description' => "Unresolved idempotency claim reconciled as {$outcome}.",
                'actor' => ['via' => 'cli', 'os_user' => get_current_user(), 'approved_by' => $approvedBy],
                'before' => $before,
                'after' => $after,
                'data' => ['outcome' => $outcome, 'evidence' => $evidence],
            ]));

            return true;
        });

        if (! $applied) {
            $this->error('Refused: the claim was resolved or removed while this ran. Nothing written.');

            return self::FAILURE;
        }

        $this->info("Reconciled claim {$claim->id} as {$outcome}; audit row written.");

        return self::SUCCESS;
    }

    private function listUnresolved(): int
    {
        $rows = IdempotencyKey::query()
            ->where(fn ($q) => $q->where('response_status', '<', 100)->orWhere('response_status', '>', 599))
            ->orderBy('created_at')
            ->get()
            ->map(fn (IdempotencyKey $claim) => $this->row($claim))
            ->all();

        $this->table(['id', 'shop', 'user', 'key', 'request_hash', 'staked_at'], $rows);
        $this->line(count($rows).' unresolved claim(s). Each needs evidence before anything is done to it.');

        return self::SUCCESS;
    }

    /** Same test the middleware applies: anything that is not a usable HTTP status. */
    private static function isUnresolved(IdempotencyKey $claim): bool
    {
        $status = (int) $claim->response_status;

        return $status < 100 || $status > 599;
    }

    private function row(IdempotencyKey $claim): array
    {
        return [$claim->id, $claim->shop_id, $claim->user_id, $claim->key, $claim->request_hash, (string) $claim->created_at];
    }
}
