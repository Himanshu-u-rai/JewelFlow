<?php

/**
 * S3-21 — the first platform super admin, registered twice at once.
 *
 *   php tests/Concurrency/admin_bootstrap_race.php [--break=worker-exit]
 *
 * POST /admin/register creates the first super admin only while none exists,
 * checking inside a transaction and then creating one. Each scenario starts
 * from an empty platform_admins table (migrate:fresh — this harness refuses
 * any database but jewelflow_testing) and runs every registration in its own
 * process, through the HTTP kernel, with a distinct valid mobile number.
 *
 * 1. Deterministic. Process A passes the check and is held inside its
 *    transaction just before its insert. Process B then registers. This
 *    process waits until B has either finished or is seen waiting on a lock
 *    held by A's backend, then releases A.
 * 2. A real race: four registrations started together.
 * 3. Configured: a later registration is refused, and GET /admin/register
 *    redirects to the login page.
 *
 * SAFE requires the premises too, and prints each one that fails: every
 * child exits 0 and reports its backend and its answer; every answer is
 * `registered` (redirect to the dashboard) or `refused` (redirect to the
 * login page with "Super admin is already configured."); exactly one super
 * admin exists afterwards; in 1, B waited on a lock held by A's backend.
 *
 * --break=worker-exit makes every child exit 1 before registering, to show
 * that the verdict fails when the work does not happen.
 *
 * Writes committed rows to jewelflow_testing and leaves it migrated fresh.
 */

use App\Models\Platform\PlatformAdmin;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(HttpKernel::class)->bootstrap();

if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}
app()->instance('request', Request::create((string) config('app.url')));   // for url(), as the console kernel binds

$breakArg = current(array_filter($argv, fn ($a) => str_starts_with($a, '--break=')));
$break = $breakArg ? substr($breakArg, 8) : (string) getenv('HARNESS_BREAK');
if (! in_array($break, ['', 'worker-exit'], true)) {
    fwrite(STDERR, "unknown --break={$break}\n");
    exit(2);
}

/** One request through the HTTP kernel; returns [status, location path, error messages]. */
function send(string $method, string $uri, array $data = []): array
{
    Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['admin/register']);   // harness only: no browser session
    $request = Request::create(url($uri), $method, $data, [], [], ['HTTP_ACCEPT' => 'text/html']);
    app()->instance('request', $request);
    $response = app(HttpKernel::class)->handle($request);
    $errors = session('errors')?->all() ?? [];

    return [$response->getStatusCode(), (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH), implode('; ', $errors),
        $response->exception ? get_class($response->exception).': '.$response->exception->getMessage() : ''];
}

/** The application's answer to a registration: `registered`, `refused`, or anything else in full. */
function outcome(array $answer): string
{
    [$status, $location, $errors, $exception] = $answer;

    return match (true) {
        $status === 302 && $location === '/admin' => 'registered',
        $status === 302 && $location === '/admin/login' && str_contains($errors, 'Super admin is already configured.') => 'refused',
        default => "other: {$status} {$location} {$errors} {$exception}",
    };
}

// ── child: one registration; prints its backend pid, then its answer ──
if (($argv[1] ?? null) === 'register') {
    [, , $mobile] = $argv;
    $hold = $argv[3] ?? null;
    $go = $argv[4] ?? null;
    if ($break === 'worker-exit') {
        fwrite(STDERR, "harness: worker failing on purpose\n");
        exit(1);
    }
    echo 'pid '.DB::selectOne('select pg_backend_pid() as p')->p."\n";
    if ($hold !== null) {
        // Held after the bootstrap check, before the insert, inside the transaction.
        PlatformAdmin::creating(function () use ($hold, $go): void {
            touch($hold);
            for ($i = 0; $i < 600 && ! file_exists($go); $i++) {
                usleep(50000);
            }
        });
    }
    echo json_encode(outcome(send('POST', '/admin/register', [
        'first_name' => 'Boot', 'last_name' => substr($mobile, -4), 'mobile_number' => $mobile,
        'password' => 'Harness-Pass-1', 'password_confirmation' => 'Harness-Pass-1',
    ]))), "\n";
    exit(0);
}

/** Prints each premise that does not hold; true when all do. */
function check(array $premises): bool
{
    $failed = array_keys(array_filter($premises, fn ($holds) => ! $holds));
    foreach ($failed as $premise) {
        echo "  FAILED: {$premise}\n";
    }

    return $failed === [];
}

function spawn(array $args): array
{
    $proc = proc_open(array_merge(['php', __FILE__, 'register'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(),
        ['HARNESS_BREAK' => $GLOBALS['break']] + getenv());
    $pid = preg_match('/^pid (\d+)/', (string) fgets($pipes[1]), $m) ? (int) $m[1] : null;

    return [$proc, $pipes, $pid];
}

/** [exit code, answer or null, stderr] once the child has finished. */
function finish(array $child): array
{
    [$proc, $pipes] = $child;
    $out = trim(stream_get_contents($pipes[1]));
    $err = trim(stream_get_contents($pipes[2]));
    $code = proc_close($proc);
    $lines = explode("\n", $out);
    $answer = json_decode((string) end($lines), true);

    return [$code, is_string($answer) ? $answer : null, $err];
}

function superAdmins(): int
{
    return PlatformAdmin::where('role', 'super_admin')->count();
}

function fresh(): void
{
    ob_start();   // some migrations echo forensic counts
    Artisan::call('migrate:fresh', ['--force' => true]);
    ob_end_clean();
}

if ($break !== '') {
    echo "── BREAK: {$break} — this run must end UNSAFE ──\n";
}

// ── 1. deterministic interleaving ────────────────────────────────────────
echo "── 1. A passes the check and is held before its insert; B registers meanwhile ──\n";
fresh();
$monitor = new PDO('pgsql:host='.config('database.connections.pgsql.host').';port='.config('database.connections.pgsql.port').';dbname=jewelflow_testing',
    config('database.connections.pgsql.username'), config('database.connections.pgsql.password'));
$blocked = $monitor->prepare("select coalesce(bool_or(wait_event_type = 'Lock' and ?::int = any(pg_blocking_pids(pid))), false) from pg_stat_activity where pid = ?");
$dir = sys_get_temp_dir().'/jf-bootstrap-'.getmypid();
@mkdir($dir);
[$holdFile, $goFile] = ["{$dir}/held", "{$dir}/go"];
@unlink($holdFile);
@unlink($goFile);
$a = spawn(['9876500001', $holdFile, $goFile]);
for ($i = 0; $i < 600 && $a[2] !== null && ! file_exists($holdFile); $i++) {
    usleep(50000);
}
$aHeld = file_exists($holdFile);
$b = spawn(['9876500002']);
$bFinishedWhileAHeld = $bWaitedOnA = false;
for ($i = 0; $aHeld && $b[2] !== null && $i < 300; $i++) {
    if (! proc_get_status($b[0])['running']) {
        $bFinishedWhileAHeld = true;
        break;
    }
    $blocked->execute([$a[2], $b[2]]);
    if ($bWaitedOnA = (bool) $blocked->fetchColumn()) {
        break;
    }
    usleep(50000);
}
touch($goFile);
[$aCode, $aAnswer, $aErr] = finish($a);
[$bCode, $bAnswer, $bErr] = finish($b);
@unlink($holdFile);
@unlink($goFile);
@rmdir($dir);
$count1 = superAdmins();
echo '  A: backend '.($a[2] ?? 'NOT REPORTED').", held before its insert: ".($aHeld ? 'yes' : 'NO')."; exit {$aCode}: ".json_encode($aAnswer).($aCode !== 0 ? " — {$aErr}" : '')."\n";
echo '  B: backend '.($b[2] ?? 'NOT REPORTED').'; '.($bWaitedOnA ? 'waited on a lock held by A' : ($bFinishedWhileAHeld ? 'FINISHED while A was held' : 'neither finished nor waited on A'))
    ."; exit {$bCode}: ".json_encode($bAnswer).($bCode !== 0 ? " — {$bErr}" : '')."\n";
echo "  super admins: {$count1}\n";
$safe1 = check([
    'both children exited 0 and reported their backend and answer' => $aCode === 0 && $bCode === 0 && $a[2] !== null && $b[2] !== null && $aAnswer !== null && $bAnswer !== null,
    'A was held after the check, inside its transaction' => $aHeld,
    'B waited on a lock held by A\'s backend (did not pass the check meanwhile)' => $bWaitedOnA && ! $bFinishedWhileAHeld,
    'A registered, B was refused' => $aAnswer === 'registered' && $bAnswer === 'refused',
    'exactly one super admin' => $count1 === 1,
]);
echo $safe1 ? "  SAFE\n" : "  UNSAFE\n";

// ── 2. a real race ───────────────────────────────────────────────────────
echo "\n── 2. four registrations started together ──\n";
fresh();
$children = [];
foreach (['9876500011', '9876500012', '9876500013', '9876500014'] as $mobile) {
    $children[] = spawn([$mobile]);
}
$answers = [];
$childrenOk = true;
foreach ($children as $n => $child) {
    [$code, $answer, $err] = finish($child);
    $childrenOk = $childrenOk && $code === 0 && $child[2] !== null && $answer !== null;
    $answers[] = $answer ?? 'none';
    echo '  child '.($n + 1)." exit {$code}: ".json_encode($answer).($code !== 0 ? " — {$err}" : '')."\n";
}
$counts = array_count_values($answers);
$count2 = superAdmins();
echo "  super admins: {$count2}\n";
$safe2 = check([
    'every child exited 0 and reported its backend and answer' => $childrenOk,
    'every answer is registered or refused' => array_diff(array_keys($counts), ['registered', 'refused']) === [],
    'exactly one registered, three refused' => ($counts['registered'] ?? 0) === 1 && ($counts['refused'] ?? 0) === 3,
    'exactly one super admin' => $count2 === 1,
]);
echo $safe2 ? "  SAFE\n" : "  UNSAFE\n";

// ── 3. configured ────────────────────────────────────────────────────────
echo "\n── 3. once configured ──\n";
if (superAdmins() === 0) {
    PlatformAdmin::create(['first_name' => 'Boot', 'last_name' => 'Seed', 'name' => 'Boot Seed', 'mobile_number' => '9876500020',
        'password' => bcrypt('Harness-Pass-1'), 'role' => 'super_admin', 'is_active' => true, 'password_changed_at' => now()]);
}
$late = spawn(['9876500021']);
[$lateCode, $lateAnswer, $lateErr] = finish($late);
[$getStatus, $getLocation, , $getException] = send('GET', '/admin/register');
$count3 = superAdmins();
echo "  a later registration: exit {$lateCode}: ".json_encode($lateAnswer).($lateCode !== 0 ? " — {$lateErr}" : '')."\n";
echo "  GET /admin/register: {$getStatus} {$getLocation}".($getException !== '' ? " — {$getException}" : '')."\n";
echo "  super admins: {$count3}\n";
$safe3 = check([
    'the later registration exited 0 and was refused' => $lateCode === 0 && $lateAnswer === 'refused',
    'GET /admin/register redirects to the login page' => $getStatus === 302 && $getLocation === '/admin/login',
    'still exactly one super admin' => $count3 === 1,
]);
echo $safe3 ? "  SAFE\n" : "  UNSAFE\n";

fresh();
echo "\n(jewelflow_testing reset with migrate:fresh)\n";
echo ($safe1 && $safe2 && $safe3) ? "RESULT: SAFE\n" : 'RESULT: UNSAFE — scenario'.($safe1 ? '' : ' 1').($safe2 ? '' : ' 2').($safe3 ? '' : ' 3')."\n";
exit(($safe1 && $safe2 && $safe3) ? 0 : 1);
