<?php

/**
 * S3-09 §7c-3 — REAL multi-process concurrency probe for EnsureIdempotency.
 *
 * ─── Why this exists, and why it is not a PHPUnit test ────────────────────
 *
 * The handoff recorded "two concurrent first requests, separate processes" as
 * NOT RUN, with an accurate reason: `RefreshDatabase` wraps each test body in a
 * single uncommitted transaction, so a second connection cannot see the
 * fixtures, and a single PHP process cannot schedule two requests to collide.
 * Everything claimed about concurrency so far has therefore been SIMULATED —
 * the conflict path was driven by inserting a duplicate claim by hand, which
 * exercises the constraint the race would hit without ever scheduling the race.
 *
 * This probe closes that gap. It:
 *
 *   * seeds a tenant and COMMITS it (no wrapping transaction), so other
 *     connections can see it;
 *   * spawns N real OS processes, each booting the application independently
 *     with its own PostgreSQL connection;
 *   * has every child pre-warm its connection and then wait on a shared
 *     wall-clock start time, so the contention lands on the INSERT rather than
 *     on process startup;
 *   * drives each request through the real HTTP kernel — real middleware
 *     stack, real controller, real triggers;
 *   * then reads the business effect back out of the database.
 *
 * ─── What this can and cannot establish ──────────────────────────────────
 *
 * It CAN establish that, under genuine wall-clock contention on one machine,
 * the unique index on (shop_id, user_id, key) admits exactly one request to the
 * controller and the money row is written exactly once.
 *
 * It CANNOT establish behaviour under a multi-node deployment, under a
 * connection pooler, or at production concurrency levels. A green run here is
 * evidence about this machine and this schema, nothing wider.
 *
 * ─── Cleanup: deliberately incomplete, and that is the constitution ──────
 *
 * The probe does NOT delete the rows it creates. `cash_transactions` carries
 * `prevent_ledger_mutation` plus an append-only guard and `audit_logs` an
 * append-only guard, so a DELETE is refused by the database itself. Rather than
 * fight that, each run provisions a FRESH shop and scopes every assertion to
 * it. Runs accumulate rows in `jewelflow_testing`; that is the intended cost of
 * not weakening a constitutional trigger for the convenience of a test.
 *
 * ─── Usage ───────────────────────────────────────────────────────────────
 *
 *   php tests/Concurrency/idempotency_race.php run
 *   php tests/Concurrency/idempotency_race.php fire <token> <key> <startMicro> <payloadB64>
 *
 * `fire` is the child entry point and is not meant to be called by hand.
 */

use App\Models\CashTransaction;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** Fixture builder — reuses the same tenant helper the PHPUnit suite uses. */
final class RaceFixture
{
    use CreatesTestTenant;

    public function build(): array
    {
        return $this->createRetailerTenant();
    }
}

const ROUTE = '/api/mobile/v1/cashbook';

function basePayload(): array
{
    return [
        'type' => 'in',
        'amount' => 2500.00,
        'source_type' => 'other',
        'payment_mode' => 'cash',
        'description' => 'Concurrency probe entry',
    ];
}

/**
 * CHILD: wait for the shared start instant, then issue one real request.
 *
 * The connection is warmed BEFORE the barrier on purpose. Without it the
 * children would be racing to open a socket and run the schema cache, and the
 * spread on that dwarfs the window this probe is trying to hit.
 */
function fire(string $token, string $key, float $startMicro, string $payloadB64): void
{
    /** @var Kernel $kernel */
    $kernel = app(Kernel::class);

    // Warm the connection before the barrier so the contention lands on the
    // INSERT rather than on socket setup and schema caching.
    //
    // Swallowing the failure is load-bearing for the DB-outage control. An
    // earlier version let this throw, which killed the child before the kernel
    // ever ran — so the control proved only that the PROBE cannot reach a
    // missing database, not what the MIDDLEWARE does when it cannot. The
    // request must still be dispatched so EnsureIdempotency's own fail-closed
    // read path is the thing under observation.
    try {
        DB::select('select 1');
    } catch (Throwable $e) {
        // Intentionally ignored — see above.
    }

    $spin = 0;
    while (microtime(true) < $startMicro) {
        $remaining = $startMicro - microtime(true);
        if ($remaining > 0.002) {
            usleep((int) (($remaining - 0.001) * 1_000_000));
        }
        $spin++;
    }

    $payload = json_decode(base64_decode($payloadB64), true);

    $request = Request::create(ROUTE, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        'HTTP_X_IDEMPOTENCY_KEY' => $key,
    ], json_encode($payload));

    $sent = microtime(true);

    $replayHeader = null;

    try {
        $response = $kernel->handle($request);
        $status = $response->getStatusCode();
        $body = $response->getContent();
        // Captured so a replay can be proven from the middleware's own signal
        // rather than inferred from "the row count did not change".
        $replayHeader = $response->headers->get('X-Idempotent-Replay');
    } catch (Throwable $e) {
        $status = -1;
        $body = json_encode(['fatal' => get_class($e) . ': ' . $e->getMessage()]);
    }

    $decoded = json_decode($body, true);
    $code = $decoded['errors'][0]['code']
        ?? ($decoded['error']['code'] ?? null);

    fwrite(STDOUT, json_encode([
        'pid' => getmypid(),
        'status' => $status,
        'code' => $code,
        'replay' => $replayHeader,
        'sent_at' => $sent,
        'spins' => $spin,
        'body' => mb_substr((string) $body, 0, 220),
    ]) . "\n");
}

/**
 * PARENT: launch `count($specs)` children at one shared start instant.
 *
 * Each spec is [key, payload, envOverrides]. Children are started first and
 * only then allowed to run, which is what makes the collision real rather than
 * sequential.
 */
function race(string $token, array $specs, float $leadSeconds = 2.0): array
{
    $start = microtime(true) + $leadSeconds;
    $procs = [];

    foreach ($specs as $i => [$key, $payload, $env]) {
        $cmd = sprintf(
            'php %s fire %s %s %.6f %s',
            escapeshellarg(__FILE__),
            escapeshellarg($token),
            escapeshellarg($key),
            $start,
            escapeshellarg(base64_encode(json_encode($payload))),
        );

        $procs[$i] = [
            'proc' => proc_open(
                $cmd,
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                dirname(__DIR__, 2),
                array_merge(getenv() ?: [], $env),
            ),
            'pipes' => $pipes,
        ];
    }

    $results = [];
    foreach ($procs as $i => $p) {
        $out = stream_get_contents($p['pipes'][1]);
        $err = stream_get_contents($p['pipes'][2]);
        fclose($p['pipes'][1]);
        fclose($p['pipes'][2]);
        proc_close($p['proc']);

        $line = trim(strtok(trim($out), "\n") ?: '');
        $decoded = json_decode($line, true);

        $results[$i] = is_array($decoded)
            ? $decoded
            : ['status' => -1, 'code' => 'no_output', 'body' => trim($out . ' ' . $err)];
    }

    return $results;
}

function tally(array $results): array
{
    $t = [];
    foreach ($results as $r) {
        $label = $r['status'] . ($r['code'] ? ' ' . $r['code'] : '');
        $t[$label] = ($t[$label] ?? 0) + 1;
    }
    ksort($t);

    return $t;
}

function cashRows(int $shopId): array
{
    return [
        'rows' => (int) CashTransaction::withoutTenant()->where('shop_id', $shopId)->count(),
        'sum' => (float) CashTransaction::withoutTenant()->where('shop_id', $shopId)->sum('amount'),
    ];
}

function report(string $name, string $expectation, array $results, array $cash, array $extra = []): array
{
    echo "\n─── {$name} ───\n";
    echo "expect: {$expectation}\n";
    echo "responses: " . json_encode(tally($results)) . "\n";
    echo "cash rows: {$cash['rows']}  sum: {$cash['sum']}\n";
    foreach ($extra as $k => $v) {
        echo "{$k}: {$v}\n";
    }
    foreach ($results as $r) {
        if (($r['status'] ?? 0) === -1) {
            echo "  !! child produced no usable response: " . ($r['body'] ?? '') . "\n";
        }
    }

    return ['name' => $name, 'responses' => tally($results), 'cash' => $cash];
}

// ─────────────────────────────────────────────────────────────────────────
// Entry point
// ─────────────────────────────────────────────────────────────────────────

$mode = $argv[1] ?? 'run';

if ($mode === 'fire') {
    fire($argv[2], $argv[3], (float) $argv[4], $argv[5]);
    exit(0);
}

echo "EnsureIdempotency — real multi-process concurrency probe\n";
echo "db: " . config('database.connections.pgsql.database') . "\n";
echo "git: " . trim(shell_exec('git rev-parse HEAD') ?: '?') . "\n";

$verdicts = [];

// ── Scenario A: N concurrent identical same-key requests ────────────────
[$user, $shop] = (new RaceFixture())->build();
$token = $user->createToken('race-probe')->plainTextToken;

$specs = [];
for ($i = 0; $i < 4; $i++) {
    $specs[] = ['race-same-key-aaaa', basePayload(), []];
}
$results = race($token, $specs);

// IdempotencyKey does NOT use BelongsToShop — it carries shop_id as a plain
// column and scopes by hand, so there is no withoutTenant() to bypass.
$claims = IdempotencyKey::query()->where('shop_id', $shop->id)->get();
$verdicts[] = report(
    'A — 4 concurrent requests, SAME key, SAME payload',
    'exactly one 201; the rest refused; exactly ONE cash row',
    $results,
    cashRows((int) $shop->id),
    [
        'claim rows' => $claims->count() . ' (unique index admits one)',
        'claim status' => $claims->pluck('response_status')->implode(','),
    ],
);

// ── Scenario A2: SEQUENTIAL retry after the race has settled ───────────
//
// This targets the exact sentence the handoff records as unestablished: that
// pre-staking "does NOT by itself prove ... recoverable successful replay".
// Scenario A left a RESOLVED claim (response_status 201). A later same-key
// retry should now replay the original response rather than be refused as
// in-flight, and must not write a second cash row.
$replay = race($token, [['race-same-key-aaaa', basePayload(), []]]);

$verdicts[] = report(
    'A2 — sequential same-key retry AFTER the race settled',
    '200 replay of the original response, STILL exactly one cash row',
    $replay,
    cashRows((int) $shop->id),
    [
        'X-Idempotent-Replay' => var_export($replay[0]['replay'] ?? null, true),
        'replay body' => $replay[0]['body'] ?? '(none)',
    ],
);

// ── Scenario B: concurrent same key, DIFFERENT payloads ─────────────────
[$userB, $shopB] = (new RaceFixture())->build();
$tokenB = $userB->createToken('race-probe')->plainTextToken;

$p1 = basePayload();
$p2 = basePayload();
$p2['amount'] = 9999.00;

$results = race($tokenB, [
    ['race-diff-payload-bb', $p1, []],
    ['race-diff-payload-bb', $p2, []],
]);

$verdicts[] = report(
    'B — 2 concurrent requests, SAME key, DIFFERENT payload',
    'one 201; the other refused (conflict or in-flight); exactly ONE cash row',
    $results,
    cashRows((int) $shopB->id),
);

// ── Scenario C (POSITIVE CONTROL): distinct keys must all succeed ───────
[$userC, $shopC] = (new RaceFixture())->build();
$tokenC = $userC->createToken('race-probe')->plainTextToken;

$specs = [];
for ($i = 0; $i < 4; $i++) {
    $specs[] = ['race-distinct-key-' . $i . '-cc', basePayload(), []];
}
$results = race($tokenC, $specs);

$verdicts[] = report(
    'C — POSITIVE CONTROL: 4 concurrent requests, DISTINCT keys',
    'four 201s and FOUR cash rows — the middleware must not block legitimate concurrent work',
    $results,
    cashRows((int) $shopC->id),
);

// ── Scenario D (UNRELATED DB-ERROR CONTROL) ─────────────────────────────
// A child pointed at a database that does not exist. The point is that an
// infrastructure failure must NOT be indistinguishable from an idempotency
// refusal: it must not surface as 201 (ran anyway) and must not surface as a
// 409 in-flight (which would tell the client to stop retrying a request that
// never touched the real database at all).
[$userD, $shopD] = (new RaceFixture())->build();
$tokenD = $userD->createToken('race-probe')->plainTextToken;

$results = race($tokenD, [
    ['race-dberror-control-d', basePayload(), ['DB_DATABASE' => 'jewelflow_no_such_db']],
]);

$verdicts[] = report(
    'D — UNRELATED DB-ERROR CONTROL: child pointed at a missing database',
    'NOT 201 and NOT 409 in-flight — an outage must look like an outage',
    $results,
    cashRows((int) $shopD->id),
);

echo "\n─── shops used (rows are retained; ledger/audit deletes are refused by trigger) ───\n";
echo "A={$shop->id} B={$shopB->id} C={$shopC->id} D={$shopD->id}\n";
