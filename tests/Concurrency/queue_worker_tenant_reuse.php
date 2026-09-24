<?php

/**
 * §7e — tenant context in a REUSED worker.
 *
 *   php tests/Concurrency/queue_worker_tenant_reuse.php
 *
 * The queue worker is the one runtime here that keeps one PHP process — and
 * its static TenantContext — across work for different shops. This runs ONE
 * real `php artisan queue:work database` process over, in this order:
 *
 *   probe        records TenantContext::get() as the worker sees it
 *   export A     the real queued export job: shop A's customers report
 *   probe
 *   export A!    a job for shop A that throws INSIDE its tenant context
 *   probe
 *   mismatch     payload shop_id = A naming B's export row
 *   export B     shop B's customers report
 *   probe
 *   leaky        a job that sets context with TenantContext::set() and
 *                never restores it — no application job does this
 *   probe
 *
 * The export payloads are built with the same services and in the same shape
 * as ExportController::dispatchQueued(). The probes and the leaky job are
 * harness-owned closures.
 *
 * SAFE: the probes before `leaky` see no context; A's file names A's customer
 * and not B's, and B's the reverse; the throwing job is marked failed and
 * leaves no context behind; the mismatched job writes nothing and B's export
 * row is untouched. The probe after `leaky` measures a property of the
 * runtime, not a defect: whether the worker itself clears context between
 * jobs. Refuses any database not named jewelflow_testing.
 *
 * Each export is judged on the file it wrote (markFinished ran), not on its
 * final status: in this schema every queued export then fails at its
 * ExportReadyNotification, whose `database` channel needs a `notifications`
 * table no migration creates (S3-17, reported separately; not an isolation
 * question). The status is printed as found.
 */

use App\Jobs\Reporting\GenerateQueuedExportJob;
use App\Models\Reporting\ReportExport;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportAuditService;
use App\Services\Reporting\Filters\DatePreset;
use App\Services\Reporting\Filters\FilterResolver;
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

const QUEUE = 'tenant-reuse-probe';
$probeFile = sys_get_temp_dir().'/tenant-reuse-probe-'.getmypid().'.log';
@unlink($probeFile);
DB::table('jobs')->where('queue', QUEUE)->delete();

final class ReuseFixture
{
    use CreatesTestTenant;

    public function shop(string $customerName): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer((int) $shop->id, ['first_name' => $customerName]);

        return [$owner, $shop];
    }
}

/** An export row plus the job payload, exactly as ExportController::dispatchQueued() builds them. */
function queued_export($owner, $shop, string $reportKey = 'customers'): array
{
    return TenantContext::runFor((int) $shop->id, function () use ($owner, $shop, $reportKey) {
        $registry = app(ReportRegistry::class);
        $definition = $registry->definition('customers');
        $period = app(FilterResolver::class)->resolve(DatePreset::ThisMonth);
        $profile = ReportProfile::Detailed;
        $format = ExportFormat::Csv;
        $columns = app(ColumnPolicy::class)->resolve($definition, $profile, $owner);
        $request = new ReportRequest(definition: $definition, shopId: (int) $shop->id, userId: (int) $owner->id,
            userName: (string) $owner->name, profile: $profile, format: $format,
            filters: ['period' => ['from' => $period->from, 'to' => $period->to]],
            columnKeys: $columns->columnKeys, includeSensitive: false, revealMasked: false);
        $export = app(ExportAuditService::class)->recordQueued($request, false);

        return [$export->id, [
            'export_id' => $export->id, 'report_key' => $reportKey, 'shop_id' => (int) $shop->id,
            'user_id' => (int) $owner->id, 'user_name' => (string) $owner->name,
            'profile' => $profile->value, 'format' => $format->value, 'date_preset' => DatePreset::ThisMonth->value,
            'date_from' => $period->from->toIso8601String(), 'date_to' => $period->to->toIso8601String(), 'fy_name' => null,
            'filters' => $request->filters, 'column_keys' => $request->columnKeys, 'include_sensitive' => false,
            'reveal_masked' => false, 'filters_applied' => ['Period' => $period->label], 'watermark' => null,
            'shop' => ['legal_name' => (string) $shop->name, 'address' => null, 'gstin' => null, 'state_code' => null],
        ]];
    });
}

function probe(string $file, string $label): void
{
    dispatch(function () use ($file, $label) {
        file_put_contents($file, $label.'='.json_encode(TenantContext::get())."\n", FILE_APPEND);
    })->onConnection('database')->onQueue(QUEUE);
}

function queue_export(array $payload): void
{
    GenerateQueuedExportJob::dispatch($payload)->onConnection('database')->onQueue(QUEUE);
}

$fixture = new ReuseFixture;
[$ownerA, $shopA] = $fixture->shop('AlphaOnlyXR');
[$ownerB, $shopB] = $fixture->shop('BravoOnlyXR');
[$exportA, $payloadA] = queued_export($ownerA, $shopA);
[$exportA2, $payloadA2] = queued_export($ownerA, $shopA, 'no-such-report');
[$exportB, $payloadB] = queued_export($ownerB, $shopB);
[$exportBUnrun] = queued_export($ownerB, $shopB);
$mismatch = ['export_id' => $exportBUnrun, 'shop_id' => (int) $shopA->id] + $payloadA;

probe($probeFile, 'before-A');
queue_export($payloadA);
probe($probeFile, 'after-A');
queue_export($payloadA2);
probe($probeFile, 'after-failing-A');
queue_export($mismatch);
queue_export($payloadB);
probe($probeFile, 'after-B');
$leakyShop = (int) $shopA->id;
dispatch(function () use ($leakyShop) {
    TenantContext::set($leakyShop);
})->onConnection('database')->onQueue(QUEUE);
probe($probeFile, 'after-leaky');

$worker = proc_open(['php', 'artisan', 'queue:work', 'database', '--queue='.QUEUE, '--stop-when-empty', '--tries=1', '--sleep=0'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
$workerOut = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
$workerExit = proc_close($worker);

// ── what the one worker process did ───────────────────────────────────────
$probes = [];
foreach (file($probeFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    [$label, $value] = explode('=', $line, 2);
    $probes[$label] = json_decode($value);
}
$rows = fn (int $id) => ReportExport::withoutGlobalScopes()->find($id);
$contents = function (?ReportExport $e): string {
    return $e && $e->file_path ? (string) Storage::disk($e->file_disk)->get($e->file_path) : '';
};
$a = $rows($exportA);
$a2 = $rows($exportA2);
$b = $rows($exportB);
$bUnrun = $rows($exportBUnrun);

echo 'worker exit ', $workerExit, '; jobs left on the queue: ', DB::table('jobs')->where('queue', QUEUE)->count(), "\n";
echo 'context seen by each probe: ', json_encode($probes), "\n";
printf("export A:  status %s; names AlphaOnlyXR: %s; names BravoOnlyXR: %s\n", $a?->status, str_contains($contents($a), 'AlphaOnlyXR') ? 'yes' : 'no', str_contains($contents($a), 'BravoOnlyXR') ? 'YES' : 'no');
printf("export A!: status %s (a job that threw inside shop A's context)\n", $a2?->status);
printf("export B:  status %s; names BravoOnlyXR: %s; names AlphaOnlyXR: %s\n", $b?->status, str_contains($contents($b), 'BravoOnlyXR') ? 'yes' : 'no', str_contains($contents($b), 'AlphaOnlyXR') ? 'YES' : 'no');
printf("mismatched payload (shop A, B's export row): B's row status %s, file %s\n", $bUnrun?->status, $bUnrun?->file_path ?: 'none');

// array_key_exists, not ??: a probe that recorded null must count as null.
$saw = fn (string $k) => array_key_exists($k, $probes) ? $probes[$k] : 'NOT RUN';
$safe = $saw('before-A') === null && $saw('after-A') === null && $saw('after-failing-A') === null && $saw('after-B') === null
    && str_contains($contents($a), 'AlphaOnlyXR') && ! str_contains($contents($a), 'BravoOnlyXR')
    && str_contains($contents($b), 'BravoOnlyXR') && ! str_contains($contents($b), 'AlphaOnlyXR')
    && $a2?->status === ExportAuditService::STATUS_FAILED && ! $a2?->file_path
    && $bUnrun?->status === ExportAuditService::STATUS_QUEUED && ! $bUnrun?->file_path;
printf("final status of exports A and B: %s, %s%s\n", $a?->status, $b?->status,
    $a?->status === ExportAuditService::STATUS_FAILED ? ' — S3-17: '.mb_substr((string) $a->error, 0, 80) : '');

echo 'runtime property — after a job that set context and did not restore it, the next job saw: ',
    json_encode($saw('after-leaky')), $saw('after-leaky') === null ? ' (cleared by the worker)' : ' (NOT cleared by the worker)', "\n";

foreach ([$a, $b] as $e) {
    if ($e?->file_path) {
        Storage::disk($e->file_disk)->delete($e->file_path);
    }
}
@unlink($probeFile);

echo $safe ? "RESULT: SAFE — sequential shops in one worker, context restored after success and failure, each export only its own shop\n"
           : "RESULT: UNSAFE\n".mb_substr($workerOut, -1500)."\n";
exit($safe ? 0 : 1);
