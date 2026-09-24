<?php

/**
 * S3-16 — expiry against the other loyalty writers, real processes.
 *
 *   php tests/Concurrency/loyalty_redeem_expiry_race.php [--break=worker-exit|http-500|no-expiry]
 *
 * Every customer starts with 110 points: a lot of 100 that fell due and 10
 * not yet due. Whatever the order of expiry and a redemption, the customer
 * ends at 10 with exactly one expiry row — 100 when the redemption was
 * refused, 40 when a redemption of 60 was recorded first (it took the
 * earliest-expiring points).
 *
 * 1. The precise interleaving, through the real route. An owner posts a
 *    redemption of 80 to POST /loyalty/{customer}/adjust for a customer
 *    holding 110 (a lot of 100 that fell due, and 10 not yet due). Route
 *    binding loads the customer at 110; at that moment a separate
 *    `php artisan loyalty:expire` process runs and commits the 100-point
 *    expiry; then the request continues with the model it loaded. Recorded:
 *    the response, the balance, the ledger.
 * 2. A real race: an expiry process and three redemption processes over 60
 *    customers, started together. The expiry process runs loyalty:expire's
 *    per-shop step (LoyaltyService::expireDue) for the harness's shop only:
 *    the command walks every active shop in jewelflow_testing first, and
 *    with the shops earlier runs left behind it reached these customers only
 *    after every redemption had finished — no race. Recorded per customer:
 *    the answer, the expiry, balance against the ledger's sum, the
 *    balance_after chain, negative balances; and whether both orders
 *    occurred.
 *
 * 3. The lock, deterministically: this process holds the row as a mid-flight
 *    expiry (locked, decremented, not committed) while a child redeems; it
 *    commits only once the child is seen waiting on a lock.
 *
 * SAFE requires each scenario's premises as well as its balances; every
 * premise that fails is printed:
 *   - every worker process exits 0 and reports an answer for every request
 *     it was given;
 *   - every request gets an answer the application should give: the
 *     redemption recorded (redirect to the customer), or refused (redirect
 *     with "Insufficient loyalty points"). A 500, or any other answer, is
 *     UNSAFE;
 *   - the expected transactions exist and no others: one expiry per
 *     customer, exactly the redemptions the workers reported;
 *   - in 1 and 3 the redemption is refused against the fresh balance (10)
 *     and nothing is written for it; in 3 the waiting backend is the child's
 *     own, and the backend blocking it is this process's;
 *   - no balance is negative, every balance equals its ledger's sum, every
 *     balance_after chain is consistent.
 * Scenario 2's interleavings depend on scheduling; with the lock removed it
 * can pass by chance — scenario 3 is the one that decides the lock.
 *
 * --break= makes it fail on purpose, to show that it can (propagated to the
 * child processes as HARNESS_BREAK):
 *   worker-exit  every redemption worker exits 1 before doing anything
 *   http-500     every redemption request throws (a 500) — balances stay
 *                consistent, so only the answer check can catch it
 *   no-expiry    the expiry process exits 0 having done nothing
 *
 * Writes committed fixtures to jewelflow_testing; refuses any other database.
 */

use App\Models\Customer;
use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(HttpKernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}
app()->instance('request', Request::create((string) config('app.url')));   // for url(), as the console kernel binds

$breakArg = current(array_filter($argv, fn ($a) => str_starts_with($a, '--break=')));
$break = $breakArg ? substr($breakArg, 8) : (string) getenv('HARNESS_BREAK');
if (! in_array($break, ['', 'worker-exit', 'http-500', 'no-expiry'], true)) {
    fwrite(STDERR, "unknown --break={$break}\n");
    exit(2);
}

// ── child: loyalty:expire's step for one shop ──
if (($argv[1] ?? null) === 'expire') {
    if ($break === 'no-expiry') {
        exit(0);
    }
    $shopId = (int) $argv[2];
    $activeFrom = Illuminate\Support\Carbon::parse((string) config('loyalty.expiry_active_from'))->startOfDay();
    $r = TenantContext::runFor($shopId, fn () => app(App\Services\LoyaltyService::class)->expireDue($shopId, $activeFrom, true));
    echo json_encode($r), "\n";
    exit(0);
}

// ── child: redemption requests for a list of customers, in-process through the kernel ──
// Prints its database backend's pid first, then the count of each answer.
if (($argv[1] ?? null) === 'redeem') {
    [, , $ownerId, $ids, $points] = $argv;
    if ($break === 'worker-exit') {
        fwrite(STDERR, "harness: worker failing on purpose\n");
        exit(1);
    }
    if ($break === 'http-500') {
        Event::listen('eloquent.retrieved: '.Customer::class, fn () => throw new RuntimeException('harness: failing on purpose'));
    }
    $owner = App\Models\User::withoutGlobalScopes()->findOrFail((int) $ownerId);
    echo 'pid '.DB::selectOne('select pg_backend_pid() as p')->p."\n";
    $answers = [];
    foreach (explode(',', $ids) as $id) {
        $answers[] = outcome(...adjust($owner, (int) $id, (int) $points), customerId: (int) $id);
    }
    echo json_encode(array_count_values($answers)), "\n";
    exit(0);
}

final class LoyaltyRaceFixture
{
    use CreatesTestTenant;

    public function tenant(): array
    {
        return $this->createRetailerTenant();
    }

    public function customer(int $shopId): Customer
    {
        return $this->createCustomer($shopId);
    }
}

/** POST /loyalty/{id}/adjust (redeem) as $owner, through the HTTP kernel; returns [status, detail]. */
function adjust($owner, int $customerId, int $points): array
{
    Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['loyalty/*']);   // harness only: no browser session
    $request = Request::create(url("/loyalty/{$customerId}/adjust"), 'POST',
        ['type' => 'redeem', 'points' => $points, 'description' => 'harness redemption'], [], [], ['HTTP_ACCEPT' => 'text/html']);
    app()->instance('request', $request);
    Auth::guard('web')->setUser($owner);
    TenantContext::set((int) $owner->shop_id);
    $response = app(HttpKernel::class)->handle($request);
    $detail = $response->exception?->getMessage()
        ?? ($response->isRedirect() ? 'redirect '.parse_url($response->headers->get('Location'), PHP_URL_PATH) : '');
    $errors = session('errors')?->all() ?? [];

    return [$response->getStatusCode(), trim($detail.' '.implode('; ', $errors))];
}

/** The application's answer: `redeemed`, `refused`, or the status and detail of anything else. */
function outcome(int $status, string $detail, int $customerId): string
{
    return match (true) {
        $status === 302 && str_contains($detail, 'Insufficient loyalty points') => 'refused',
        $status === 302 && $detail === "redirect /customers/{$customerId}" => 'redeemed',
        default => "other: {$status} {$detail}",
    };
}

/** Prints each premise that does not hold; true when all do. */
function check(array $premises): bool
{
    $failed = array_keys(array_filter($premises, fn ($holds) => ! $holds));
    foreach ($failed as $premise) {
        echo "  FAILED: {$premise}\n";
    }

    return $failed === [];
}

/** Points of each expiry row, in order. */
function expiries(int $customerId): array
{
    return DB::table('loyalty_transactions')->where('customer_id', $customerId)->whereNotNull('expires_lot_id')->orderBy('id')
        ->pluck('points')->map(fn ($p) => (int) $p)->all();
}

function redemptions(int $customerId): int
{
    return DB::table('loyalty_transactions')->where('customer_id', $customerId)->where('description', 'harness redemption')->count();
}

/** A worker's last line: its answers, counted. */
function answers(string $stdout): ?array
{
    $lines = explode("\n", trim($stdout));
    $counts = json_decode((string) end($lines), true);

    return is_array($counts) ? $counts : null;
}

function ledger(int $customerId): array
{
    return DB::table('loyalty_transactions')->where('customer_id', $customerId)->orderBy('id')
        ->get(['type', 'points', 'balance_after', 'expires_lot_id', 'description'])
        ->map(fn ($r) => sprintf('%s %d → %d%s', $r->type, $r->points, $r->balance_after, $r->expires_lot_id ? ' (expiry)' : ''))->all();
}

function balance(int $customerId): int
{
    return (int) DB::table('customers')->where('id', $customerId)->value('loyalty_points');
}

function ledgerSum(int $customerId): int
{
    return (int) DB::table('loyalty_transactions')->where('customer_id', $customerId)
        ->selectRaw("coalesce(sum(case when type = 'earn' then points else -points end), 0) s")->value('s');
}

/** Every row's balance_after equals the running sum of the ledger up to it. */
function chainConsistent(int $customerId): bool
{
    $running = 0;
    foreach (DB::table('loyalty_transactions')->where('customer_id', $customerId)->orderBy('id')->get() as $r) {
        $running += $r->type === 'earn' ? $r->points : -$r->points;
        if ((int) $r->balance_after !== $running) {
            return false;
        }
    }

    return true;
}

$env = ['LOYALTY_EXPIRY_ACTIVE_FROM' => now()->subDays(7)->toDateString(), 'HARNESS_BREAK' => $break] + getenv();
$expiryCmd = $break === 'no-expiry' ? ['php', '-r', 'exit(0);'] : ['php', 'artisan', 'loyalty:expire'];
if ($break !== '') {
    echo "── BREAK: {$break} — this run must end UNSAFE ──\n";
}
$fixture = new LoyaltyRaceFixture;
[$owner, $shop] = $fixture->tenant();
$holder = function () use ($fixture, $shop): int {
    return TenantContext::runFor((int) $shop->id, function () use ($fixture, $shop) {
        $c = $fixture->customer((int) $shop->id);
        $c->addLoyaltyPoints(100, null, 'harness: due', now()->subDay());
        $c->fresh()->addLoyaltyPoints(10, null, 'harness: not yet due', now()->addYear());

        return (int) $c->id;
    });
};

// ── 1. the precise interleaving ──────────────────────────────────────────
echo "── 1. redemption loads 110; expiry commits 100; redemption attempts 80 with its old model ──\n";
$customerId = $holder();
$expiryRan = false;
$expiryExit = null;
Event::listen('eloquent.retrieved: '.Customer::class, function (Customer $c) use ($customerId, &$expiryRan, &$expiryExit, $env, $expiryCmd) {
    if ($expiryRan || (int) $c->id !== $customerId) {
        return;
    }
    $expiryRan = true;
    echo "  bound: model balance {$c->loyalty_points}\n";
    $p = proc_open($expiryCmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    $expiryExit = proc_close($p);
    echo "  expiry process exit {$expiryExit}; balance now ".balance($customerId)."\n";
});
[$status, $detail] = adjust($owner, $customerId, 80);
Event::forget('eloquent.retrieved: '.Customer::class);
$answer = outcome($status, $detail, $customerId);
$after = balance($customerId);
echo "  response: {$status} {$detail} → {$answer}\n";
echo "  balance: {$after}; ledger sum: ".ledgerSum($customerId).'; balance_after chain consistent: '.(chainConsistent($customerId) ? 'yes' : 'NO')."\n";
echo '  ledger: '.implode(' | ', ledger($customerId))."\n";
$safe1 = check([
    'the expiry process ran while the request held the bound model' => $expiryRan,
    'the expiry process exited 0' => $expiryExit === 0,
    'the expiry wrote one row, of 100' => expiries($customerId) === [100],
    'the redemption was refused (302, Insufficient loyalty points)' => $answer === 'refused',
    'nothing written for the redemption' => redemptions($customerId) === 0,
    'balance 10, equal to the ledger sum, chain consistent' => $after === 10 && $after === ledgerSum($customerId) && chainConsistent($customerId),
]);
echo $safe1 ? "  SAFE: refused against the fresh balance; nothing written for it\n" : "  UNSAFE\n";

// ── 2. a real race ───────────────────────────────────────────────────────
echo "\n── 2. expiry and redemptions in separate processes, started together ──\n";
$ids = [];
for ($i = 0; $i < 60; $i++) {
    $ids[] = $holder();
}
$chunks = array_chunk($ids, 20);
$procs = [['php', __FILE__, 'expire', (string) $shop->id]];
foreach ($chunks as $chunk) {
    $procs[] = ['php', __FILE__, 'redeem', (string) $owner->id, implode(',', $chunk), '60'];
}
$running = [];
foreach ($procs as $i => $cmd) {
    $running[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
}
$workersOk = true;
$reported = ['redeemed' => 0, 'refused' => 0];
$otherAnswers = [];
foreach ($running as $i => $p) {
    $stdout = stream_get_contents($pipes[$i][1]);
    $stderr = trim(stream_get_contents($pipes[$i][2]));
    $code = proc_close($p);
    $ok = $code === 0;
    if ($i > 0) {
        $counts = answers($stdout);
        $ok = $ok && $counts !== null && array_sum($counts) === count($chunks[$i - 1]);
        foreach ($counts ?? [] as $answer => $n) {
            isset($reported[$answer]) ? $reported[$answer] += $n : $otherAnswers[$answer] = ($otherAnswers[$answer] ?? 0) + $n;
        }
    }
    $workersOk = $workersOk && $ok;
    echo '  '.($i === 0 ? 'expiry' : "redeemer {$i}")." exit {$code}".($i === 0 ? ': '.trim($stdout) : ': '.json_encode($counts ?? null, JSON_UNESCAPED_SLASHES))
        .(! $ok && $stderr !== '' ? ' — '.mb_substr($stderr, 0, 160) : '')."\n";
}
$negative = $inconsistent = $chainBroken = $redeemed = $expiredOnce = $unexpected = 0;
foreach ($ids as $id) {
    $b = balance($id);
    $expiry = expiries($id);
    $redemption = redemptions($id);
    $negative += $b < 0 ? 1 : 0;
    $inconsistent += $b !== ledgerSum($id) ? 1 : 0;
    $chainBroken += chainConsistent($id) ? 0 : 1;
    $redeemed += $redemption;
    $expiredOnce += count($expiry) === 1 ? 1 : 0;
    $unexpected += $b === 10 && (($redemption === 0 && $expiry === [100]) || ($redemption === 1 && $expiry === [40])) ? 0 : 1;
}
echo '  customers: '.count($ids)."; answers: redeemed {$reported['redeemed']}, refused {$reported['refused']}"
    .($otherAnswers === [] ? '' : ', other '.json_encode($otherAnswers, JSON_UNESCAPED_SLASHES))
    ."; redemptions recorded: {$redeemed}; customers expired exactly once: {$expiredOnce}\n";
echo "  negative balances: {$negative}; balance ≠ ledger sum: {$inconsistent}; balance_after chain broken: {$chainBroken}; not in an expected end state: {$unexpected}\n";
$safe2 = check([
    'every worker exited 0 and answered every request it was given' => $workersOk,
    'every request was answered redeemed or refused (no 500, nothing else)' => $otherAnswers === [],
    'every request was answered' => $reported['redeemed'] + $reported['refused'] === count($ids),
    'the race interleaved: both orders occurred (a redemption before expiry, and expiry before a redemption)' => $reported['redeemed'] > 0 && $reported['refused'] > 0,
    'the redemptions recorded are exactly those the workers reported' => $redeemed === $reported['redeemed'],
    'every customer expired exactly once' => $expiredOnce === count($ids),
    'every customer ended at 10 with one expiry: 100 if refused, 40 if redeemed first' => $unexpected === 0,
    'no negative balance; every balance equals its ledger sum; every chain consistent' => $negative === 0 && $inconsistent === 0 && $chainBroken === 0,
]);
echo $safe2 ? "  SAFE\n" : "  UNSAFE\n";

// ── 3. the lock, deterministically ───────────────────────────────────────
// This process holds the customer row as an expiry does mid-flight — locked,
// decremented, its ledger row written, not committed — while a redemption of
// 80 runs in a child process. Only once PostgreSQL shows the child waiting on
// a lock does this commit. With the shared row lock the child reads 10 after
// the commit and refuses; without it the child checks a snapshot of 110 and
// its update lands on 10.
echo "\n── 3. redemption against an expiry holding the row, uncommitted ──\n";
$lockedId = $holder();
$lot = (int) DB::table('loyalty_transactions')->where('customer_id', $lockedId)->where('description', 'harness: due')->value('id');
$holderPid = (int) DB::selectOne('select pg_backend_pid() as p')->p;
DB::beginTransaction();
DB::table('customers')->where('id', $lockedId)->lockForUpdate()->first();
DB::table('customers')->where('id', $lockedId)->decrement('loyalty_points', 100);
DB::table('loyalty_transactions')->insert(['shop_id' => $shop->id, 'customer_id' => $lockedId, 'type' => 'redeem', 'points' => 100,
    'description' => 'Points expired', 'balance_after' => 10, 'expires_lot_id' => $lot, 'expired' => DB::raw('false'), 'created_at' => now(), 'updated_at' => now()]);
$child = proc_open(['php', __FILE__, 'redeem', (string) $owner->id, (string) $lockedId, '80'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cp, base_path(), $env);
// The child names its backend before it redeems; wait until THAT backend is
// waiting on a lock and THIS process's backend is what blocks it.
$childPid = preg_match('/^pid (\d+)/', (string) fgets($cp[1]), $m) ? (int) $m[1] : null;
$waited = false;
$monitor = new PDO('pgsql:host='.config('database.connections.pgsql.host').';port='.config('database.connections.pgsql.port').';dbname=jewelflow_testing',
    config('database.connections.pgsql.username'), config('database.connections.pgsql.password'));
$blocked = $monitor->prepare("select coalesce(bool_or(wait_event_type = 'Lock' and ?::int = any(pg_blocking_pids(pid))), false) from pg_stat_activity where pid = ?");
for ($i = 0; $childPid !== null && $i < 200 && ! $waited; $i++) {
    usleep(50000);
    $blocked->execute([$holderPid, $childPid]);
    $waited = (bool) $blocked->fetchColumn();
}
DB::commit();
$childOut = stream_get_contents($cp[1]);
$childErr = trim(stream_get_contents($cp[2]));
$childExit = proc_close($child);
$childAnswers = answers($childOut);
$b3 = balance($lockedId);
echo '  child backend '.($childPid ?? 'NOT REPORTED').", holder backend {$holderPid}; child seen blocked by the holder before the commit: "
    .($waited ? 'yes' : 'NO')."; child exit {$childExit}: ".json_encode($childAnswers, JSON_UNESCAPED_SLASHES)
    .($childExit !== 0 && $childErr !== '' ? ' — '.mb_substr($childErr, 0, 160) : '')."\n";
echo "  balance: {$b3}; ledger sum: ".ledgerSum($lockedId).'; chain consistent: '.(chainConsistent($lockedId) ? 'yes' : 'NO')."\n";
echo '  ledger: '.implode(' | ', ledger($lockedId))."\n";
$safe3 = check([
    'the child reported its database backend' => $childPid !== null,
    "the child's backend waited on a lock held by this process's backend" => $waited,
    'the child exited 0' => $childExit === 0,
    'the redemption was refused (302, Insufficient loyalty points)' => $childAnswers === ['refused' => 1],
    'nothing written for the redemption; the one expiry row of 100' => redemptions($lockedId) === 0 && expiries($lockedId) === [100],
    'balance 10, equal to the ledger sum, chain consistent' => $b3 === 10 && $b3 === ledgerSum($lockedId) && chainConsistent($lockedId),
]);
echo $safe3 ? "  SAFE\n" : "  UNSAFE\n";

echo ($safe1 && $safe2 && $safe3) ? "RESULT: SAFE\n" : 'RESULT: UNSAFE — scenario'.($safe1 ? '' : ' 1').($safe2 ? '' : ' 2').($safe3 ? '' : ' 3')."\n";
exit(($safe1 && $safe2 && $safe3) ? 0 : 1);
