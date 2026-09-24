<?php

/**
 * XR-05 / S3-12, second review — does a response's ETag describe the data in
 * that same response?
 *
 *   php tests/Concurrency/etag_image_race.php
 *
 * Two windows, each for items and for customers, in real processes:
 *
 *   GET   — a competing commit lands after the row is loaded and before the
 *           response tag is built.
 *   PATCH — a competing commit lands after the PATCH commits and before the
 *           response tag is built.
 *
 * The requesting child pauses itself at that point (instrumentation inside the
 * harness's own process); this process then commits the competing write and
 * lets it continue. The competing write keeps updated_at as stored, i.e. it
 * commits within the same stored second — the case a timestamp(0) column
 * cannot tell apart.
 *
 * The client then edits what it was SHOWN, sending the tag it was GIVEN.
 * UNSAFE: the edit is accepted and overwrites a value the client never saw.
 * SAFE: it is refused (412), or the value it overwrites is the one it was shown.
 *
 * The two-writer race stays in tests/Concurrency/etag_race.php.
 * Refuses any database not named jewelflow_testing.
 */

use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}

const FIELD = ['items' => 'selling_price', 'customers' => 'notes'];

function call(int $shopId, string $method, string $uri, string $token, array $headers = [], ?array $body = null): array
{
    TenantContext::set($shopId);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $r = app(Kernel::class)->handle(Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body)));
    $json = json_decode($r->getContent(), true);

    return ['status' => $r->getStatusCode(), 'data' => $json['data'] ?? null, 'code' => $json['errors'][0]['code'] ?? null, 'tag' => $r->headers->get('ETag')];
}

/** Pause at the first query once $armed() says so; report, and wait for the parent. */
function pause_when(Closure $armed): void
{
    $paused = false;
    DB::connection()->beforeExecuting(function () use ($armed, &$paused) {
        if (! $paused && $armed()) {
            $paused = true;
            fwrite(STDOUT, "paused\n");
            fgets(STDIN);
        }
    });
}

// ── child ─────────────────────────────────────────────────────────────────
if (in_array($argv[1] ?? null, ['get', 'patch'], true)) {
    [, $mode, $token, $shopId, $table, $id] = $argv;
    $pause = ($argv[6] ?? '') === 'pause';

    if ($mode === 'get' && $pause) {
        // Armed once the row itself has been loaded (route binding).
        $loaded = false;
        DB::listen(function ($q) use (&$loaded, $table) { $loaded = $loaded || str_starts_with($q->sql, "select * from \"{$table}\""); });
        pause_when(function () use (&$loaded) { return $loaded; });
    }
    if ($mode === 'patch' && $pause) {
        // Armed once the transaction that wrote the row has committed.
        $wrote = $committed = false;
        DB::listen(function ($q) use (&$wrote, $table) { $wrote = $wrote || str_starts_with($q->sql, "update \"{$table}\""); });
        Event::listen(TransactionCommitted::class, function ($e) use (&$wrote, &$committed) {
            $committed = $committed || ($wrote && $e->connection->transactionLevel() === 0);
        });
        pause_when(function () use (&$committed) { return $committed; });
    }

    $r = $mode === 'get'
        ? call((int) $shopId, 'GET', "/api/mobile/v1/{$table}/{$id}", $token)
        : call((int) $shopId, 'PATCH', "/api/mobile/v1/{$table}/{$id}", $token,
            ['X-Idempotency-Key' => 'xr05b-'.bin2hex(random_bytes(6)), 'If-Match' => $argv[7]], [FIELD[$table] => $argv[8]]);
    echo json_encode(['status' => $r['status'], 'code' => $r['code'], 'shown' => $r['data'][FIELD[$table]] ?? null, 'tag' => $r['tag']]), "\n";
    exit(0);
}

// ── parent ────────────────────────────────────────────────────────────────
final class ImageFixture
{
    use CreatesTestTenant;

    public function build(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $shopId = (int) $shop->id;

        return [$owner->createToken('xr05b')->plainTextToken, $shopId,
            ['items' => (int) $this->createItem($shopId, null, ['selling_price' => 1000])->id,
             'customers' => (int) $this->createCustomer($shopId, ['notes' => 'v0'])->id]];
    }
}

function child(array $args, bool $paused, Closure $whilePaused): array
{
    $proc = proc_open(array_merge(['php', __FILE__], $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    if ($paused) {
        $line = trim((string) fgets($pipes[1]));
        if ($line !== 'paused') {
            proc_close($proc);

            return ['status' => -1, 'err' => "child did not pause: {$line}"];
        }
        $whilePaused();
        fwrite($pipes[0], "go\n");
    }
    $out = json_decode(trim(stream_get_contents($pipes[1])), true) ?? ['status' => -1, 'err' => mb_substr(stream_get_contents($pipes[2]), 0, 400)];
    proc_close($proc);

    return $out;
}

/** The competing write: a new value, committed, with updated_at kept as stored. */
function compete(string $table, int $id, string $value): void
{
    $field = FIELD[$table];
    DB::update("update {$table} set {$field} = ?, updated_at = updated_at where id = ?", [$value, $id]);
}

function stored(string $table, int $id): string
{
    return (string) DB::table($table)->where('id', $id)->value(FIELD[$table]);
}

function same(string $a, string $b): bool
{
    return is_numeric($a) && is_numeric($b) ? (float) $a === (float) $b : $a === $b;
}

[$token, $shopId, $ids] = (new ImageFixture)->build();
$unsafe = 0;

// Per window: the competing value, the value the client's first request
// writes, and the value of the client's follow-up edit.
$values = [
    'items' => ['get' => ['3100', '2500'], 'patch' => ['3200', '2600', '2700']],
    'customers' => ['get' => ['competing', 'client-edit'], 'patch' => ['competing-2', 'client-edit-2', 'client-edit-3']],
];

foreach ($values as $table => $v) {
    [$competing, $v1] = $v['get'];
    $id = $ids[$table];

    // ── GET window ────────────────────────────────────────────────────────
    $get = child(['get', $token, (string) $shopId, $table, (string) $id, 'pause'], true, fn () => compete($table, $id, $competing));
    $before = stored($table, $id);
    $edit = child(['patch', $token, (string) $shopId, $table, (string) $id, '', $get['tag'] ?? '', $v1], false, fn () => null);
    $safe = $edit['status'] === 412 || ($edit['status'] === 200 && same((string) $get['shown'], $before));
    $unsafe += $safe ? 0 : 1;
    printf("%-9s GET   window: shown %s; value when the client's edit arrived %s; edit with that tag -> %d%s -> %s\n",
        $table, json_encode($get['shown'] ?? $get), $before, $edit['status'], $edit['code'] ? " {$edit['code']}" : '',
        $safe ? 'SAFE' : 'UNSAFE — overwrote a value the client was never shown');

    // ── PATCH window ──────────────────────────────────────────────────────
    $fresh = child(['get', $token, (string) $shopId, $table, (string) $id], false, fn () => null);
    [$competing, $v2, $v3] = $v['patch'];
    $patch = child(['patch', $token, (string) $shopId, $table, (string) $id, 'pause', $fresh['tag'], $v2], true, fn () => compete($table, $id, $competing));
    $before = stored($table, $id);
    $edit = child(['patch', $token, (string) $shopId, $table, (string) $id, '', $patch['tag'] ?? '', $v3], false, fn () => null);
    $safe = $patch['status'] === 200 && ($edit['status'] === 412 || ($edit['status'] === 200 && same((string) $patch['shown'], $before)));
    $unsafe += $safe ? 0 : 1;
    printf("%-9s PATCH window: response %d shown %s; value when the next edit arrived %s; edit with the response tag -> %d%s -> %s\n",
        $table, $patch['status'], json_encode($patch['shown'] ?? $patch), $before, $edit['status'], $edit['code'] ? " {$edit['code']}" : '',
        $safe ? 'SAFE' : 'UNSAFE — overwrote a value the client was never shown');
}

echo $unsafe === 0 ? "RESULT: SAFE — every tag describes the data it was sent with\n" : "RESULT: {$unsafe} UNSAFE window(s)\n";
exit($unsafe === 0 ? 0 : 1);
