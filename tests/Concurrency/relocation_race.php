<?php

/**
 * XR-03 — two movers relocating the same rows at the same time.
 *
 *   php tests/Concurrency/relocation_race.php
 *
 * Real processes against the real local disks (storage/app/public and
 * storage/app/private of this checkout) and jewelflow_testing. Both run
 * `karigar-invoices:relocate-attachments --execute --shop=<id>` over the same
 * 40 rows, released at one shared instant, in the same order, so they meet on
 * every file.
 *
 * Safe outcome, checked row by row: every row recorded on the private disk has
 * a file there whose sha256 equals the public original; no file was lost or
 * torn; no mover left a temporary behind; and no row is reported as a
 * conflict or a failed copy, because the two movers are copying the same bytes.
 *
 * Cleans up the files it created (this shop's directory on both disks). Rows
 * stay: karigar_invoices is append-only by constitutional trigger.
 *
 * SCENARIO 2 (second review — the signature ledger now reuses or refuses
 * evidence under a row lock): 10 shops, each with a public signature, and two
 * `signatures:relocate --execute --shop=<id>` movers per shop, all 20 released
 * at one instant. Safe: every settings row on the private disk with a verified
 * copy, exactly one ledger row per shop recording the source digest, no
 * conflict or ledger failure reported, no temporary left, every mover exit 0.
 */

use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}

// child: wait for the shared instant, then run the mover once
if (($argv[1] ?? null) === 'mover') {
    while (microtime(true) < (float) $argv[3]) {
        usleep(1000);
    }
    $command = ($argv[4] ?? 'karigar') === 'signatures' ? 'signatures:relocate' : 'karigar-invoices:relocate-attachments';
    $code = Illuminate\Support\Facades\Artisan::call($command, ['--execute' => true, '--shop' => (int) $argv[2]]);
    echo json_encode(['exit' => $code, 'out' => Illuminate\Support\Facades\Artisan::output()]), "\n";
    exit(0);
}

final class MoverFixture
{
    use CreatesTestTenant;

    public function build(): array { return $this->createRetailerTenant(); }
}

[$owner, $shop] = (new MoverFixture)->build();
$shopId = (int) $shop->id;
$karigar = TenantContext::runFor($shopId, fn () => Karigar::create(['shop_id' => $shopId, 'name' => 'Race Karigar', 'mobile' => '98'.random_int(10000000, 99999999)]));
$dir = "karigar-invoices/{$shopId}";
$rows = [];

for ($i = 1; $i <= 40; $i++) {
    $path = "{$dir}/race-{$i}.pdf";
    $bytes = random_bytes(256 * 1024);
    Storage::disk('public')->put($path, $bytes);
    $rows[$path] = hash('sha256', $bytes);
    TenantContext::runFor($shopId, fn () => KarigarInvoice::create([
        'shop_id' => $shopId, 'karigar_id' => $karigar->id, 'mode' => KarigarInvoice::MODE_PURCHASE,
        'karigar_invoice_number' => "RACE-{$shopId}-{$i}", 'karigar_invoice_date' => now()->toDateString(),
        'payment_status' => KarigarInvoice::PAYMENT_UNPAID, 'amount_paid' => 0,
        'invoice_file_path' => $path, 'invoice_file_disk' => 'public', 'created_by_user_id' => $owner->id,
    ]));
}

$start = microtime(true) + 2.0;
$procs = [];
foreach ([1, 2] as $n) {
    $procs[$n] = proc_open(['php', __FILE__, 'mover', (string) $shopId, sprintf('%.6f', $start)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$n], dirname(__DIR__, 2));
}
$results = [];
foreach ([1, 2] as $n) {
    $results[$n] = json_decode(trim(stream_get_contents($pipes[$n][1])), true) ?? ['exit' => -1, 'out' => stream_get_contents($pipes[$n][2])];
    proc_close($procs[$n]);
}

$bad = [];
foreach ($rows as $path => $digest) {
    $disk = DB::table('karigar_invoices')->where('shop_id', $shopId)->where('invoice_file_path', $path)->value('invoice_file_disk');
    $local = Storage::disk('local')->exists($path) ? hash('sha256', Storage::disk('local')->get($path)) : null;
    if ($disk !== 'local' || $local !== $digest) {
        $bad[] = "{$path}: disk={$disk} private=".($local === null ? 'MISSING' : ($local === $digest ? 'ok' : 'TORN'));
    }
}
$temps = array_values(array_filter(Storage::disk('local')->files($dir), fn ($f) => str_contains($f, '.relocating-')));
$reported = implode("\n", array_column($results, 'out'));
$conflicts = substr_count($reported, 'already at the destination') + substr_count($reported, 'copy did not verify');

printf("mover exits: %d, %d\n", $results[1]['exit'], $results[2]['exit']);
printf("rows wrong: %d; temporaries left: %d; conflicts/failed copies reported: %d\n", count($bad), count($temps), $conflicts);
foreach (array_slice($bad, 0, 5) as $line) {
    echo "  {$line}\n";
}

Storage::disk('public')->deleteDirectory($dir);
Storage::disk('local')->deleteDirectory($dir);

$safe1 = $bad === [] && $temps === [] && $conflicts === 0 && $results[1]['exit'] === 0 && $results[2]['exit'] === 0;
echo $safe1 ? "RESULT: SAFE — 40 rows, two concurrent movers, every file verified, nothing left behind\n"
            : "RESULT: UNSAFE\n";

// ── scenario 2: signatures, two movers per shop ───────────────────────────
echo "\n== signatures: 10 shops, two concurrent movers each ==\n";
$shops = [];
for ($i = 1; $i <= 10; $i++) {
    [, $sigShop] = (new MoverFixture)->build();
    $sid = (int) $sigShop->id;
    $path = "signatures/{$sid}/race.png";
    $bytes = random_bytes(64 * 1024);
    Storage::disk('public')->put($path, $bytes);
    DB::table('shop_billing_settings')->where('shop_id', $sid)->update([
        'digital_signature_path' => $path, 'digital_signature_disk' => 'public', 'show_digital_signature' => DB::raw('true'),
    ]);
    $shops[$sid] = [$path, hash('sha256', $bytes)];
}

$start = microtime(true) + 3.0;
$procs = $pipes = [];
foreach (array_keys($shops) as $sid) {
    foreach ([1, 2] as $n) {
        $procs["{$sid}-{$n}"] = proc_open(['php', __FILE__, 'mover', (string) $sid, sprintf('%.6f', $start), 'signatures'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes["{$sid}-{$n}"], dirname(__DIR__, 2));
    }
}
$exits = $outs = [];
foreach ($procs as $k => $proc) {
    $r = json_decode(trim(stream_get_contents($pipes[$k][1])), true) ?? ['exit' => -1, 'out' => stream_get_contents($pipes[$k][2])];
    proc_close($proc);
    $exits[] = $r['exit'];
    $outs[] = $r['out'];
}

$bad = [];
foreach ($shops as $sid => [$path, $digest]) {
    $disk = DB::table('shop_billing_settings')->where('shop_id', $sid)->value('digital_signature_disk');
    $ledger = DB::table('signature_relocations')->where('shop_id', $sid)->where('path', $path)->get();
    $local = Storage::disk('local')->exists($path) ? hash('sha256', Storage::disk('local')->get($path)) : null;
    if ($disk !== 'local' || $local !== $digest || $ledger->count() !== 1 || $ledger[0]->sha256 !== $digest) {
        $bad[] = "shop {$sid}: disk={$disk} private=".($local === null ? 'MISSING' : ($local === $digest ? 'ok' : 'TORN'))
            ." ledger_rows={$ledger->count()}";
    }
}
$reported = implode("\n", $outs);
$problems = substr_count($reported, 'existing relocation evidence') + substr_count($reported, 'ledger write failed')
    + substr_count($reported, 'already at the destination') + substr_count($reported, 'copy did not verify');
$temps = 0;
foreach ($shops as $sid => [$path]) {
    $temps += count(array_filter(Storage::disk('local')->files("signatures/{$sid}"), fn ($f) => str_contains($f, '.relocating-')));
}

printf("mover exits non-zero: %d of %d\n", count(array_filter($exits, fn ($e) => $e !== 0)), count($exits));
printf("shops wrong: %d; ledger conflicts/failures reported: %d; temporaries left: %d\n", count($bad), $problems, $temps);
foreach (array_slice($bad, 0, 5) as $line) {
    echo "  {$line}\n";
}

foreach ($shops as $sid => $unused) {
    Storage::disk('public')->deleteDirectory("signatures/{$sid}");
    Storage::disk('local')->deleteDirectory("signatures/{$sid}");
}

$safe2 = $bad === [] && $problems === 0 && $temps === 0 && ! array_filter($exits, fn ($e) => $e !== 0);
echo $safe2 ? "RESULT: SAFE — 10 signatures, two movers each, one ledger row per shop, nothing left behind\n"
            : "RESULT: UNSAFE\n";
exit($safe1 && $safe2 ? 0 : 1);
