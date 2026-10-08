<?php

/**
 * Real multi-process probe for POST /api/mobile/quick-bills with an
 * idempotency key. The PHPUnit suite cannot schedule this race (one
 * uncommitted transaction per test); see idempotency_race.php, whose child
 * and barrier this copies.
 *
 *   php tests/Concurrency/quick_bill_create_race.php run
 *
 * Refuses any database but `jewelflow_testing`. Synthetic tenants only; rows
 * accumulate there, as with the other probes. Exits 1 unless every scenario's
 * premises and counts hold.
 */

use App\Models\QuickBill;
use App\Models\QuickBillPayment;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}

final class QuickBillRaceFixture
{
    use CreatesTestTenant;

    public function build(): array
    {
        return $this->createRetailerTenant();
    }
}

function payload(int $rate = 1000): array
{
    return [
        'bill_date' => now()->toDateString(),
        'pricing_mode' => 'no_gst',
        'gst_rate' => 0,
        'round_off' => 0,
        'save_action' => 'issue',
        'customer_name' => 'Synthetic Walk-in',
        'items' => [['description' => 'Race probe item', 'pcs' => 1, 'gross_weight' => 1, 'net_weight' => 1, 'rate' => $rate]],
        'payments' => [['payment_mode' => 'cash', 'amount' => $rate]],
    ];
}

/** CHILD: warm the connection, wait for the shared instant, send one real request. */
function fire(string $token, string $key, float $startMicro, string $payloadB64): void
{
    $kernel = app(Kernel::class);
    DB::select('select 1');

    while (microtime(true) < $startMicro) {
        $remaining = $startMicro - microtime(true);
        if ($remaining > 0.002) {
            usleep((int) (($remaining - 0.001) * 1_000_000));
        }
    }

    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
    if ($key !== '-') {
        $server['HTTP_X_IDEMPOTENCY_KEY'] = $key;
    }

    $sent = microtime(true);

    try {
        $response = $kernel->handle(Request::create('/api/mobile/quick-bills', 'POST', [], [], [], $server, base64_decode($payloadB64)));
        $status = $response->getStatusCode();
        $decoded = json_decode($response->getContent(), true);
        $replay = $response->headers->get('X-Idempotent-Replay');
    } catch (Throwable $e) {
        $status = -1;
        $decoded = ['fatal' => get_class($e) . ': ' . $e->getMessage()];
        $replay = null;
    }

    fwrite(STDOUT, json_encode([
        'status' => $status,
        'code' => $decoded['errors'][0]['code'] ?? null,
        'replay' => $replay,
        'bill' => $decoded['quick_bill']['id'] ?? null,
        'sent_at' => $sent,
        'done_at' => microtime(true),
        'fatal' => $decoded['fatal'] ?? null,
    ]) . "\n");
}

/** PARENT: release every child at one instant; return what each one got. */
function race(string $token, array $specs): array
{
    $start = microtime(true) + 2.0;
    $procs = [];

    foreach ($specs as $i => [$key, $payload]) {
        $cmd = sprintf('%s %s fire %s %s %.6f %s', escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__), escapeshellarg($token), escapeshellarg($key), $start, escapeshellarg(base64_encode(json_encode($payload))));
        $procs[$i] = ['proc' => proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2)), 'pipes' => $pipes];
    }

    $results = [];
    foreach ($procs as $i => $p) {
        $out = stream_get_contents($p['pipes'][1]);
        $err = stream_get_contents($p['pipes'][2]);
        proc_close($p['proc']);
        $results[$i] = json_decode(trim(strtok(trim($out), "\n") ?: ''), true) ?: ['status' => -1, 'fatal' => trim($out . ' ' . $err)];
    }

    return $results;
}

function booked(int $shopId): array
{
    $ids = QuickBill::withoutGlobalScopes()->where('shop_id', $shopId)->pluck('id');

    return [
        'bills' => $ids->count(),
        'payments' => QuickBillPayment::withoutGlobalScopes()->whereIn('quick_bill_id', $ids)->count(),
        'numbers' => QuickBill::withoutGlobalScopes()->where('shop_id', $shopId)->distinct()->count('bill_number'),
    ];
}

/** Requests overlapped if some request was sent before another had finished. */
function overlapped(array $results): bool
{
    $firstDone = min(array_map(fn ($r) => $r['done_at'] ?? INF, $results));
    $sentBefore = count(array_filter($results, fn ($r) => ($r['sent_at'] ?? INF) < $firstDone));

    return $sentBefore >= 2;
}

function scenario(string $name, array $specs, callable $verdict): bool
{
    [$user, $shop] = (new QuickBillRaceFixture())->build();
    $token = $user->createToken('quick-bill-race')->plainTextToken;

    // One bill first, alone. A shop's very first quick bill also creates its
    // number counter, and concurrent first bills collide on THAT (the losers
    // get a 500 and book nothing: seen here, not part of this probe).
    $warm = race($token, [['-', payload(500)]]);
    if (($warm[0]['status'] ?? 0) !== 201) {
        echo "\n--- {$name} ---\nwarm-up bill failed: " . json_encode($warm[0]) . "\nVERDICT: NOT SAFE\n";

        return false;
    }
    $before = booked($shop->id);

    $results = race($token, $specs);
    $booked = array_map(fn ($after, $base) => $after - $base, booked($shop->id), $before);
    $booked = array_combine(['bills', 'payments', 'numbers'], $booked);

    $tally = [];
    foreach ($results as $r) {
        $label = $r['status'] . ($r['code'] ? ' ' . $r['code'] : '') . ($r['replay'] ? ' replay' : '');
        $tally[$label] = ($tally[$label] ?? 0) + 1;
    }
    ksort($tally);

    $fatal = array_filter(array_map(fn ($r) => $r['fatal'] ?? null, $results));
    $spread = (max(array_column($results, 'sent_at')) - min(array_column($results, 'sent_at'))) * 1000;
    $ok = $fatal === [] && overlapped($results) && $verdict($results, $booked);

    echo "\n--- {$name} ---\n";
    echo 'responses: ' . json_encode($tally) . "\n";
    echo "booked: {$booked['bills']} bill(s), {$booked['payments']} payment(s), {$booked['numbers']} distinct number(s)\n";
    printf("requests sent within %.1f ms of each other; overlapped: %s\n", $spread, overlapped($results) ? 'yes' : 'NO');
    foreach ($fatal as $f) {
        echo "  !! child failed: {$f}\n";
    }
    echo 'VERDICT: ' . ($ok ? 'SAFE' : 'NOT SAFE') . "\n";

    return $ok;
}

if (($argv[1] ?? 'run') === 'fire') {
    fire($argv[2], $argv[3], (float) $argv[4], $argv[5]);
    exit(0);
}

echo "quick-bill create: real multi-process probe\n";
echo 'db: ' . DB::connection()->getDatabaseName() . '  git: ' . trim(shell_exec('git rev-parse --short HEAD') ?: '?') . "\n";

$key = 'qb-race-' . bin2hex(random_bytes(8));
$passed = [];

// A. Six identical requests with one key: one books, the rest are refused or replayed.
$passed[] = scenario('A. six identical requests, one key', array_fill(0, 6, [$key, payload()]), function (array $results, array $booked) {
    $created = array_filter($results, fn ($r) => $r['status'] === 201 && ! $r['replay']);
    $others = array_filter($results, fn ($r) => ! ($r['status'] === 201 && ! $r['replay']));
    $othersSafe = array_filter($others, fn ($r) => ($r['status'] === 201 && $r['replay']) || ($r['status'] === 409 && $r['code'] === 'idempotency_in_flight'));
    $oneBill = count(array_unique(array_filter(array_column($results, 'bill')))) === 1;

    return count($created) === 1 && count($othersSafe) === count($others) && $oneBill
        && $booked === ['bills' => 1, 'payments' => 1, 'numbers' => 1];
});

// B. One key, two different payloads: one books; the other payload is never booked.
$passed[] = scenario('B. one key, two payloads', [[$key . 'b', payload(1000)], [$key . 'b', payload(2000)], [$key . 'b', payload(1000)], [$key . 'b', payload(2000)]], function (array $results, array $booked) {
    $created = array_filter($results, fn ($r) => $r['status'] === 201 && ! $r['replay']);
    $refused = array_filter($results, fn ($r) => $r['status'] === 409 && in_array($r['code'], ['idempotency_in_flight', 'idempotency_key_conflict'], true));
    $replayed = array_filter($results, fn ($r) => $r['status'] === 201 && $r['replay']);

    return count($created) === 1 && count($created) + count($refused) + count($replayed) === count($results)
        && $booked === ['bills' => 1, 'payments' => 1, 'numbers' => 1];
});

// C. Control. The same race WITHOUT a key must book every request: it shows the
// requests really ran side by side and that this probe can see a duplicate.
$passed[] = scenario('C. control: four requests, no key', array_fill(0, 4, ['-', payload()]), function (array $results, array $booked) {
    return count(array_filter($results, fn ($r) => $r['status'] === 201)) === 4
        && $booked === ['bills' => 4, 'payments' => 4, 'numbers' => 4];
});

$all = ! in_array(false, $passed, true);
echo "\nRESULT: " . ($all ? 'ALL SCENARIOS SAFE' : 'FAILED') . ' (' . count(array_filter($passed)) . ' of ' . count($passed) . ")\n";
exit($all ? 0 : 1);
