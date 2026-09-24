<?php

/**
 * §7e — route-model binding on the PRODUCTION path.
 *
 *   php tests/Concurrency/production_binding_path.php
 *
 * Implicit binding runs after Authenticate and BEFORE EnsureTenantUser sets
 * the tenant context. In a real HTTP request BelongsToShop therefore resolves
 * the shop from the authenticated user (its non-console fallback). PHPUnit
 * never takes that path: it runs with runningInConsole() true, where the
 * fallback is off, so tests set TenantContext themselves.
 *
 * Here each request is handled in its own child process with
 * APP_RUNNING_IN_CONSOLE=false and no TenantContext set — the production
 * resolution order — and the child asserts both before handling.
 *
 * Shop A's credentials ask for shop A's and shop B's items and customers
 * (mobile API, bearer token) and customers (web, session guard). SAFE: every
 * own record answers 200; every foreign one is refused without its content;
 * a PATCH naming B's item changes nothing. Refuses any database not named
 * jewelflow_testing.
 */

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;

require __DIR__.'/../../vendor/autoload.php';

// ── child: one request on the production resolution path ─────────────────
if (($argv[1] ?? null) === 'request') {
    [, , $method, $uri, $credential, $guard] = $argv;
    $body = $argv[6] ?? null;
    $app = require __DIR__.'/../../bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $kernel->bootstrap();
    // The child is its own entry point: refuse here, before any user lookup
    // or request dispatch, not only in the parent.
    if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
        fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
        exit(2);
    }
    if ($app->runningInConsole() || App\Support\TenantContext::get() !== null) {
        echo json_encode(['status' => -1, 'err' => 'not on the production path']), "\n";
        exit(0);
    }
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_HOST' => 'jewelflows.com', 'HTTPS' => 'on'];
    if ($guard === 'token') {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$credential;
        $server['HTTP_X_IDEMPOTENCY_KEY'] = 'prodpath-'.bin2hex(random_bytes(6));
        $server['HTTP_IF_MATCH'] = '"any"';
    }
    $request = Request::create($uri, $method, [], [], [], $server, $body);
    if ($guard === 'web') {
        // The session guard needs the request bound before a user is set on it.
        $app->instance('request', $request);
        Auth::guard('web')->setUser(User::withoutGlobalScopes()->findOrFail((int) $credential));
    }
    $response = $kernel->handle($request);
    echo json_encode(['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()]), "\n";
    exit(0);
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (DB::connection()->getDatabaseName() !== 'jewelflow_testing') {
    fwrite(STDERR, "REFUSED: not jewelflow_testing\n");
    exit(2);
}

final class BindingFixture
{
    use CreatesTestTenant;

    public function shop(string $tag): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer((int) $shop->id, ['first_name' => $tag.'Customer']);
        $item = $this->createItem((int) $shop->id, null, ['design' => $tag.'Design', 'selling_price' => 5000]);

        return [$owner, $shop, $customer, $item, $owner->createToken('prodpath')->plainTextToken];
    }
}

function request_as(string $method, string $uri, string $credential, string $guard, ?string $body = null): array
{
    $proc = proc_open(array_values(array_filter(['php', __FILE__, 'request', $method, $uri, $credential, $guard, $body], fn ($a) => $a !== null)),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), ['APP_RUNNING_IN_CONSOLE' => 'false'] + getenv());
    $out = json_decode(trim(stream_get_contents($pipes[1])), true) ?? ['status' => -1, 'err' => mb_substr(stream_get_contents($pipes[2]), 0, 300)];
    proc_close($proc);

    return $out;
}

$fixture = new BindingFixture;
[$ownerA, , $customerA, $itemA, $tokenA] = $fixture->shop('AlphaPP');
[, , $customerB, $itemB] = $fixture->shop('BravoPP');
$priceBBefore = (string) DB::table('items')->where('id', $itemB->id)->value('selling_price');

$cases = [
    ['mobile item, own',        'GET', "/api/mobile/v1/items/{$itemA->id}", $tokenA, 'token', 200, 'AlphaPP', null],
    ['mobile item, foreign',    'GET', "/api/mobile/v1/items/{$itemB->id}", $tokenA, 'token', 404, null, 'BravoPP'],
    ['mobile customer, own',    'GET', "/api/mobile/v1/customers/{$customerA->id}", $tokenA, 'token', 200, 'AlphaPP', null],
    ['mobile customer, foreign','GET', "/api/mobile/v1/customers/{$customerB->id}", $tokenA, 'token', 404, null, 'BravoPP'],
    ['web customer, own',       'GET', "/customers/{$customerA->id}", (string) $ownerA->id, 'web', 200, 'AlphaPP', null],
    ['web customer, foreign',   'GET', "/customers/{$customerB->id}", (string) $ownerA->id, 'web', 404, null, 'BravoPP'],
    ['mobile item PATCH, foreign', 'PATCH', "/api/mobile/v1/items/{$itemB->id}", $tokenA, 'token', 404, null, 'BravoPP', json_encode(['selling_price' => 1])],
];

$unsafe = 0;
foreach ($cases as $c) {
    [$label, $method, $uri, $cred, $guard, $want, $mustShow, $mustNotShow] = $c;
    $r = request_as($method, $uri, $cred, $guard, $c[8] ?? null);
    $body = $r['body'] ?? '';
    $ok = $r['status'] === $want && ($mustShow === null || str_contains($body, $mustShow)) && ($mustNotShow === null || ! str_contains($body, $mustNotShow));
    $unsafe += $ok ? 0 : 1;
    printf("%-28s %s %-40s -> %d%s %s\n", $label, $method, $uri, $r['status'], isset($r['err']) ? ' '.$r['err'] : '', $ok ? 'ok' : 'UNEXPECTED');
}
$priceBAfter = (string) DB::table('items')->where('id', $itemB->id)->value('selling_price');
echo "shop B's item price before/after the foreign PATCH: {$priceBBefore} / {$priceBAfter}\n";
$unsafe += $priceBBefore === $priceBAfter ? 0 : 1;

echo $unsafe === 0 ? "RESULT: SAFE — production binding path: own records 200, foreign refused, nothing changed\n" : "RESULT: {$unsafe} UNEXPECTED\n";
exit($unsafe === 0 ? 0 : 1);
