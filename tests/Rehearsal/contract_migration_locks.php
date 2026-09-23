<?php

/**
 * XR-04 — the contract migration's ACTUAL lock boundaries, observed from
 * other connections while the real migrator runs.
 *
 *   php tests/Rehearsal/contract_migration_locks.php
 *
 * The migration runs as a child `php artisan migrate --path=… --force`,
 * exactly as a deploy would. This process watches it through separate
 * connections:
 *
 *   A. The migrator is held at the THIRD table (an observer keeps ACCESS SHARE
 *      on stock_purchases). Which locks does it still hold on the two tables it
 *      has finished, and can another session read and write them?
 *   B. The migrator is caught while VALIDATE scans a large karigar_invoices.
 *      Which lock does it hold there, and can another session read and write?
 *   C. The migrator is killed while held at the third table, then run again.
 *      What state did it leave, and does the re-run complete?
 *
 * Refuses any database not named jewelflow_testing; ends with migrate:fresh.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}

const CONTRACT = 'database/migrations/2026_09_20_130000_add_disk_column_check_constraints.php';
const CONSTRAINTS = [
    'shop_billing_settings' => 'shop_billing_settings_digital_signature_disk_check',
    'karigar_invoices' => 'karigar_invoices_attachment_disk_check',
    'stock_purchases' => 'stock_purchases_invoice_image_disk_check',
];

final class LockFixture
{
    use CreatesTestTenant;

    public function build(): array { return $this->createRetailerTenant(); }
}

function pdo(): PDO
{
    $c = config('database.connections.pgsql');
    $pdo = new PDO("pgsql:host={$c['host']};port={$c['port']};dbname={$c['database']}", $c['username'], $c['password']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function start_migrator(): array
{
    $proc = proc_open(['php', 'artisan', 'migrate', '--path='.CONTRACT, '--force'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
    usleep(300_000);

    return [$proc, $pipes];
}

function migrator_pid(PDO $watch): ?int
{
    $row = $watch->query("select pid from pg_stat_activity where query ilike 'alter table%' and pid <> pg_backend_pid() and state <> 'idle' order by backend_start desc limit 1")->fetch();

    return $row ? (int) $row['pid'] : null;
}

/** Relations the migrator holds a GRANTED lock on, with the mode. */
function held_locks(PDO $watch, int $pid): array
{
    $rows = $watch->query("select c.relname, l.mode from pg_locks l join pg_class c on c.oid = l.relation
        where l.pid = {$pid} and l.granted and c.relname in ('shop_billing_settings','karigar_invoices','stock_purchases')
        order by 1, 2")->fetchAll();

    return array_map(fn ($r) => "{$r['relname']}:{$r['mode']}", $rows);
}

/** Can a separate session read and write $table within one second? */
function probe(string $table, ?PDO $p = null): string
{
    $p ??= pdo();
    $p->exec("set lock_timeout = '1s'");
    $out = [];
    foreach (['read' => "select count(*) from {$table}", 'write' => "update {$table} set updated_at = updated_at where id = (select min(id) from {$table})"] as $kind => $sql) {
        try {
            $p->exec($sql);
            $out[] = "{$kind}=ok";
        } catch (PDOException $e) {
            $out[] = "{$kind}=BLOCKED";
        }
    }

    return implode(' ', $out);
}

function constraint_state(): array
{
    $state = [];
    foreach (CONSTRAINTS as $table => $name) {
        $row = DB::selectOne('select convalidated from pg_constraint where conname = ?', [$name]);
        $state[] = $table.'='.($row === null ? 'absent' : ($row->convalidated ? 'validated' : 'NOT VALID'));
    }

    return $state;
}

function contract_recorded(): bool
{
    return DB::table('migrations')->where('migration', basename(CONTRACT, '.php'))->exists();
}

function roll_back_contract(): void
{
    if (contract_recorded()) {
        Artisan::call('migrate:rollback', ['--path' => CONTRACT, '--force' => true]);
    }
    foreach (CONSTRAINTS as $table => $name) {
        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
    }
}

$failures = 0;
function verdict(bool $ok, string $label): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    echo ($ok ? 'PASS  ' : 'FAIL  '), $label, "\n";
}

try {
    Artisan::call('migrate:fresh', ['--force' => true]);
    roll_back_contract();

    [$owner, $shop] = (new LockFixture)->build();
    $karigar = DB::table('karigars')->insertGetId(['shop_id' => $shop->id, 'name' => 'Lock Karigar', 'created_at' => now(), 'updated_at' => now()]);
    DB::statement("insert into karigar_invoices (shop_id, karigar_id, karigar_invoice_number, karigar_invoice_date, invoice_file_path, invoice_file_disk, payment_status, created_at, updated_at)
        select ?, ?, 'LK-' || g, current_date, 'karigar-invoices/x/' || g || '.pdf', 'public', 'unpaid', now(), now() from generate_series(1, 3000000) g", [$shop->id, $karigar]);
    DB::statement("insert into stock_purchases (shop_id, purchase_number, purchase_date, status, created_at, updated_at)
        select ?, 'LP-' || g, current_date, 'draft', now(), now() from generate_series(1, 1000) g", [$shop->id]);
    DB::statement('analyze karigar_invoices');
    echo "populated: karigar_invoices ", DB::table('karigar_invoices')->count(), ", stock_purchases ", DB::table('stock_purchases')->count(), "\n";

    // ── A. held at the third table ────────────────────────────────────────
    echo "\n== A. migrator held at stock_purchases (third table) ==\n";
    $observer = pdo();
    $observer->beginTransaction();
    $observer->exec('LOCK TABLE stock_purchases IN ACCESS SHARE MODE');
    $watch = pdo();
    [$proc, $pipes] = start_migrator();

    $pid = null;
    for ($i = 0; $i < 600 && $pid === null; $i++) {
        usleep(50_000);
        $waiting = $watch->query("select l.pid from pg_locks l join pg_class c on c.oid = l.relation where c.relname = 'stock_purchases' and not l.granted limit 1")->fetch();
        $pid = $waiting ? (int) $waiting['pid'] : null;
    }
    $held = $pid ? held_locks($watch, $pid) : [];
    echo 'migrator waiting: ', $pid ? 'yes' : 'NO', '; still holds: ', $held === [] ? '(nothing on the finished tables)' : implode(', ', $held), "\n";
    echo 'shop_billing_settings: ', probe('shop_billing_settings'), "\n";
    echo 'karigar_invoices:      ', probe('karigar_invoices'), "\n";
    verdict($pid !== null, 'the migrator reached the third table');
    verdict(! array_filter($held, fn ($h) => ! str_starts_with($h, 'stock_purchases')), 'no lock is held on a table whose step is finished');

    $observer->commit();
    stream_get_contents($pipes[1]);
    $exit = proc_close($proc);
    echo 'migrator exit ', $exit, '; constraints: ', implode(', ', constraint_state()), "\n";

    // ── B. caught inside VALIDATE on a large table ─────────────────────────
    echo "\n== B. migrator caught while VALIDATE scans karigar_invoices ==\n";
    roll_back_contract();
    $prober = pdo(); // opened in advance, so the probe itself costs no connect time
    [$proc, $pipes] = start_migrator();
    $caught = null;
    $stillValidating = false;
    for ($i = 0; $i < 20000 && $caught === null; $i++) {
        $row = $watch->query("select pid from pg_stat_activity where state = 'active' and query ilike 'alter table karigar_invoices validate%'")->fetch();
        if ($row) {
            $caught = (int) $row['pid'];
            $modes = held_locks($watch, $caught);
            $probed = probe('karigar_invoices', $prober);
            // Only a probe that finished while VALIDATE was still running counts.
            $stillValidating = (bool) $watch->query("select 1 from pg_stat_activity where pid = {$caught} and state = 'active' and query ilike 'alter table karigar_invoices validate%'")->fetch();
        }
        usleep(1_000);
    }
    stream_get_contents($pipes[1]);
    proc_close($proc);
    if ($caught === null) {
        echo "VALIDATE finished before it could be observed — not measured\n";
    } else {
        echo 'locks during VALIDATE: ', implode(', ', $modes), "\n";
        echo "karigar_invoices during VALIDATE: {$probed}; VALIDATE still running after the probe: ", $stillValidating ? 'yes' : 'no', "\n";
        verdict(! in_array('karigar_invoices:AccessExclusiveLock', $modes, true), 'VALIDATE runs without ACCESS EXCLUSIVE on its table');
        verdict($stillValidating && $probed === 'read=ok write=ok', 'reads and writes continue during VALIDATE (probe completed while it ran)');
    }

    // ── C. killed mid-run, then re-run ─────────────────────────────────────
    echo "\n== C. migrator killed while held at the third table, then re-run ==\n";
    roll_back_contract();
    $observer = pdo();
    $observer->beginTransaction();
    $observer->exec('LOCK TABLE stock_purchases IN ACCESS SHARE MODE');
    [$proc, $pipes] = start_migrator();
    $pid = null;
    for ($i = 0; $i < 600 && $pid === null; $i++) {
        usleep(50_000);
        $waiting = $watch->query("select l.pid from pg_locks l join pg_class c on c.oid = l.relation where c.relname = 'stock_purchases' and not l.granted limit 1")->fetch();
        $pid = $waiting ? (int) $waiting['pid'] : null;
    }
    $status = proc_get_status($proc);
    posix_kill($status['pid'], SIGKILL);
    proc_close($proc);
    $watch->query("select pg_terminate_backend({$pid})");
    $observer->commit();
    usleep(500_000);
    echo 'after kill: ', implode(', ', constraint_state()), '; recorded: ', contract_recorded() ? 'yes' : 'no', "\n";

    $rerun = Artisan::call('migrate', ['--path' => CONTRACT, '--force' => true]);
    echo 're-run exit ', $rerun, ': ', implode(', ', constraint_state()), '; recorded: ', contract_recorded() ? 'yes' : 'no', "\n";
    verdict($rerun === 0 && constraint_state() === ['shop_billing_settings=validated', 'karigar_invoices=validated', 'stock_purchases=validated'] && contract_recorded(),
        'a re-run after an interrupted run completes and validates all three');
} catch (Throwable $e) {
    verdict(false, 'harness aborted: '.get_class($e).': '.$e->getMessage());
} finally {
    Artisan::call('migrate:fresh', ['--force' => true]);
    echo "\n(jewelflow_testing reset with migrate:fresh)\n";
}

echo $failures === 0 ? "RESULT: all checks passed\n" : "RESULT: {$failures} check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
