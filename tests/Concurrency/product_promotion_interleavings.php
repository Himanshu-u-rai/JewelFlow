<?php

// APP_ENV=testing php8.2 tests/Concurrency/product_promotion_interleavings.php
// Real transaction pauses expose in-flight proofs, approvals and expiry waits.
use App\Models\Role;
use App\Models\User;
use App\Services\ProductPromotionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestDatabaseGuard;

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
config(['hashing.bcrypt.rounds' => 4]);
$service = app(ProductPromotionService::class);

if (($argv[1] ?? '') === 'worker') {
    [$action, $userId, $argument, $applicationName] = json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR);
    DB::select("select set_config('application_name', ?, false)", [$applicationName]);
    echo DB::selectOne('select pg_backend_pid() as pid')->pid."\n";
    flush();
    if (trim(readWorker(STDIN, true)) !== 'go') {
        throw new RuntimeException('Worker was not released.');
    }
    $pause = function (): void {
        echo "held\n";
        flush();
        if (trim(readWorker(STDIN, true)) !== 'release') {
            throw new RuntimeException('Paused worker was not released.');
        }
    };
    if ($action === 'hold-start') {
        DB::connection()->beforeExecuting(function ($sql) use ($pause): void {
            if (str_starts_with($sql, 'insert into "product_recognition_requests"')) {
                $pause();
            }
        });
    } elseif ($action === 'hold-approve') {
        DB::listen(function ($query) use ($pause): void {
            if (str_starts_with($query->sql, 'update "product_recognition_requests"') && str_contains($query->sql, 'target_user_id')) {
                $pause();
            }
        });
    } elseif ($action === 'hold-finish') {
        DB::listen(function ($query) use ($pause): void {
            if (str_starts_with($query->sql, 'insert into "product_recognitions"') && str_contains($query->sql, ' on conflict ')) {
                if ($query->connection->transactionLevel() !== 1 || ! $query->connection->getPdo()->inTransaction()) {
                    throw new RuntimeException('Recognition upsert was not inside an uncommitted transaction.');
                }
                $pause();
            }
        });
    }
    try {
        $user = User::findOrFail($userId);
        if ($action === 'cycle') {
            $role = Role::withoutTenant()->findOrFail($user->role_id);
            $role->forceFill(['name' => 'staff'])->save();
            $role->forceFill(['name' => 'owner'])->save();
            $value = true;
        } else {
            $value = match ($action) {
                'hold-start' => $service->start($user, 'password'),
                'approve', 'hold-approve' => $service->approve($user, 'password', $argument),
                'finish', 'hold-finish' => $service->finish($user, 'password', $argument),
                'revoke' => $service->revoke($user, $argument),
                'cancel' => $service->cancel($user, $argument),
            };
        }
        echo json_encode(['status' => 200, 'value' => $value])."\n";
    } catch (\Illuminate\Validation\ValidationException $e) {
        echo "{\"status\":422}\n";
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

$fixture = new class
{
    use CreatesTestTenant;

    public function owner(string $realm): User
    {
        $shop = $this->createShop($realm === 'erp' ? 'retailer' : 'dhiran');
        $user = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));
        $user->forceFill(['realm' => $realm, 'password' => Hash::make('password')])->save();

        return $user->fresh();
    }
};
function spawn(array $job): array
{
    $applicationName = 'product-promotion-'.bin2hex(random_bytes(12));
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', json_encode([...$job, $applicationName], JSON_THROW_ON_ERROR)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
    if (! is_resource($process)) {
        throw new RuntimeException('Worker startup failed.');
    }
    $GLOBALS['ownedWorkers'][get_resource_id($process)] = [$process, $pipes];
    foreach ([0, 1, 2] as $descriptor) {
        if (! isset($pipes[$descriptor]) || ! is_resource($pipes[$descriptor])) {
            throw new RuntimeException('Worker startup pipe missing.');
        }
    }
    $pid = filter_var(trim(readWorker($pipes[1], true)), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($pid === false) {
        throw new RuntimeException('Worker returned an invalid PostgreSQL PID.');
    }

    return [$process, $pipes, $pid, $applicationName];
}
function finish(array $worker): array
{
    [$process, $pipes] = $worker;
    fclose($pipes[0]);
    $output = readWorker($pipes[1]);
    $error = readWorker($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
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
    if (($status['exitcode'] >= 0 ? $status['exitcode'] : $exit) !== 0) {
        throw new RuntimeException('Worker failed: '.$error);
    }

    return json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);
}
function send(array $worker, string $command): void
{
    if (fwrite($worker[1][0], $command."\n") !== strlen($command) + 1) {
        throw new RuntimeException('Worker release failed.');
    }
}
function heldRace(array $firstJob, array $secondJob): array
{
    try {
        $first = spawn($firstJob);
        $second = spawn($secondJob);
        send($first, 'go');
        if (trim(readWorker($first[1][1], true)) !== 'held') {
            throw new RuntimeException('First worker did not reach the in-transaction pause.');
        }
        send($second, 'go');
        $start = microtime(true);
        do {
            $waiting = DB::table('pg_stat_activity')->where('pid', $second[2])->where('wait_event_type', 'Lock')->exists();
            $read = [$second[1][1]];
            $write = $except = [];
            $ready = stream_select($read, $write, $except, 0, 0);
            if ($ready === false) {
                throw new RuntimeException('Worker stream select failed.');
            }
            $answered = $ready > 0;
            if ($waiting || $answered) {
                break;
            }
            usleep(10000);
        } while (microtime(true) - $start < 5);
        if (! $waiting && ! $answered) {
            throw new RuntimeException('Second worker neither waited nor answered.');
        }
        send($first, 'release');
        $results = [finish($first), finish($second)];
        echo json_encode(['second_waited_in_postgres' => $waiting, 'statuses' => array_column($results, 'status')])."\n";

        return $results;
    } finally {
        cleanupWorkers();
    }
}
function afterExpiry(array $job, string $id, string $expiry): array
{
    try {
        $worker = spawn($job);
        DB::beginTransaction();
        try {
            DB::table('product_recognition_requests')->where('id', $id)->lockForUpdate()->first();
            send($worker, 'go');
            $start = microtime(true);
            do {
                $waiting = DB::table('pg_stat_activity')->where('pid', $worker[2])->where('wait_event_type', 'Lock')->exists();
                usleep(10000);
            } while (! $waiting && microtime(true) - $start < 1);
            if (! $waiting) {
                throw new RuntimeException('Expiry worker was not observed waiting before expiry.');
            }
            while (now()->lessThanOrEqualTo($expiry)) {
                if (microtime(true) - $start >= 20) {
                    throw new RuntimeException('Expiry wait timed out.');
                }
                usleep(50000);
            }
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        $result = finish($worker);
        echo json_encode(['expiry_lock_wait_ms' => round((microtime(true) - $start) * 1000), 'status' => $result['status']])."\n";

        return $result;
    } finally {
        cleanupWorkers();
    }
}
$failed = 0;
function check(bool $ok, string $label): void
{
    $GLOBALS['failed'] += $ok ? 0 : 1;
    echo ($ok ? 'PASS: ' : 'FAIL: ').$label."\n";
}
function cannotFinish(User $source, User $target, ?string $code, ?string $id): bool
{
    $service = app(ProductPromotionService::class);
    try {
        if ($code) {
            $service->approve($target, 'password', $code);
            $id = $service->requests($source)->where('code_hash', hash('sha256', $code))->value('id');
        }
        $service->finish($source, 'password', $id);

        return false;
    } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $e) {
        return true;
    }
}

foreach (['hold-start', 'hold-approve'] as $action) {
    $source = $fixture->owner('erp');
    $target = $fixture->owner('dhiran');
    $code = $action === 'hold-approve' ? $service->start($source, 'password') : null;
    $id = $code ? $service->requests($source)->value('id') : null;
    $actor = $action === 'hold-start' ? $source : $target;
    $results = heldRace([$action, $actor->id, $code], ['cycle', $actor->id, null]);
    check($results[0]['status'] === 200 && $results[1]['status'] === 200
        && cannotFinish($source, $target, $action === 'hold-start' ? $results[0]['value'] : null, $id),
        $action.' proof cannot survive normal owner-role change and restoration');
}
$source = $fixture->owner('erp');
$target = $fixture->owner('dhiran');
$code = $service->start($source, 'password');
$id = $service->requests($source)->value('id');
$service->approve($target, 'password', $code);
$service->finish($source, 'password', $id);
$link = $service->recognitions($source)->first()->id;
$code = $service->start($source, 'password');
$id = $service->requests($source)->whereNull('consumed_at')->value('id');
$results = heldRace(['hold-approve', $target->id, $code], ['revoke', $target->id, $link]);
check(array_column($results, 'status') === [200, 200] && cannotFinish($source, $target, null, $id)
    && $service->recognitions($source)->isEmpty(), 'revocation cannot be undone by an in-flight approval');
foreach (['approve' => 422, 'finish' => 409] as $action => $expectedStatus) {
    $source = $fixture->owner('erp');
    $target = $fixture->owner('dhiran');
    $code = $service->start($source, 'password');
    $id = $service->requests($source)->value('id');
    if ($action === 'finish') {
        $service->approve($target, 'password', $code);
    }
    $expiry = now()->addSeconds(2)->format('Y-m-d H:i:s');
    DB::table('product_recognition_requests')->where('id', $id)->update(['expires_at' => $expiry]);
    $result = afterExpiry([$action, $action === 'approve' ? $target->id : $source->id, $action === 'approve' ? $code : $id], $id, $expiry);
    check($result['status'] === $expectedStatus && $service->recognitions($source)->isEmpty(), $action.' rejects expiry reached during row-lock wait');
}
$source = $fixture->owner('erp');
$target = $fixture->owner('dhiran');
$code = $service->start($source, 'password');
$id = $service->requests($source)->whereNull('consumed_at')->value('id');
$results = heldRace(['hold-approve', $target->id, $code], ['cancel', $source->id, $id]);
check(array_column($results, 'status') === [200, 200] && cannotFinish($source, $target, null, $id)
    && $service->recognitions($source)->isEmpty(), 'source cancellation wins over an in-flight approval');

// Isolated owners and one failed finish; only a new worker may retry the request.
$source = $fixture->owner('erp');
$target = $fixture->owner('dhiran');
$code = $service->start($source, 'password');
$id = $service->requests($source)->whereNull('consumed_at')->value('id');
$service->approve($target, 'password', $code);
$pair = fn () => DB::table('product_recognitions')->where('environment', app()->environment())
    ->where('erp_user_id', $source->id)->where('erp_shop_id', $source->shop_id)
    ->where('dhiran_user_id', $target->id)->where('dhiran_shop_id', $target->shop_id);
try {
    $worker = spawn(['hold-finish', $source->id, $id]);
    send($worker, 'go');
    if (trim(readWorker($worker[1][1], true)) !== 'held') {
        throw new RuntimeException('Finish worker did not pause after the uncommitted recognition upsert.');
    }
    // Every predicate is checked by PostgreSQL before terminating this exact backend.
    $termination = DB::selectOne(<<<'SQL'
        select pg_terminate_backend(pid)::int as terminated
        from pg_stat_activity
        where pid = ? and pid <> pg_backend_pid()
          and datname = current_database() and current_database() = 'jewelflow_testing'
          and usename = current_user and application_name = ?
          and xact_start is not null and state = 'idle in transaction'
        SQL, [$worker[2], $worker[3]]);
    if ((int) ($termination->terminated ?? 0) !== 1) {
        throw new RuntimeException('Refused to terminate an unidentified or non-transactional worker backend.');
    }
    $deadline = microtime(true) + 5;
    do {
        $alive = DB::table('pg_stat_activity')->where('pid', $worker[2])->where('application_name', $worker[3])->exists();
        if (! $alive) {
            break;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    if ($alive) {
        throw new RuntimeException('Terminated worker backend did not disappear in time.');
    }
    send($worker, 'release');
    $result = finish($worker);
    check($result['status'] === 500 && isset($result['exception']), 'connection loss fails the original finish without a silent retry');
    check($pair()->count() === 0, 'connection loss rolls back the uncommitted recognition');
    check($service->requests($source)->where('id', $id)->where('target_user_id', $target->id)
        ->whereNotNull('target_approved_at')->whereNotNull('target_proof')->whereNull('consumed_at')->exists(),
        'connection loss leaves the approved request unconsumed');
    DB::beginTransaction();
    try {
        $unlocked = true;
        foreach ([$source->id, $target->id] as $ownerId) {
            $lock = DB::selectOne('select pg_try_advisory_xact_lock(hashtextextended(?, 0))::int as acquired', ['product-promotion-owner:'.$ownerId]);
            $unlocked = (int) $lock->acquired === 1 && $unlocked;
        }
        check($unlocked, 'connection loss releases both owner transaction locks');
    } finally {
        DB::rollBack();
    }
    $fresh = spawn(['finish', $source->id, $id]);
    if ($fresh[2] === $worker[2] || $fresh[3] === $worker[3]) {
        throw new RuntimeException('Recovery did not use a fresh worker connection.');
    }
    send($fresh, 'go');
    $result = finish($fresh);
    check($result['status'] === 200 && $pair()->count() === 1
        && $service->requests($source)->where('id', $id)->whereNotNull('consumed_at')->exists(),
        'a fresh separate worker can finish the same approved request exactly once');
} finally {
    cleanupWorkers();
}
echo $failed === 0 ? "PASS: all seven proof/approval/expiry/cancellation/connection-loss interleavings\n"
    : "FAIL: {$failed} assertions across seven interleavings\n";
exit($failed === 0 ? 0 : 1);
