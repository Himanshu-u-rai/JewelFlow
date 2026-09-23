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
if (($argv[1] ?? null) === 'fire') {
    [$status, $replay] = post_cash($argv[2], (int) $argv[3], $argv[4]);
    echo json_encode(['status' => $status, 'replay' => $replay]), "\n";
    exit(0);
}

// ── parent ────────────────────────────────────────────────────────────────
[$user, $shop] = (new ReleaseFixture)->build();
$shopId = (int) $shop->id;
$token = $user->createToken('xr02-probe')->plainTextToken;
$key = 'xr02-'.bin2hex(random_bytes(6));
$root = dirname(__DIR__, 2);

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

[$retryStatus, $retryReplay] = post_cash($token, $shopId, $key);
$rows = CashTransaction::withoutTenant()->where('shop_id', $shopId)->count();
printf("same-key retry: %d (replay: %s); cash rows for this shop: %d\n", $retryStatus, $retryReplay ?? 'no', $rows);

$safe = $claimAfterReconcile && $rows === 1;
echo $safe ? "RESULT: SAFE — release refused while the writer could still commit; one cash row\n"
           : "RESULT: UNSAFE — claim released under a live writer; {$rows} cash rows for one intent\n";
exit($safe ? 0 : 1);
