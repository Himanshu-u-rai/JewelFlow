<?php

/**
 * Populated-database migration and rollback rehearsal — the branch's ten
 * migrations, up and down in release order, against synthetic data at the
 * baseline schema.
 *
 *   php tests/Rehearsal/migration_rollback_rehearsal.php
 *
 * REFUSES to run against any database not named `jewelflow_testing`, and ends
 * with `migrate:fresh`, so it leaves that database as the test suite expects.
 *
 * WHY A SCRIPT, NOT A PHPUNIT TEST. RefreshDatabase wraps each test in one
 * transaction, so migrations, rollbacks and failed statements would not
 * behave as they do on a server. This runs every step as its own statement,
 * as a deploy would.
 *
 * BASELINE SCHEMA. The branch adds ten migrations and modifies none, so the
 * baseline schema is built from exactly the migration files in 018b3d8 —
 * checked against `git ls-tree`, not assumed — rather than from a second code
 * tree. Baseline APPLICATION code is not run. Its writes are reproduced by
 * their SQL shape (a path and no disk column), which is what the contract phase
 * has to survive.
 *
 * WHAT THIS DOES NOT DO. No code rollback. In particular it never runs the
 * cache-only payment controller: traffic-serving rollback to that code is
 * measured as double-charging (b1a52f0) and stays prohibited. The
 * invoice_payment_claims down() is exercised only as a schema step, which is
 * valid only after the code that needs the table is gone.
 */

use App\Models\IdempotencyKey;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = DB::connection()->getDatabaseName();
if ($database !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: connected to '{$database}', not jewelflow_testing.\n");
    exit(2);
}

final class RehearsalFixture
{
    use CreatesTestTenant;

    public function tenant(): array { return $this->createRetailerTenant(); }

    public function item(int $shopId) { return $this->createItem($shopId); }

    public function customer(int $shopId) { return $this->createCustomer($shopId); }
}

const BASELINE = '018b3d810e37d534f498033ab582ee41f3197c27';
const EXPAND = [
    '2026_09_15_120000_add_invoice_file_disk_to_karigar_invoices',
    '2026_09_15_140000_add_invoice_image_disk_to_stock_purchases',
    '2026_09_16_120000_add_digital_signature_disk_to_billing_settings',
    '2026_09_20_120000_create_signature_relocations_table',
];
const CLAIMS = '2026_09_21_120000_create_invoice_payment_claims_table';
const HEADERS = '2026_09_23_120000_add_response_headers_to_idempotency_keys';
const CONTRACT = '2026_09_20_130000_add_disk_column_check_constraints';
// This round: Phase 1 (before the code), and Phase 2b (after it, before the contract).
const OUTCOME = '2026_09_24_120100_add_notification_outcome_to_report_exports';
const EXPIRY_LOT = '2026_09_24_130000_add_expires_lot_id_to_loyalty_transactions';
const NOTIFICATIONS = '2026_09_24_120000_create_notifications_table';
// The layout the release writes: one directory per shop and export.
const NEW_LAYOUT = '^reporting-exports/[0-9]+/[0-9]+/[^/]+$';

$failures = 0;
function result(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("%-9s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : " — {$detail}");
}
function measured(string $label, string $detail): void
{
    printf("%-9s %s — %s\n", 'MEASURED', $label, $detail);
}
function section(string $title): void
{
    echo "\n== {$title} ==\n";
}
/** Run one migration file up, timed. */
function up(string $name): float
{
    $t = microtime(true);
    $code = Artisan::call('migrate', ['--path' => database_path("migrations/{$name}.php"), '--realpath' => true, '--force' => true]);
    if ($code !== 0) {
        throw new RuntimeException("migrate {$name} exited {$code}: ".Artisan::output());
    }
    return (microtime(true) - $t) * 1000;
}
/** Roll back the most recent migration, and prove it was the one expected. */
function down(string $name): float
{
    $last = DB::table('migrations')->orderByDesc('id')->value('migration');
    if ($last !== $name) {
        throw new RuntimeException("expected {$name} to be the last migration, found {$last}");
    }
    $t = microtime(true);
    $code = Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]);
    if ($code !== 0) {
        throw new RuntimeException("rollback {$name} exited {$code}: ".Artisan::output());
    }
    return (microtime(true) - $t) * 1000;
}
function count_where(string $table, callable $where): int
{
    return $where(DB::table($table))->count();
}
function relfilenode(string $table): int
{
    return (int) DB::selectOne('select relfilenode from pg_class where relname = ?', [$table])->relfilenode;
}
/** Migrations present as files but not recorded as run, with default paths. */
function pending(): array
{
    $ran = DB::table('migrations')->pluck('migration')->all();
    $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')));
    return array_values(array_diff($files, $ran));
}
/**
 * One in-process request through the real HTTP kernel. Tenant context is set
 * first because, under the CLI, BelongsToShop does not fall back to the
 * authenticated user, so route-model binding would 404 — the same
 * test-environment characteristic the PHPUnit suite handles the same way.
 */
function mobile(int $shopId, string $method, string $uri, string $token, array $headers = [], ?array $body = null)
{
    App\Support\TenantContext::set($shopId);
    $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => "Bearer {$token}"];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    return app(Kernel::class)->handle(Request::create($uri, $method, [], [], [], $server, $body === null ? null : json_encode($body)));
}

/** One in-process web request as $user (session guard), in the user's tenant context. */
function web($user, string $url)
{
    $request = Request::create($url, 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
    app()->instance('request', $request);
    Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
    App\Support\TenantContext::set((int) $user->shop_id);
    $response = app(Kernel::class)->handle($request);
    $body = $response instanceof Symfony\Component\HttpFoundation\StreamedResponse
        ? (function () use ($response) { ob_start(); $response->sendContent(); return (string) ob_get_clean(); })()
        : (string) $response->getContent();

    return [$response->getStatusCode(), $body];
}

/** A queued export row and its job payload, as ExportController::dispatchQueued() builds them. */
function queued_export($owner, $shop): array
{
    return App\Support\TenantContext::runFor((int) $shop->id, function () use ($owner, $shop) {
        $definition = app(App\Services\Reporting\Definition\ReportRegistry::class)->definition('customers');
        $period = app(App\Services\Reporting\Filters\FilterResolver::class)->resolve(App\Services\Reporting\Filters\DatePreset::ThisMonth);
        $columns = app(App\Services\Reporting\ColumnPolicy::class)->resolve($definition, App\Services\Reporting\Definition\ReportProfile::Detailed, $owner);
        $request = new App\Services\Reporting\Dataset\ReportRequest(definition: $definition, shopId: (int) $shop->id, userId: (int) $owner->id,
            userName: (string) $owner->name, profile: App\Services\Reporting\Definition\ReportProfile::Detailed,
            format: App\Services\Reporting\Definition\ExportFormat::Csv, filters: ['period' => ['from' => $period->from, 'to' => $period->to]],
            columnKeys: $columns->columnKeys, includeSensitive: false, revealMasked: false);
        $export = app(App\Services\Reporting\ExportAuditService::class)->recordQueued($request, false);

        return [$export->id, [
            'export_id' => $export->id, 'report_key' => 'customers', 'shop_id' => (int) $shop->id, 'user_id' => (int) $owner->id,
            'user_name' => (string) $owner->name, 'profile' => App\Services\Reporting\Definition\ReportProfile::Detailed->value,
            'format' => App\Services\Reporting\Definition\ExportFormat::Csv->value, 'date_preset' => App\Services\Reporting\Filters\DatePreset::ThisMonth->value,
            'date_from' => $period->from->toIso8601String(), 'date_to' => $period->to->toIso8601String(), 'fy_name' => null,
            'filters' => $request->filters, 'column_keys' => $request->columnKeys, 'include_sensitive' => false, 'reveal_masked' => false,
            'filters_applied' => ['Period' => $period->label], 'watermark' => null,
            'shop' => ['legal_name' => (string) $shop->name, 'address' => null, 'gstin' => null, 'state_code' => null],
        ]];
    });
}

/** Flat-layout export rows: the before/after comparison of runbook D2 (no clock involved). */
function flat_exports(): int
{
    return (int) DB::selectOne('select count(*) n from report_exports where file_path is not null and file_path !~ ?', [NEW_LAYOUT])->n;
}

$exportDirs = [];
$flatFiles = [];
try {
    // ─────────────────────────────────────────────────────────────────────
    section('A. Baseline schema from the 018b3d8 migration set');

    exec('git -C '.escapeshellarg(base_path()).' ls-tree --name-only '.BASELINE.' database/migrations/', $baselineFiles);
    $baselineNames = array_map(fn ($f) => basename($f, '.php'), $baselineFiles);
    $treeNames = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')));
    $branchOnly = array_values(array_diff($treeNames, $baselineNames));
    sort($branchOnly);
    $expectedBranchOnly = array_merge(EXPAND, [CONTRACT, CLAIMS, HEADERS, OUTCOME, EXPIRY_LOT, NOTIFICATIONS]);
    sort($expectedBranchOnly);
    result($branchOnly === $expectedBranchOnly, 'the branch adds exactly the ten migrations under test', count($baselineNames).' baseline files');
    result(array_diff($baselineNames, $treeNames) === [], 'no baseline migration was removed');

    $dir = sys_get_temp_dir().'/jf-rehearsal-baseline-'.getmypid();
    @mkdir($dir);
    foreach ($baselineNames as $name) {
        symlink(database_path("migrations/{$name}.php"), "{$dir}/{$name}.php");
    }
    $t = microtime(true);
    Artisan::call('migrate:fresh', ['--path' => $dir, '--realpath' => true, '--force' => true]);
    measured('baseline migrate:fresh', sprintf('%.0f ms', (microtime(true) - $t) * 1000));

    $pending = pending();
    sort($pending);
    result($pending === $expectedBranchOnly, 'with default paths, exactly the ten branch migrations are pending', implode(', ', $pending));
    result(! Schema::hasColumn('karigar_invoices', 'invoice_file_disk') && ! Schema::hasTable('invoice_payment_claims')
        && ! Schema::hasColumn('idempotency_keys', 'response_headers') && ! Schema::hasColumn('report_exports', 'notified_at')
        && ! Schema::hasColumn('loyalty_transactions', 'expires_lot_id') && ! Schema::hasTable('notifications'), 'none of their objects exist yet');

    // ─────────────────────────────────────────────────────────────────────
    section('B. Synthetic population at the baseline schema');

    $fixture = new RehearsalFixture;
    $tenants = [$fixture->tenant(), $fixture->tenant(), $fixture->tenant()];
    foreach ($tenants as $i => [$owner, $shop]) {
        $sid = (int) $shop->id;
        $karigar = DB::table('karigars')->insertGetId(['shop_id' => $sid, 'name' => "Rehearsal Karigar {$i}", 'created_at' => now(), 'updated_at' => now()]);

        // 400 per shop: a file on 2 of 3, and half already paid — frozen by
        // karigar_invoices_finalized_guard, which the backfill must get past.
        DB::statement("
            insert into karigar_invoices (shop_id, karigar_id, karigar_invoice_number, karigar_invoice_date, invoice_file_path, payment_status, created_at, updated_at)
            select ?, ?, 'RKI-' || g, current_date,
                   case when g % 3 <> 0 then 'karigar-invoices/' || ? || '/' || g || '.pdf' end,
                   case when g % 2 = 0 then 'paid' else 'unpaid' end, now(), now()
            from generate_series(1, 400) g", [$sid, $karigar, $sid]);

        DB::statement("
            insert into stock_purchases (shop_id, purchase_number, purchase_date, status, invoice_image, created_at, updated_at)
            select ?, 'RPO-' || ? || '-' || g, current_date, case when g % 2 = 0 then 'confirmed' else 'draft' end,
                   case when g % 3 <> 0 then 'purchases/' || g || '.jpg' end, now(), now()
            from generate_series(1, 400) g", [$sid, $sid]);

        if ($i < 2) {
            DB::table('shop_billing_settings')->where('shop_id', $sid)->update(['digital_signature_path' => "signatures/{$sid}/sig.png"]);
        }

        // Queued exports as the baseline leaves them — every one failed at its
        // notification — and a loyalty ledger (earns, redemptions, one
        // pre-trigger expiry flag), for the S3-16/S3-17 columns.
        DB::statement("
            insert into report_exports (shop_id, user_id, report_key, report_version, format, mode, status, file_disk, file_path, error, generated_at, created_at, updated_at)
            select ?, ?, 'customers', 'customers@1', 'csv', 'queued', 'failed', 'local', 'reporting-exports/customers-' || g || '.csv',
                   'relation \"notifications\" does not exist', now(), now(), now()
            from generate_series(1, 200) g", [$sid, $owner->id]);
        $customer = $fixture->customer($sid);
        DB::statement("
            insert into loyalty_transactions (shop_id, customer_id, type, points, description, balance_after, expires_at, expired, created_at, updated_at)
            select ?, ?, case when g % 4 = 0 then 'redeem' else 'earn' end, 10, 'rehearsal', 0,
                   case when g % 4 = 0 then null else now() + (g || ' days')::interval end, g = 1, now(), now()
            from generate_series(1, 400) g", [$sid, $customer->id]);

        // 1000 claims per shop, as baseline code leaves them: resolved.
        DB::statement("
            insert into idempotency_keys (shop_id, user_id, key, request_hash, response_status, response_body, created_at, updated_at)
            select ?, ?, 'rehearsal-' || g, md5(g::text) || md5(g::text), 201, '{}'::jsonb, now(), now()
            from generate_series(1, 1000) g", [$sid, $owner->id]);
    }

    $before = [
        'karigar_invoices' => DB::table('karigar_invoices')->count(),
        'stock_purchases' => DB::table('stock_purchases')->count(),
        'idempotency_keys' => DB::table('idempotency_keys')->count(),
        'shop_billing_settings' => DB::table('shop_billing_settings')->count(),
        'report_exports' => DB::table('report_exports')->count(),
        'loyalty_transactions' => DB::table('loyalty_transactions')->count(),
    ];
    measured('populated', json_encode($before));

    // ─────────────────────────────────────────────────────────────────────
    section('C. Expand phase and the independent tables, applied to populated data');

    $idemNode = relfilenode('idempotency_keys');
    $exportsNode = relfilenode('report_exports');
    $loyaltyNode = relfilenode('loyalty_transactions');
    $loyaltyGuard = fn () => (int) DB::selectOne("select count(*) c from pg_trigger where tgname = 'loyalty_transactions_append_only_trigger' and tgenabled = 'O'")->c;
    foreach (EXPAND as $m) {
        measured("up {$m}", sprintf('%.0f ms', up($m)));
    }
    measured('up '.CLAIMS, sprintf('%.0f ms', up(CLAIMS)));
    measured('up '.HEADERS, sprintf('%.0f ms', up(HEADERS)));
    measured('up '.OUTCOME, sprintf('%.0f ms', up(OUTCOME)));
    measured('up '.EXPIRY_LOT, sprintf('%.0f ms', up(EXPIRY_LOT)));
    result(relfilenode('report_exports') === $exportsNode && count_where('report_exports', fn ($q) => $q->whereNotNull('notified_at')->orWhereNotNull('notification_error')) === 0,
        'S3-17 columns added without rewriting report_exports, nothing invented for existing rows', "relfilenode {$exportsNode} unchanged");
    result(relfilenode('loyalty_transactions') === $loyaltyNode && count_where('loyalty_transactions', fn ($q) => $q->whereNotNull('expires_lot_id')) === 0,
        'S3-16 column added without rewriting loyalty_transactions, no expiry invented', "relfilenode {$loyaltyNode} unchanged");
    result($loyaltyGuard() === 1, 'the protected append-only trigger is present and enabled after the S3-16 column');

    $withFile = count_where('karigar_invoices', fn ($q) => $q->whereNotNull('invoice_file_path'));
    result($withFile > 0 && count_where('karigar_invoices', fn ($q) => $q->whereNotNull('invoice_file_path')->where('invoice_file_disk', 'public')) === $withFile,
        'karigar backfill: every row with a file labelled public', "{$withFile} rows");
    result(count_where('karigar_invoices', fn ($q) => $q->whereNull('invoice_file_path')->whereNotNull('invoice_file_disk')) === 0, 'karigar backfill: no disk invented for a row without a file');
    result(count_where('karigar_invoices', fn ($q) => $q->where('payment_status', 'paid')->whereNotNull('invoice_file_path')->where('invoice_file_disk', 'public')) > 0,
        'paid (frozen) karigar invoices were backfilled past the finalized guard');
    result(count_where('stock_purchases', fn ($q) => $q->whereNotNull('invoice_image')->whereNull('invoice_image_disk')) === 0, 'purchase backfill complete');
    result(count_where('shop_billing_settings', fn ($q) => $q->where('digital_signature_disk', 'public')) === 2, 'signature backfill: the two shops with a signature');
    result(Schema::hasTable('signature_relocations') && Schema::hasTable('invoice_payment_claims'), 'relocation ledger and payment-claim tables created');
    result(relfilenode('idempotency_keys') === $idemNode, 'response_headers added without rewriting idempotency_keys', "relfilenode {$idemNode} unchanged");
    result(count_where('idempotency_keys', fn ($q) => $q->whereNotNull('response_headers')) === 0, 'no headers invented for existing claims');

    // ─────────────────────────────────────────────────────────────────────
    section('C2. Phase 2 — the new code serving the Phase-1 schema (no notifications table)');

    result(! Schema::hasTable('notifications'), 'Phase 1 created no notifications table: a baseline export still fails at its notification until the code is live');
    // A queue worker runs under the console kernel, which binds a request from
    // app.url for URL generation (SetRequestForConsole); this script booted the
    // HTTP kernel, so bind the same.
    app()->instance('request', Request::create((string) config('app.url')));
    [$ownerA, $shopA] = $tenants[0];
    [$ownerB] = $tenants[1];
    App\Support\TenantContext::runFor((int) $shopA->id, fn () => (new RehearsalFixture)->customer((int) $shopA->id)->forceFill(['first_name' => 'PhaseTwoMarker'])->save());
    [$exportId, $payload] = queued_export($ownerA, $shopA);
    App\Jobs\Reporting\GenerateQueuedExportJob::dispatchSync($payload);
    $export = App\Models\Reporting\ReportExport::withoutGlobalScopes()->find($exportId);
    $exportDirs[] = $export->storageDirectory();
    result($export->status === 'done' && $export->notified_at === null && str_contains((string) $export->notification_error, 'notifications'),
        'the job finishes the export and records the missing table', (string) $export->notification_error);
    $link = (new App\Notifications\Reporting\ExportReadyNotification($export))->toDatabase($ownerA)['download_url'];
    [$panelStatus] = web($ownerA, url('/reports/customers/export'));
    result($panelStatus === 200, 'the export panel is served without the table', "HTTP {$panelStatus}");
    [$downloadStatus, $downloadBody] = web($ownerA, $link);
    result($downloadStatus === 200 && str_contains($downloadBody, 'PhaseTwoMarker'), 'an authorized download is served without the table', "HTTP {$downloadStatus}");
    [$foreignStatus, $foreignBody] = web($ownerB, $link);
    result($foreignStatus === 404 && ! str_contains($foreignBody, 'PhaseTwoMarker'), "another shop's user following the link is refused", "HTTP {$foreignStatus}");
    [$legacyId] = queued_export($ownerA, $shopA);
    Illuminate\Support\Facades\Storage::disk('local')->put('reporting-exports/customers-rehearsal-legacy.csv', 'legacy');
    App\Support\TenantContext::runFor((int) $shopA->id, fn () => app(App\Services\Reporting\ExportAuditService::class)->markFinished(
        App\Models\Reporting\ReportExport::withoutGlobalScopes()->find($legacyId), 1, 'local', 'reporting-exports/customers-rehearsal-legacy.csv', now()->addDay()));
    $legacyLink = (new App\Notifications\Reporting\ExportReadyNotification(App\Models\Reporting\ReportExport::withoutGlobalScopes()->find($legacyId)))->toDatabase($ownerA)['download_url'];
    $appExports = 2;   // written by the application in this section, counted in the final check
    [$legacyStatus] = web($ownerA, $legacyLink);
    result($legacyStatus === 410, 'a file stored in the old flat layout is still refused', "HTTP {$legacyStatus}");
    config(['loyalty.expiry_active_from' => null]);
    $expireCode = Artisan::call('loyalty:expire');
    result($expireCode === 0 && str_contains(Artisan::output(), 'NOT ACTIVATED')
        && DB::table('loyalty_transactions')->whereNotNull('expires_lot_id')->count() === 0, 'loyalty:expire with activation unset writes nothing');

    // ─────────────────────────────────────────────────────────────────────
    section('C3. D2 — a baseline worker still running after the switch');

    // An export queued BEFORE the switch, written AFTER it by a worker that was
    // never restarted: 018b3d8's own GenerateQueuedExportJob, loaded from git
    // under another namespace (its dependencies are unchanged since). It
    // stores a flat-layout file, marks the row done, fails at the missing
    // notifications table and marks it failed — as the baseline does.
    [$lateId, $latePayload] = queued_export($ownerA, $shopA);
    $appExports++;
    sleep(1);
    $switch = now()->toDateTimeString();   // the application's clock and timezone: how the runbook records :switch
    $flatBefore = flat_exports();
    sleep(1);
    $baselineJob = shell_exec('git -C '.escapeshellarg(base_path()).' show '.BASELINE.':app/Jobs/Reporting/GenerateQueuedExportJob.php');
    eval(str_replace(['<?php', 'namespace App\\Jobs\\Reporting;'], ['', 'namespace JewelflowBaseline;'], (string) $baselineJob));
    $baselineError = '';
    try {
        JewelflowBaseline\GenerateQueuedExportJob::dispatchSync($latePayload);
    } catch (Throwable $e) {
        $baselineError = get_class($e);
    }
    $late = App\Models\Reporting\ReportExport::withoutGlobalScopes()->find($lateId);
    $flatFiles[] = (string) $late->file_path;
    measured('D2: the baseline worker\'s record', "created_at {$late->created_at} < :switch {$switch} < finished_at {$late->finished_at}; "
        ."status {$late->status}; file_path {$late->file_path}; the job threw {$baselineError}");
    result($late->created_at->toDateTimeString() < $switch && $late->finished_at->toDateTimeString() > $switch
        && ! preg_match('#'.NEW_LAYOUT.'#', (string) $late->file_path) && Illuminate\Support\Facades\Storage::disk('local')->exists((string) $late->file_path),
        'reproduced: queued before the switch, a flat-layout file written after it');
    $byCreation = (int) DB::selectOne('select count(*) n from report_exports where created_at > ? and file_path is not null and file_path !~ ?', [$switch, NEW_LAYOUT])->n;
    $byCompletion = fn () => (int) DB::selectOne('select count(*) n from report_exports where finished_at > ? and file_path is not null and file_path !~ ?', [$switch, NEW_LAYOUT])->n;
    result($byCreation === 0, 'the former D2 check (created_at > :switch) misses it', "count {$byCreation}");
    result($byCompletion() === 1, 'the corrected D2 check (finished_at > :switch) catches it', 'count '.$byCompletion());
    result(flat_exports() === $flatBefore + 1, 'the before/after flat-layout count catches it too', $flatBefore.' -> '.flat_exports());
    $switchTs = Illuminate\Support\Carbon::parse($switch)->getTimestamp();
    $newFlatFiles = array_values(array_filter(Illuminate\Support\Facades\Storage::disk('local')->files('reporting-exports'),
        fn ($f) => Illuminate\Support\Facades\Storage::disk('local')->lastModified($f) > $switchTs));
    result($newFlatFiles === [(string) $late->file_path], 'the storage listing (flat files newer than :switch) catches it — it reads the files, not the rows',
        implode(', ', $newFlatFiles));
    // Control: the release's own job finishing after the switch is not flagged.
    [$controlId, $controlPayload] = queued_export($ownerA, $shopA);
    $appExports++;
    App\Jobs\Reporting\GenerateQueuedExportJob::dispatchSync($controlPayload);
    $control = App\Models\Reporting\ReportExport::withoutGlobalScopes()->find($controlId);
    $exportDirs[] = $control->storageDirectory();
    result($control->finished_at->toDateTimeString() > $switch && $byCompletion() === 1,
        'control: an export the release finished after the switch is not counted', (string) $control->file_path);

    // ─────────────────────────────────────────────────────────────────────
    section('D. The window between expand and contract, then contract');

    [, $s1] = $tenants[0];
    [, $s3] = $tenants[2];
    $sid1 = (int) $s1->id;
    $karigar1 = DB::table('karigars')->where('shop_id', $sid1)->value('id');

    // Baseline code still serving: a path, no disk.
    foreach (range(1, 5) as $n) {
        DB::table('karigar_invoices')->insert(['shop_id' => $sid1, 'karigar_id' => $karigar1, 'karigar_invoice_number' => "WIN-{$n}", 'karigar_invoice_date' => now()->toDateString(), 'invoice_file_path' => "karigar-invoices/{$sid1}/win-{$n}.pdf", 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_purchases')->insert(['shop_id' => $sid1, 'purchase_number' => "WIN-{$n}", 'purchase_date' => now()->toDateString(), 'status' => 'draft', 'invoice_image' => "purchases/win-{$n}.jpg", 'created_at' => now(), 'updated_at' => now()]);
    }
    // New code serving: private uploads.
    foreach (range(1, 5) as $n) {
        DB::table('karigar_invoices')->insert(['shop_id' => $sid1, 'karigar_id' => $karigar1, 'karigar_invoice_number' => "PRIV-{$n}", 'karigar_invoice_date' => now()->toDateString(), 'invoice_file_path' => "karigar-invoices/{$sid1}/priv-{$n}.pdf", 'invoice_file_disk' => 'local', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('stock_purchases')->insert(['shop_id' => $sid1, 'purchase_number' => "PRIV-{$n}", 'purchase_date' => now()->toDateString(), 'status' => 'draft', 'invoice_image' => "purchases/{$sid1}/priv-{$n}.jpg", 'invoice_image_disk' => 'local', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('shop_billing_settings')->where('shop_id', $s3->id)->update(['digital_signature_path' => "signatures/{$s3->id}/private.png", 'digital_signature_disk' => 'local']);
    // Baseline "remove" branch: nulls the path, strands the disk.
    $stranded = DB::table('stock_purchases')->where('shop_id', $sid1)->whereNotNull('invoice_image')->where('invoice_image_disk', 'public')->value('id');
    DB::table('stock_purchases')->where('id', $stranded)->update(['invoice_image' => null]);

    $window = ['karigar_invoices' => 10, 'stock_purchases' => 10];

    // Phase 2b — after the code, before the contract.
    measured('up '.NOTIFICATIONS, sprintf('%.0f ms', up(NOTIFICATIONS)));
    result(Schema::hasTable('notifications'), 'notifications table created (Phase 2b)');
    $sendCode = Artisan::call('reporting:notify-export', ['--send' => true]);
    $delivered = DB::table('notifications')->whereRaw("(data::jsonb ->> 'export_id') = ?", [(string) $exportId])->count();
    result($sendCode === 0 && $delivered === 1, 'Phase 2b: the export finished without the table is delivered once, not regenerated',
        'file '.(App\Models\Reporting\ReportExport::withoutGlobalScopes()->find($exportId)->file_path === $export->file_path ? 'unchanged' : 'CHANGED'));
    [$panelStatus, $panelBody] = web($ownerA, url('/reports/customers/export'));
    result($panelStatus === 200 && str_contains($panelBody, 'Ready to download'), 'Phase 2b: the panel lists it');
    [$downloadStatus] = web($ownerA, $link);
    $readAt = DB::table('notifications')->whereRaw("(data::jsonb ->> 'export_id') = ?", [(string) $exportId])->value('read_at');
    result($downloadStatus === 200 && $readAt !== null, 'Phase 2b: the download is served and marks it read', "HTTP {$downloadStatus}");

    ob_start();
    measured('up '.CONTRACT, sprintf('%.0f ms', up(CONTRACT)));
    $contractOut = ob_get_clean();
    echo $contractOut;

    $validated = DB::select("select conname, convalidated from pg_constraint where conname in ('karigar_invoices_attachment_disk_check','stock_purchases_invoice_image_disk_check','shop_billing_settings_digital_signature_disk_check')");
    result(count($validated) === 3 && collect($validated)->every(fn ($c) => $c->convalidated), 'all three constraints present and VALIDATED', json_encode($validated));
    result(count_where('karigar_invoices', fn ($q) => $q->where('karigar_invoice_number', 'like', 'WIN-%')->where('invoice_file_disk', 'public')) === 5, 'window rows written by baseline code were labelled, not rejected');
    result(DB::table('stock_purchases')->where('id', $stranded)->value('invoice_image_disk') === null, 'the stranded disk value was cleared');
    result(count_where('stock_purchases', fn ($q) => $q->where('invoice_image_disk', 'local')) === 5, 'private labels untouched by the reconcile');

    try {
        DB::table('stock_purchases')->insert(['shop_id' => $sid1, 'purchase_number' => 'AFTER-CONTRACT', 'purchase_date' => now()->toDateString(), 'status' => 'draft', 'invoice_image' => 'purchases/after.jpg', 'created_at' => now(), 'updated_at' => now()]);
        result(false, 'a baseline-shaped write is refused once the contract phase exists');
    } catch (Illuminate\Database\QueryException $e) {
        result(str_contains($e->getMessage(), 'stock_purchases_invoice_image_disk_check'), 'a baseline-shaped write is refused once the contract phase exists — hence code before contract');
    }

    // ─────────────────────────────────────────────────────────────────────
    section('E. New code on the fully migrated schema');

    [$owner1] = $tenants[0];
    $token = $owner1->createToken('rehearsal')->plainTextToken;
    $item = $fixture->item($sid1);
    $tag = mobile($sid1, 'GET', "/api/mobile/v1/items/{$item->id}", $token)->headers->get('ETag');
    $first = mobile($sid1, 'PATCH', "/api/mobile/v1/items/{$item->id}", $token, ['X-Idempotency-Key' => 'rehearsal-patch-1', 'If-Match' => $tag], ['selling_price' => 2500]);
    $replay = mobile($sid1, 'PATCH', "/api/mobile/v1/items/{$item->id}", $token, ['X-Idempotency-Key' => 'rehearsal-patch-1', 'If-Match' => $tag], ['selling_price' => 2500]);
    result($first->getStatusCode() === 200 && $replay->headers->get('X-Idempotent-Replay') === 'true'
        && $replay->headers->get('ETag') === $first->headers->get('ETag'), 'S3-09e: replay carries the original ETag');

    DB::table('signature_relocations')->insert(['shop_id' => $s3->id, 'path' => "signatures/{$s3->id}/old.png", 'source_disk' => 'public', 'target_disk' => 'local', 'sha256' => str_repeat('a', 64), 'bytes' => 10]);

    // ─────────────────────────────────────────────────────────────────────
    section('F. Rollback, newest first, on the same data');

    measured('down '.CONTRACT, sprintf('%.0f ms', down(CONTRACT)));
    result(DB::selectOne("select count(*) c from pg_constraint where conname like '%disk_check'")->c == 0, 'contract down: constraints gone');
    DB::table('stock_purchases')->insert(['shop_id' => $sid1, 'purchase_number' => 'AFTER-CONTRACT-DOWN', 'purchase_date' => now()->toDateString(), 'status' => 'draft', 'invoice_image' => 'purchases/after-down.jpg', 'created_at' => now(), 'updated_at' => now()]);
    result(true, 'contract down: baseline-shaped writes accepted again');
    $window['stock_purchases']++;

    measured('down '.NOTIFICATIONS, sprintf('%.0f ms', down(NOTIFICATIONS)));
    result(Schema::hasTable('notifications'), 'notifications down keeps the table: it may predate the release, and holds delivered notifications');

    // An expiry row makes the S3-16 column evidence: its down() must refuse.
    // Tried inside a transaction that is rolled back, because the row itself
    // can never be deleted (append-only trigger).
    DB::beginTransaction();
    $lot = (int) DB::table('loyalty_transactions')->where('type', 'earn')->value('id');
    $lotRow = DB::table('loyalty_transactions')->find($lot);
    DB::table('loyalty_transactions')->insert(['shop_id' => $lotRow->shop_id, 'customer_id' => $lotRow->customer_id, 'type' => 'redeem', 'points' => 1,
        'description' => 'Points expired', 'balance_after' => 0, 'expires_lot_id' => $lot, 'expired' => DB::raw('false'), 'created_at' => now(), 'updated_at' => now()]);
    try {
        down(EXPIRY_LOT);
        $refused = false;
    } catch (Throwable $e) {
        $refused = str_contains($e->getMessage(), 'identifies recorded expiries');
    }
    DB::rollBack();
    result($refused && Schema::hasColumn('loyalty_transactions', 'expires_lot_id'), 'S3-16 column down refuses while an expiry row exists');
    measured('down '.EXPIRY_LOT, sprintf('%.0f ms', down(EXPIRY_LOT)));
    result(! Schema::hasColumn('loyalty_transactions', 'expires_lot_id') && $loyaltyGuard() === 1, 'with no expiry rows it drops; the protected trigger is untouched');
    measured('down '.OUTCOME, sprintf('%.0f ms', down(OUTCOME)));
    result(! Schema::hasColumn('report_exports', 'notified_at'), 'S3-17 columns dropped');

    measured('down '.HEADERS, sprintf('%.0f ms', down(HEADERS)));
    // New code keeps serving with the column really gone.
    $tag = mobile($sid1, 'GET', "/api/mobile/v1/items/{$item->id}", $token)->headers->get('ETag');
    $live = mobile($sid1, 'PATCH', "/api/mobile/v1/items/{$item->id}", $token, ['X-Idempotency-Key' => 'rehearsal-patch-2', 'If-Match' => $tag], ['selling_price' => 3100]);
    $again = mobile($sid1, 'PATCH', "/api/mobile/v1/items/{$item->id}", $token, ['X-Idempotency-Key' => 'rehearsal-patch-2', 'If-Match' => $tag], ['selling_price' => 3100]);
    $claimStatus = (int) IdempotencyKey::where('key', 'rehearsal-patch-2')->value('response_status');
    result($live->getStatusCode() === 200 && $again->headers->get('X-Idempotent-Replay') === 'true' && $again->headers->get('ETag') === null && $claimStatus === 200,
        'headers column dropped under new code: claim still resolves, replay loses only the headers', "claim status {$claimStatus}");

    measured('down '.CLAIMS, sprintf('%.0f ms', down(CLAIMS)));
    result(! Schema::hasTable('invoice_payment_claims'), 'claims down: table dropped (schema step only — see header)');

    $privateBefore = count_where('karigar_invoices', fn ($q) => $q->where('invoice_file_disk', 'local'))
        + count_where('stock_purchases', fn ($q) => $q->where('invoice_image_disk', 'local'))
        + count_where('shop_billing_settings', fn ($q) => $q->where('digital_signature_disk', 'local'));
    foreach (array_reverse(EXPAND) as $m) {
        measured("down {$m}", sprintf('%.0f ms', down($m)));
    }
    result(! Schema::hasColumn('stock_purchases', 'invoice_image_disk') && ! Schema::hasTable('signature_relocations'), 'expand down: columns and relocation ledger gone');

    // ─────────────────────────────────────────────────────────────────────
    section('G. Re-apply — and what the Phase-1 rollback cost');

    foreach (EXPAND as $m) {
        up($m);
    }
    $privateAfter = count_where('karigar_invoices', fn ($q) => $q->where('invoice_file_disk', 'local'))
        + count_where('stock_purchases', fn ($q) => $q->where('invoice_image_disk', 'local'))
        + count_where('shop_billing_settings', fn ($q) => $q->where('digital_signature_disk', 'local'));
    measured('private-disk labels', "before expand rollback: {$privateBefore}; after re-apply: {$privateAfter}");
    result($privateBefore === 11 && $privateAfter === 0,
        'Phase-1 rollback is ONE-WAY: every private file is relabelled public on re-apply, and the relocation ledger is empty',
        'relocations: '.DB::table('signature_relocations')->count());

    up(CLAIMS);
    up(HEADERS);
    up(OUTCOME);
    up(EXPIRY_LOT);
    up(NOTIFICATIONS);   // skips creating: the table survived its down()
    up(CONTRACT);
    result(pending() === [], 'fully re-applied: nothing pending');

    $after = [
        'karigar_invoices' => DB::table('karigar_invoices')->count(),
        'stock_purchases' => DB::table('stock_purchases')->count(),
        'idempotency_keys' => DB::table('idempotency_keys')->count(),
        'shop_billing_settings' => DB::table('shop_billing_settings')->count(),
        'report_exports' => DB::table('report_exports')->count(),
        'loyalty_transactions' => DB::table('loyalty_transactions')->count(),
    ];
    result($after['karigar_invoices'] === $before['karigar_invoices'] + $window['karigar_invoices']
        && $after['stock_purchases'] === $before['stock_purchases'] + $window['stock_purchases']
        && $after['shop_billing_settings'] === $before['shop_billing_settings']
        && $after['report_exports'] === $before['report_exports'] + $appExports && $after['loyalty_transactions'] === $before['loyalty_transactions'],
        'no business row lost across every up and down', json_encode($after));
} catch (Throwable $e) {
    result(false, 'rehearsal aborted', get_class($e).': '.$e->getMessage());
} finally {
    foreach ($exportDirs as $dir) {
        Illuminate\Support\Facades\Storage::disk('local')->deleteDirectory($dir);
    }
    Illuminate\Support\Facades\Storage::disk('local')->delete('reporting-exports/customers-rehearsal-legacy.csv');
    foreach ($flatFiles as $file) {
        if ($file !== '') {
            Illuminate\Support\Facades\Storage::disk('local')->delete($file);
        }
    }
    foreach (glob(sys_get_temp_dir().'/jf-rehearsal-baseline-'.getmypid().'/*') ?: [] as $link) {
        unlink($link);
    }
    @rmdir(sys_get_temp_dir().'/jf-rehearsal-baseline-'.getmypid());
    Artisan::call('migrate:fresh', ['--force' => true]);
    echo "\n(jewelflow_testing reset with migrate:fresh)\n";
}

echo $failures === 0 ? "\nREHEARSAL: all checks passed\n" : "\nREHEARSAL: {$failures} check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
