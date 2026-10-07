<?php

// APP_ENV=testing php tests/Concurrency/product_promotion_race.php
// Committed fixtures and separate PHP/PostgreSQL connections, never RefreshDatabase.
use App\Models\User;
use App\Services\ProductPromotionService;
use App\Support\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestDatabaseGuard;

function introductionResultsAreValid(array $results): bool
{
    $values = array_column($results, 'value');
    sort($values);

    return count($results) === 2 && array_column($results, 'status') === [200, 200]
        && $values === [false, true];
}

if (($argv[1] ?? '') === 'assertion-check') {
    if (! introductionResultsAreValid([['status' => 200, 'value' => true], ['status' => 200, 'value' => false]])
        || ! introductionResultsAreValid([['status' => 200, 'value' => false], ['status' => 200, 'value' => true]])
        || introductionResultsAreValid([['status' => 200, 'value' => true], ['status' => 500]])
        || introductionResultsAreValid([['status' => 200, 'value' => true], ['status' => 200, 'value' => true]])) {
        throw new RuntimeException('Introduction race assertion accepted a failed worker or invalid winner count.');
    }
    echo "PASS: introduction assertion requires two successful workers and exactly one boolean winner\n";
    exit;
}

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->afterBootstrapping(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
    TestDatabaseGuard::enforce($app);
    $app['config']->set('cache.default', 'array');
});
$app->make(Kernel::class)->bootstrap();
TestDatabaseGuard::enforce($app);
set_exception_handler(function (\Throwable $e): void {
    fwrite(STDERR, $e::class.': '.$e->getMessage()."\n");
    exit(1);
});
DB::select("select set_config('statement_timeout', '15000', false)");
if (DB::selectOne('select current_database() as name')->name !== TestDatabaseGuard::EXPECTED_DATABASE) {
    throw new RuntimeException('Refusing writes outside jewelflow_testing.');
}
config(['hashing.bcrypt.rounds' => 4, 'platform.cross_promotion.enabled' => true,
    'platform.cross_promotion.dhiran_register_url' => 'https://dhiran.example.test/register',
    'platform.cross_promotion.erp_register_url' => 'https://example.test/register']);
$service = app(ProductPromotionService::class);

if (($argv[1] ?? '') === 'worker') {
    [$action, $userId, $argument] = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    echo DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
    flush();
    if (trim(readWorker(STDIN, true)) !== 'go') {
        throw new RuntimeException('Worker was not released.');
    }
    try {
        $user = User::findOrFail($userId);
        TenantContext::set($user->shop_id);
        if ($action === 'claim') {
            $request = Request::create('https://example.test/dashboard');
            $request->setUserResolver(fn () => $user);
            $value = $service->claimIntroduction($request) !== null;
        } else {
            $value = match ($action) {
                'approve' => $service->approve($user, 'password', $argument),
                'finish' => $service->finish($user, 'password', $argument),
                'cancel' => $service->cancel($user, $argument),
                'revoke' => $service->revoke($user, $argument),
                'invalidate' => DB::transaction(fn () => $user->forceFill(['password' => Hash::make('changed-password')])->save()),
            };
        }
        echo json_encode(['status' => 200, 'value' => $value])."\n";
    } catch (\Illuminate\Validation\ValidationException $e) {
        echo json_encode(['status' => 422])."\n";
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        echo json_encode(['status' => $e->getStatusCode()])."\n";
    } catch (\Throwable $e) {
        echo json_encode(['status' => 500, 'exception' => $e::class, 'code' => $e->getCode()])."\n";
    }
    exit;
}

$ownedWorkers = [];
register_shutdown_function('cleanupWorkers');

function cleanupWorkers(): void
{
    $reaped = true;
    foreach ($GLOBALS['ownedWorkers'] as $key => [$process, $pipes]) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($process)) {
            foreach ([15, 9] as $signal) {
                if (! proc_get_status($process)['running']) {
                    break;
                }
                @proc_terminate($process, $signal);
                $deadline = microtime(true) + 1;
                do {
                    usleep(10000);
                } while (proc_get_status($process)['running'] && microtime(true) < $deadline);
            }
            if (proc_get_status($process)['running']) {
                fwrite(STDERR, "Owned worker did not exit during bounded cleanup.\n");
                $reaped = false;
            } else {
                proc_close($process);
            }
        }
        unset($GLOBALS['ownedWorkers'][$key]);
    }
    if (! $reaped) {
        exit(1);
    }
}

/** Pipes need select deadlines; stream timeouts alone do not bound pipe reads. */
function readWorker($pipe, bool $line = false): string
{
    if (! is_resource($pipe) || ! stream_set_blocking($pipe, false)) {
        throw new RuntimeException('Invalid worker pipe.');
    }
    $output = '';
    $deadline = microtime(true) + 20;
    while (! feof($pipe)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Worker stream timed out.');
        }
        $read = [$pipe];
        $write = $except = [];
        $ready = stream_select($read, $write, $except, 1);
        if ($ready === false) {
            throw new RuntimeException('Worker stream select failed.');
        }
        if ($ready === 0) {
            continue;
        }
        $chunk = $line ? fgets($pipe) : fread($pipe, 8192);
        if ($chunk === false || stream_get_meta_data($pipe)['timed_out']) {
            throw new RuntimeException('Worker stream read failed or timed out.');
        }
        $output .= $chunk;
        if ($line && str_ends_with($output, "\n")) {
            return $output;
        }
    }
    if ($line) {
        throw new RuntimeException('Worker stream ended before a complete line.');
    }

    return $output;
}

final class PromotionRaceFixture
{
    use CreatesTestTenant;

    public function owner(string $realm = 'erp'): User
    {
        $shop = $this->createShop($realm === 'erp' ? 'retailer' : 'dhiran');
        $user = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));
        $user->forceFill(['realm' => $realm, 'password' => Hash::make('password')])->save();

        return $user->fresh();
    }
}

function check(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException('FAIL: '.$label);
    }
    echo 'PASS: '.$label."\n";
}

/** Parent holds the contested rows until BOTH workers are observed waiting on locks. */
function race(array $jobs, callable $lock): array
{
    $processes = $pipes = $pids = [];
    try {
        foreach ($jobs as $i => $job) {
            $processes[$i] = proc_open([PHP_BINARY, __FILE__, 'worker', json_encode($job, JSON_THROW_ON_ERROR)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i], base_path());
            if (! is_resource($processes[$i])) {
                throw new RuntimeException('Worker startup failed.');
            }
            $GLOBALS['ownedWorkers'][get_resource_id($processes[$i])] = [$processes[$i], $pipes[$i]];
            foreach ([0, 1, 2] as $descriptor) {
                if (! isset($pipes[$i][$descriptor]) || ! is_resource($pipes[$i][$descriptor])) {
                    throw new RuntimeException('Worker startup pipe missing.');
                }
            }
            $pid = filter_var(trim(readWorker($pipes[$i][1], true)), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($pid === false) {
                throw new RuntimeException('Worker returned an invalid PostgreSQL PID.');
            }
            $pids[] = $pid;
        }
        $started = microtime(true);
        DB::beginTransaction();
        try {
            $lock();
            foreach ($pipes as $pipe) {
                if (fwrite($pipe[0], "go\n") !== 3) {
                    throw new RuntimeException('Worker release failed.');
                }
                fclose($pipe[0]);
            }
            do {
                $waiting = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($waiting === count($jobs)) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) - $started < 10);
            check($waiting === count($jobs), 'both workers observed concurrently waiting in PostgreSQL');
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $results = [];
        foreach ($processes as $i => $process) {
            $output = readWorker($pipes[$i][1]);
            $error = readWorker($pipes[$i][2]);
            foreach ($pipes[$i] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            $deadline = microtime(true) + 20;
            do {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            if ($status['running']) {
                throw new RuntimeException('Worker exit timed out.');
            }
            $key = get_resource_id($process);
            $exit = proc_close($process);
            unset($GLOBALS['ownedWorkers'][$key]);
            check(($status['exitcode'] >= 0 ? $status['exitcode'] : $exit) === 0, 'worker exited cleanly: '.$error);
            $results[] = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
        }
        echo json_encode(['duration_ms' => round((microtime(true) - $started) * 1000), 'results' => $results])."\n";

        return $results;
    } finally {
        cleanupWorkers();
    }
}

$fixture = new PromotionRaceFixture;
$source = $fixture->owner();
$target = $fixture->owner('dhiran');
$rival = $fixture->owner('dhiran');
$preference = $service->preference($source);
$results = race([['claim', $source->id, null], ['claim', $source->id, null]],
    fn () => DB::table('product_promotion_preferences')->where('id', $preference->id)->lockForUpdate()->first());
check(introductionResultsAreValid($results)
    && DB::table('product_promotion_exposures')->where('preference_id', $preference->id)->count() === 1, 'exactly one introduction');

$code = $service->start($source, 'password');
$id = $service->requests($source)->whereNull('consumed_at')->value('id');
$lock = fn () => DB::table('product_recognition_requests')->where('id', $id)->lockForUpdate()->first();
$results = race([['approve', $target->id, $code], ['approve', $rival->id, $code]], $lock);
$statuses = array_column($results, 'status');
sort($statuses);
check($statuses === [200, 422], 'one code accepts exactly one target');
$target = User::findOrFail(DB::table('product_recognition_requests')->where('id', $id)->value('target_user_id'));
$reverseCode = $service->start($target, 'password');
$reverseId = $service->requests($target)->whereNull('consumed_at')->value('id');
$service->approve($source, 'password', $reverseCode);
$results = race([['finish', $source->id, $id], ['finish', $target->id, $reverseId]],
    fn () => DB::table('product_recognition_requests')->whereIn('id', [$id, $reverseId])->orderBy('id')->lockForUpdate()->get());
$pair = fn () => DB::table('product_recognitions')->where('erp_user_id', $source->id)->where('dhiran_user_id', $target->id);
check(array_column($results, 'status') === [200, 200] && $pair()->count() === 1, 'opposite directions converge to one pair');
$link = $pair()->value('id');

$code = $service->start($source, 'password');
$id = $service->requests($source)->whereNull('consumed_at')->value('id');
$service->approve($target, 'password', $code);
$lock = fn () => DB::table('product_recognition_requests')->where('id', $id)->lockForUpdate()->first();
$results = race([['finish', $source->id, $id], ['revoke', $target->id, $link]], $lock);
check(in_array($results[0]['status'], [200, 409], true) && $results[1]['status'] === 200
    && $pair()->whereNull('revoked_at')->count() === 0, 'revocation wins over outstanding finalization');

$code = $service->start($source, 'password');
$id = $service->requests($source)->whereNull('consumed_at')->value('id');
$service->approve($target, 'password', $code);
$lock = fn () => DB::table('product_recognition_requests')->where('id', $id)->lockForUpdate()->first();
$results = race([['finish', $source->id, $id], ['cancel', $target->id, $id]], $lock);
check(($results[0]['status'] === 200 && $results[1]['status'] === 404)
    || ($results[0]['status'] === 409 && $results[1]['status'] === 200), 'cancel and finish have exactly one winner');
check($pair()->whereNull('revoked_at')->count() === ($results[0]['status'] === 200 ? 1 : 0), 'cancel winner cannot leave a live recognition');
$code = $service->start($source, 'password');
$id = $service->requests($source)->whereNull('consumed_at')->value('id');
$service->approve($target, 'password', $code);
$service->finish($source, 'password', $id);
$code = $service->start($source, 'password');
$id = $service->requests($source)->whereNull('consumed_at')->value('id');
$service->approve($target, 'password', $code);
$lock = fn () => DB::table('product_recognition_requests')->where('id', $id)->lockForUpdate()->first();
$deadlocks = fn () => (int) DB::selectOne('select deadlocks from pg_stat_database where datname = current_database()')->deadlocks;
$before = $deadlocks();
$results = race([['revoke', $source->id, $link], ['invalidate', $target->id, null]], $lock);
check(in_array($results[0]['status'], [200, 404], true) && $results[1]['status'] === 200
    && $pair()->whereNull('revoked_at')->count() === 0 && $deadlocks() === $before,
    'security invalidation and revocation use consistent lock order without deadlocks');
echo "PASS: all six real-worker promotion races\n";
