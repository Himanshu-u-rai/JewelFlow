<?php

/**
 * XR-02 — can an operator release a claim while its original writer can still
 * commit?
 *
 *   php tests/Concurrency/claim_release_race.php
 *
 * Structure reused from tests/Concurrency/idempotency_race.php: real OS
 * processes, real connections, real transactions — nothing here runs inside a
 * test transaction.
 *
 * THE SCENARIO
 *   1. This process holds `LOCK TABLE cash_transactions IN EXCLUSIVE MODE`.
 *   2. Child W posts a Cash Book entry. Its claim is staked (committed), its
 *      business transaction opens, and its INSERT waits on the lock — a
 *      database wait, which PHP's max_execution_time does not count on Linux.
 *   3. Child R runs the operator tool: reconcile the claim as not-committed.
 *      From outside, that is what the evidence shows — no cash row is visible.
 *   4. The lock is released. W commits.
 *   5. The same key is retried.
 *
 * Unsafe outcome: R releases the claim, W commits anyway, and the retry books a
 * SECOND cash row. Safe outcome: R is refused while W can still commit, and
 * the retry replays W's single entry.
 *
 * SCENARIO 2 — the connection holding the lock is lost (second review, XR-02)
 *   1. Child W stakes its claim on backend A (lock held, claim committed), then
 *      pauses at its very next query — before any business processing. The
 *      pause is instrumentation inside W, the harness's own process.
 *   2. This process terminates backend A. PostgreSQL drops A's lock with it.
 *   3. Child R reconciles the claim as not-committed: the lock is free and no
 *      cash row exists.
 *   4. W resumes. Laravel treats the dead connection as lost and, outside a
 *      transaction, reconnects on a new backend and retries.
 *   5. The same key is retried.
 * Unsafe outcome: W carries on without the lock, books its entry, and the
 * retry books a SECOND. Safe outcome: W cannot continue once its lock-holding
 * connection is gone, so R's "not committed" is true and exactly one row exists.
 *
 * Only this harness's own sessions are touched: the one backend terminated is
 * the one W reports as its own.
 *
 * Refuses any database not named jewelflow_testing. Rows accumulate, because
 * cash and audit rows are append-only by constitutional trigger.
 */

use App\Models\CashTransaction;
use App\Models\IdempotencyKey;
use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}

final class ReleaseFixture
{
    use CreatesTestTenant;

    public function build(): array { return $this->createRetailerTenant(); }
}

function post_cash(string $token, int $shopId, string $key): array
{
    TenantContext::set($shopId);
    $response = app(Kernel::class)->handle(Request::create('/api/mobile/v1/cashbook', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_X_IDEMPOTENCY_KEY' => $key,
    ], json_encode(['type' => 'in', 'amount' => 2500.00, 'source_type' => 'other', 'payment_mode' => 'cash', 'description' => 'XR-02 probe'])));

    return [$response->getStatusCode(), $response->headers->get('X-Idempotent-Replay')];
}

// ── child mode ────────────────────────────────────────────────────────────
if (($argv[1] ?? null) === 'fire-pause') {
    // Pause at the first query after the claim INSERT, report this process's
    // backend, and wait for the parent. The INSERT is autocommit: committed.
    $staked = $paused = false;
    DB::listen(function ($query) use (&$staked) {
        $staked = $staked || str_starts_with($query->sql, 'insert into "idempotency_keys"');
    });
    DB::connection()->beforeExecuting(function () use (&$staked, &$paused) {
        if ($staked && ! $paused) {
            $paused = true;
            fwrite(STDOUT, 'paused '.DB::selectOne('select pg_backend_pid() as pid')->pid."\n");
            fgets(STDIN);
        }
    });
    [$status, $replay] = post_cash($argv[2], (int) $argv[3], $argv[4]);
    echo json_encode(['status' => $status, 'replay' => $replay]), "\n";
    exit(0);
}

if (($argv[1] ?? null) === 'fire') {
    [$status, $replay] = post_cash($argv[2], (int) $argv[3], $argv[4]);
    echo json_encode(['status' => $status, 'replay' => $replay]), "\n";
    exit(0);
}

// ── parent ────────────────────────────────────────────────────────────────
function fresh_shop(): array
{
    [$user, $shop] = (new ReleaseFixture)->build();

    return [(int) $shop->id, $user->createToken('xr02-probe')->plainTextToken, 'xr02-'.bin2hex(random_bytes(6))];
}

function reconcile_not_committed(int $claimId, string $root): array
{
    $reconciler = proc_open(['php', 'artisan', 'mobile:idempotency-claims', '--reconcile='.$claimId, '--outcome=not-committed',
        '--evidence=no cash_transactions row visible for this user after staked_at', '--approved-by=XR-02 harness', '--confirm'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);

    return [proc_close($reconciler), trim(preg_replace('/\s+/', ' ', strip_tags($out)))];
}

/**
 * One request in a fresh process. In-process requests share state: a second
 * one in this process was answered for the FIRST request's user, whose shop
 * then received the row — a harness artefact, not a result.
 */
function fire_child(string $token, int $shopId, string $key, string $root): array
{
    $proc = proc_open(['php', __FILE__, 'fire', $token, (string) $shopId, $key], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    $out = json_decode(trim(stream_get_contents($pipes[1])), true) ?? ['status' => -1, 'replay' => null];
    proc_close($proc);

    return [$out['status'], $out['replay']];
}

$root = dirname(__DIR__, 2);

echo "== 1. intact connection: writer waits inside its business transaction ==\n";
[$shopId, $token, $key] = fresh_shop();

DB::beginTransaction();
DB::statement('LOCK TABLE cash_transactions IN EXCLUSIVE MODE');

$writer = proc_open(['php', __FILE__, 'fire', $token, (string) $shopId, $key], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $writerPipes, $root);

// Wait until W is genuinely blocked on the lock, not merely started.
$blocked = false;
for ($i = 0; $i < 200 && ! $blocked; $i++) {
    usleep(50_000);
    $blocked = DB::selectOne("select count(*) c from pg_locks l join pg_class c on c.oid = l.relation where c.relname = 'cash_transactions' and not l.granted")->c > 0;
}
$claim = IdempotencyKey::where('shop_id', $shopId)->where('key', $key)->first();
printf("writer blocked inside its business transaction: %s; claim staked: %s (status %s)\n",
    $blocked ? 'yes' : 'NO', $claim ? "id {$claim->id}" : 'NO', $claim?->response_status ?? '-');

// The operator, acting on what is visible: no cash row for this request.
$visibleRows = CashTransaction::withoutTenant()->where('shop_id', $shopId)->count();
$reconciler = proc_open(['php', 'artisan', 'mobile:idempotency-claims', '--reconcile='.$claim->id, '--outcome=not-committed',
    '--evidence=no cash_transactions row visible for this user after staked_at', '--approved-by=XR-02 harness', '--confirm'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $reconcilerPipes, $root);
$reconcilerOut = stream_get_contents($reconcilerPipes[1]).stream_get_contents($reconcilerPipes[2]);
$reconcilerExit = proc_close($reconciler);
$claimAfterReconcile = IdempotencyKey::whereKey($claim->id)->exists();
printf("cash rows visible to the operator: %d; reconciler exit %d; claim still present: %s\n",
    $visibleRows, $reconcilerExit, $claimAfterReconcile ? 'yes' : 'NO');
echo '  reconciler said: ', trim(preg_replace('/\s+/', ' ', strip_tags($reconcilerOut))), "\n";

DB::commit(); // releases the table lock; W proceeds

$writerOut = json_decode(trim(stream_get_contents($writerPipes[1])), true);
$writerErr = stream_get_contents($writerPipes[2]);
proc_close($writer);
printf("writer finished: %s\n", json_encode($writerOut ?? ['stderr' => mb_substr($writerErr, 0, 300)]));

[$retryStatus, $retryReplay] = fire_child($token, $shopId, $key, $root);
$rows = CashTransaction::withoutTenant()->where('shop_id', $shopId)->count();
printf("same-key retry: %d (replay: %s); cash rows for this shop: %d\n", $retryStatus, $retryReplay ?? 'no', $rows);

$safe1 = $claimAfterReconcile && $rows === 1;
echo $safe1 ? "RESULT: SAFE — release refused while the writer could still commit; one cash row\n"
            : "RESULT: UNSAFE — claim released under a live writer; {$rows} cash rows for one intent\n";

echo "\n== 2. the lock-holding connection is lost after the claim is staked ==\n";
[$shopId, $token, $key] = fresh_shop();
$writer = proc_open(['php', __FILE__, 'fire-pause', $token, (string) $shopId, $key],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $writerPipes, $root);
$line = trim((string) fgets($writerPipes[1]));
$backendA = str_starts_with($line, 'paused ') ? (int) substr($line, 7) : 0;
$claim = IdempotencyKey::where('shop_id', $shopId)->where('key', $key)->first();
$lockHolder = DB::selectOne("select pid from pg_locks where locktype = 'advisory' and granted and pid = ?", [$backendA]);
printf("writer paused after staking: %s; claim %s (status %s); advisory lock held by backend A: %s\n",
    $backendA ? "yes, backend A = {$backendA}" : "NO ({$line})", $claim ? "id {$claim->id}" : 'NO', $claim?->response_status ?? '-', $lockHolder ? 'yes' : 'NO');

DB::selectOne('select pg_terminate_backend(?)', [$backendA]);
for ($i = 0; $i < 100 && DB::selectOne('select count(*) c from pg_stat_activity where pid = ?', [$backendA])->c > 0; $i++) {
    usleep(20_000);
}
echo 'backend A terminated; lock still held by anyone: ',
    DB::selectOne("select count(*) c from pg_locks where locktype = 'advisory' and granted")->c > 0 ? 'yes' : 'no', "\n";

[$reconcilerExit, $reconcilerSaid] = reconcile_not_committed($claim->id, $root);
$claimAfterReconcile = IdempotencyKey::whereKey($claim->id)->exists();
printf("reconciler exit %d; claim still present: %s\n", $reconcilerExit, $claimAfterReconcile ? 'yes' : 'NO');
echo '  reconciler said: ', $reconcilerSaid, "\n";

fwrite($writerPipes[0], "go\n");
$writerOut = json_decode(trim(stream_get_contents($writerPipes[1])), true);
$writerErr = stream_get_contents($writerPipes[2]);
proc_close($writer);
$afterWriter = CashTransaction::withoutTenant()->where('shop_id', $shopId)->count();
printf("writer resumed: %s; cash rows it left: %d\n", json_encode($writerOut ?? ['stderr' => mb_substr($writerErr, 0, 300)]), $afterWriter);

[$retryStatus, $retryReplay] = fire_child($token, $shopId, $key, $root);
$rows = CashTransaction::withoutTenant()->where('shop_id', $shopId)->count();
printf("same-key retry: %d (replay: %s); cash rows for this shop: %d\n", $retryStatus, $retryReplay ?? 'no', $rows);

$safe2 = $backendA > 0 && $afterWriter === 0 && $rows === 1;
echo $safe2 ? "RESULT: SAFE — the writer could not continue without its lock; one cash row\n"
            : "RESULT: UNSAFE — the writer continued on a new connection after its lock was gone; {$rows} cash rows for one intent\n";

exit($safe1 && $safe2 ? 0 : 1);
