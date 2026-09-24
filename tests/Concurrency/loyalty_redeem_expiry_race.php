<?php

/**
 * S3-16 — expiry against the other loyalty writers, real processes.
 *
 *   php tests/Concurrency/loyalty_redeem_expiry_race.php
 *
 * 1. The precise interleaving, through the real route. An owner posts a
 *    redemption of 80 to POST /loyalty/{customer}/adjust for a customer
 *    holding 110 (a lot of 100 that fell due, and 10 not yet due). Route
 *    binding loads the customer at 110; at that moment a separate
 *    `php artisan loyalty:expire` process runs and commits the 100-point
 *    expiry; then the request continues with the model it loaded. Recorded:
 *    the response, the balance, the ledger.
 * 2. A real race: per customer, a `loyalty:expire` run and redemption
 *    requests in separate processes, started together. Recorded per customer:
 *    balance against the ledger's sum, the last ledger row's balance_after,
 *    and negative balances.
 *
 * 3. The lock, deterministically: this process holds the row as a mid-flight
 *    expiry (locked, decremented, not committed) while a child redeems; it
 *    commits only once the child is seen waiting on a lock.
 *
 * SAFE: in 1 and 3, the redemption is refused against the fresh balance (10)
 * and nothing is written for it; in 2, no balance is negative, every balance
 * equals its ledger's sum, and every balance_after chain is consistent.
 * Scenario 2's interleavings depend on scheduling; with the lock removed it
 * can pass by chance — scenario 3 is the one that decides the lock.
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

// ── child: redemption requests for a list of customers, in-process through the kernel ──
if (($argv[1] ?? null) === 'redeem') {
    [, , $ownerId, $ids, $points] = $argv;
    $owner = App\Models\User::withoutGlobalScopes()->findOrFail((int) $ownerId);
    $statuses = [];
    foreach (explode(',', $ids) as $id) {
        $statuses[] = adjust($owner, (int) $id, (int) $points)[0];
    }
    echo json_encode(array_count_values($statuses)), "\n";
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

$env = ['LOYALTY_EXPIRY_ACTIVE_FROM' => now()->subDays(7)->toDateString()] + getenv();
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
Event::listen('eloquent.retrieved: '.Customer::class, function (Customer $c) use ($customerId, &$expiryRan, $env) {
    if ($expiryRan || (int) $c->id !== $customerId) {
        return;
    }
    $expiryRan = true;
    echo "  bound: model balance {$c->loyalty_points}\n";
    $p = proc_open(['php', 'artisan', 'loyalty:expire'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    echo '  expiry process exit '.proc_close($p).'; balance now '.balance($customerId)."\n";
});
[$status, $detail] = adjust($owner, $customerId, 80);
Event::forget('eloquent.retrieved: '.Customer::class);
$after = balance($customerId);
echo "  response: {$status} {$detail}\n";
echo "  balance: {$after}; ledger sum: ".ledgerSum($customerId).'; balance_after chain consistent: '.(chainConsistent($customerId) ? 'yes' : 'NO')."\n";
echo '  ledger: '.implode(' | ', ledger($customerId))."\n";
$redeemRows = DB::table('loyalty_transactions')->where('customer_id', $customerId)->where('description', 'harness redemption')->count();
$safe1 = $expiryRan && $after === 10 && $redeemRows === 0 && chainConsistent($customerId) && $after === ledgerSum($customerId);
echo $safe1 ? "  SAFE: refused against the fresh balance; nothing written for it\n" : "  UNSAFE\n";

// ── 2. a real race ───────────────────────────────────────────────────────
echo "\n── 2. expiry and redemptions in separate processes, started together ──\n";
$ids = [];
for ($i = 0; $i < 60; $i++) {
    $ids[] = $holder();
}
$procs = [['php', 'artisan', 'loyalty:expire']];
foreach (array_chunk($ids, 20) as $chunk) {
    $procs[] = ['php', __FILE__, 'redeem', (string) $owner->id, implode(',', $chunk), '60'];
}
$running = [];
foreach ($procs as $i => $cmd) {
    $running[$i] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path(), $env);
}
foreach ($running as $i => $p) {
    $out = trim(stream_get_contents($pipes[$i][1]).' '.stream_get_contents($pipes[$i][2]));
    $code = proc_close($p);
    echo '  '.($i === 0 ? 'expiry' : "redeemer {$i}")." exit {$code}".($i === 0 ? '' : ": {$out}")."\n";
}
$negative = $inconsistent = $chainBroken = $redeemed = 0;
foreach ($ids as $id) {
    $b = balance($id);
    $negative += $b < 0 ? 1 : 0;
    $inconsistent += $b !== ledgerSum($id) ? 1 : 0;
    $chainBroken += chainConsistent($id) ? 0 : 1;
    $redeemed += DB::table('loyalty_transactions')->where('customer_id', $id)->where('description', 'harness redemption')->count();
}
$expired = DB::table('loyalty_transactions')->whereIn('customer_id', $ids)->whereNotNull('expires_lot_id')->count();
echo "  customers: ".count($ids)."; redemptions recorded: {$redeemed}; expiries: {$expired}\n";
echo "  negative balances: {$negative}; balance ≠ ledger sum: {$inconsistent}; balance_after chain broken: {$chainBroken}\n";
$safe2 = $negative === 0 && $inconsistent === 0 && $chainBroken === 0;
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
DB::beginTransaction();
DB::table('customers')->where('id', $lockedId)->lockForUpdate()->first();
DB::table('customers')->where('id', $lockedId)->decrement('loyalty_points', 100);
DB::table('loyalty_transactions')->insert(['shop_id' => $shop->id, 'customer_id' => $lockedId, 'type' => 'redeem', 'points' => 100,
    'description' => 'Points expired', 'balance_after' => 10, 'expires_lot_id' => $lot, 'expired' => DB::raw('false'), 'created_at' => now(), 'updated_at' => now()]);
$child = proc_open(['php', __FILE__, 'redeem', (string) $owner->id, (string) $lockedId, '80'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $cp, base_path(), $env);
$waited = false;
$monitor = new PDO('pgsql:host='.config('database.connections.pgsql.host').';port='.config('database.connections.pgsql.port').';dbname=jewelflow_testing',
    config('database.connections.pgsql.username'), config('database.connections.pgsql.password'));
for ($i = 0; $i < 200 && ! $waited; $i++) {
    usleep(50000);
    $waited = (int) $monitor->query("select count(*) from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'")->fetchColumn() > 0;
}
DB::commit();
$childOut = trim(stream_get_contents($cp[1]).' '.stream_get_contents($cp[2]));
proc_close($child);
$b3 = balance($lockedId);
echo '  child observed waiting on a lock before the commit: '.($waited ? 'yes' : 'NO (timed out)')."; child: {$childOut}\n";
echo "  balance: {$b3}; ledger sum: ".ledgerSum($lockedId).'; chain consistent: '.(chainConsistent($lockedId) ? 'yes' : 'NO')."\n";
echo '  ledger: '.implode(' | ', ledger($lockedId))."\n";
$safe3 = $waited && $b3 === 10 && chainConsistent($lockedId) && $b3 === ledgerSum($lockedId);
echo $safe3 ? "  SAFE\n" : "  UNSAFE\n";

echo ($safe1 && $safe2 && $safe3) ? "RESULT: SAFE\n" : 'RESULT: UNSAFE — scenario'.($safe1 ? '' : ' 1').($safe2 ? '' : ' 2').($safe3 ? '' : ' 3')."\n";
exit(($safe1 && $safe2 && $safe3) ? 0 : 1);
