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
    $code = Illuminate\Support\Facades\Artisan::call('karigar-invoices:relocate-attachments', ['--execute' => true, '--shop' => (int) $argv[2]]);
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

$safe = $bad === [] && $temps === [] && $conflicts === 0 && $results[1]['exit'] === 0 && $results[2]['exit'] === 0;
echo $safe ? "RESULT: SAFE — 40 rows, two concurrent movers, every file verified, nothing left behind\n"
           : "RESULT: UNSAFE\n";
exit($safe ? 0 : 1);
