<?php

/**
 * XR-05 / S3-12 — two workers edit one item from the same starting version.
 *
 *   php tests/Concurrency/etag_race.php
 *
 * Both read the same ETag, then PATCH different prices with DISTINCT
 * idempotency keys and that same If-Match. This process holds the item's row
 * lock while they start, so both are past the controller's early If-Match
 * check before either can write — the window that a check against the
 * already-loaded model cannot close. The lock is then released.
 *
 * Safe outcome: exactly one 200 and one 412 precondition_failed, and the
 * stored price is the winner's. Unsafe: two 200s — one operator's edit silently
 * lost.
 *
 * Refuses any database not named jewelflow_testing.
 */

use App\Models\Item;
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

function mobile(int $shopId, string $method, string $uri, string $token, array $headers = [], ?array $body = null)
{
    TenantContext::set($shopId);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return app(Kernel::class)->handle(Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body)));
}

// child: one PATCH
if (($argv[1] ?? null) === 'patch') {
    [, , $token, $shopId, $itemId, $key, $tag, $price] = $argv;
    $r = mobile((int) $shopId, 'PATCH', "/api/mobile/v1/items/{$itemId}", $token, ['X-Idempotency-Key' => $key, 'If-Match' => $tag], ['selling_price' => (int) $price]);
    echo json_encode(['status' => $r->getStatusCode(), 'code' => json_decode($r->getContent(), true)['errors'][0]['code'] ?? null, 'price' => $price]), "\n";
    exit(0);
}

final class EtagFixture
{
    use CreatesTestTenant;

    public function build(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();

        return [$owner, $shop, $this->createItem((int) $shop->id, null, ['selling_price' => 1000])];
    }
}

[$owner, $shop, $item] = (new EtagFixture)->build();
$shopId = (int) $shop->id;
$token = $owner->createToken('xr05')->plainTextToken;
$tag = mobile($shopId, 'GET', "/api/mobile/v1/items/{$item->id}", $token)->headers->get('ETag');
echo "starting version: {$tag}\n";

DB::beginTransaction();
DB::selectOne('select id from items where id = ? for update', [$item->id]);

$workers = [];
foreach ([[2500, 'xr05-a'], [3100, 'xr05-b']] as $n => [$price, $key]) {
    $workers[$n] = proc_open(['php', __FILE__, 'patch', $token, (string) $shopId, (string) $item->id, $key.'-'.bin2hex(random_bytes(4)), $tag, (string) $price],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$n], dirname(__DIR__, 2));
}

// Both must be waiting on the row lock, i.e. past the early check.
$waiting = 0;
for ($i = 0; $i < 200 && $waiting < 2; $i++) {
    usleep(50_000);
    // pg_locks, not pg_stat_activity: the latter is one snapshot per
    // transaction, and this process is inside one.
    $waiting = (int) DB::selectOne('select count(distinct pid) c from pg_locks where not granted and pid <> pg_backend_pid()')->c;
}
echo "workers waiting on the row: {$waiting}\n";
DB::commit();

$results = [];
foreach ($workers as $n => $proc) {
    $results[] = json_decode(trim(stream_get_contents($pipes[$n][1])), true) ?? ['status' => -1, 'err' => stream_get_contents($pipes[$n][2])];
    proc_close($proc);
}
$statuses = array_column($results, 'status');
sort($statuses);
$stored = (string) Item::withoutTenant()->whereKey($item->id)->value('selling_price');
$winner = array_values(array_filter($results, fn ($r) => $r['status'] === 200));
echo 'responses: ', json_encode($results), "\n";
echo "stored price: {$stored}\n";

$safe = $waiting === 2 && $statuses === [200, 412] && count($winner) === 1 && (float) $stored === (float) $winner[0]['price'];
echo $safe ? "RESULT: SAFE — one edit won, the other was refused as stale\n"
           : "RESULT: UNSAFE — ".($statuses === [200, 200] ? 'both succeeded; one edit was silently lost' : 'unexpected outcome')."\n";
exit($safe ? 0 : 1);
