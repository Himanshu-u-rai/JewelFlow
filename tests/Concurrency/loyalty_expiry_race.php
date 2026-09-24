<?php

/**
 * S3-16 — concurrent `loyalty:expire` runs, real processes.
 *
 *   php tests/Concurrency/loyalty_expiry_race.php
 *
 * One shop, N customers, each holding a lot that fell due after activation
 * and a lot that is not yet due. Three `php artisan loyalty:expire` processes
 * start together (activation passed through the environment, as the
 * scheduler's config would carry it). SAFE: every due lot has exactly one
 * expiry row, every balance lost exactly the due lot, the future lots are
 * intact, and no process failed. The customer row lock serializes the runs;
 * the unique expires_lot_id is the backstop (LoyaltyExpiryTest).
 *
 * Writes committed fixtures to jewelflow_testing; refuses any other database.
 */

use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}

const CUSTOMERS = 150;
const RUNS = 3;

final class ExpiryFixture
{
    use CreatesTestTenant;

    public function shop(): array
    {
        return $this->createRetailerTenant();
    }

    public function customer(int $shopId)
    {
        return $this->createCustomer($shopId);
    }
}

$fixture = new ExpiryFixture;
[, $shop] = $fixture->shop();
$dueLots = [];
$customers = [];
TenantContext::runFor((int) $shop->id, function () use ($fixture, $shop, &$dueLots, &$customers) {
    for ($i = 0; $i < CUSTOMERS; $i++) {
        $c = $fixture->customer((int) $shop->id);
        $dueLots[] = (int) $c->addLoyaltyPoints(100, null, 'race: due', now()->subDay())->id;
        $c->fresh()->addLoyaltyPoints(10, null, 'race: future', now()->addYear());
        $customers[] = (int) $c->id;
    }
});

$env = ['LOYALTY_EXPIRY_ACTIVE_FROM' => now()->subDays(7)->toDateString()] + getenv();
$procs = [];
for ($i = 0; $i < RUNS; $i++) {
    // Wall-clock start/end around each run, to show the runs overlapped.
    $procs[] = proc_open(['bash', '-c', 'date +%s.%N; php artisan loyalty:expire; e=$?; date +%s.%N; exit $e'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], dirname(__DIR__, 2), $env);
}
$runs = [];
foreach ($procs as $i => $p) {
    $out = stream_get_contents($pipes[$i][1]).stream_get_contents($pipes[$i][2]);
    $exit = proc_close($p);
    preg_match("/Shop #{$shop->id}: expired (\\d+) lot/", $out, $m);
    preg_match_all('/^(\d+\.\d+)$/m', $out, $t);
    $runs[] = ['exit' => $exit, 'expired_here' => (int) ($m[1] ?? 0), 'failed_lines' => substr_count($out, 'failed'),
        'start' => (float) ($t[1][0] ?? 0), 'end' => (float) ($t[1][1] ?? 0)];
}
$overlap = min(array_column($runs, 'end')) - max(array_column($runs, 'start'));

$perLot = DB::table('loyalty_transactions')->whereIn('expires_lot_id', $dueLots)
    ->selectRaw('expires_lot_id, count(*) as n, sum(points) as pts')->groupBy('expires_lot_id')->get();
$balances = DB::table('customers')->whereIn('id', $customers)->pluck('loyalty_points')->map(fn ($b) => (int) $b);
$ledgerMismatch = DB::table('customers as c')->whereIn('c.id', $customers)
    ->whereRaw("c.loyalty_points <> (select coalesce(sum(case when type = 'earn' then points else -points end), 0) from loyalty_transactions t where t.customer_id = c.id)")
    ->count();

echo 'runs: ', json_encode(array_map(fn ($r) => ['exit' => $r['exit'], 'expired' => $r['expired_here'], 'failed' => $r['failed_lines'],
    'ran_s' => round($r['end'] - $r['start'], 2)], $runs)), "\n";
printf("all %d runs were running at once for %.2f s\n", RUNS, max(0, $overlap));
printf("due lots: %d; with exactly one expiry row: %d; with more than one: %d; points expired: %d (expected %d)\n",
    count($dueLots), $perLot->where('n', 1)->count(), $perLot->where('n', '>', 1)->count(), $perLot->sum('pts'), 100 * CUSTOMERS);
printf("balances: all 10: %s; distinct values: %s; customers whose balance differs from their ledger: %d\n",
    $balances->every(fn ($b) => $b === 10) ? 'yes' : 'NO', json_encode($balances->unique()->values()), $ledgerMismatch);
printf("runs that expired something: %d of %d (the lock decides who; the split is informational)\n",
    count(array_filter($runs, fn ($r) => $r['expired_here'] > 0)), RUNS);

$safe = $perLot->count() === CUSTOMERS && $perLot->every(fn ($r) => (int) $r->n === 1 && (int) $r->pts === 100)
    && $balances->every(fn ($b) => $b === 10) && $ledgerMismatch === 0
    && array_sum(array_column($runs, 'expired_here')) === CUSTOMERS && $overlap > 0
    && collect($runs)->every(fn ($r) => $r['exit'] === 0 && $r['failed_lines'] === 0);

echo $safe ? "RESULT: SAFE — concurrent runs expired each due lot exactly once\n" : "RESULT: UNSAFE\n";
exit($safe ? 0 : 1);
