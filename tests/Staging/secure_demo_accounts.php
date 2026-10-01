<?php

/**
 * Secure the three demo accounts PilotDemoSeeder left on STAGING with its
 * published password. Narrow by construction: it acts on three pinned user ids
 * in one pinned shop of one pinned database, and aborts before changing
 * anything if what it finds differs from the pins in any way.
 *
 *   cd /var/www/jewelflow-staging && sudo -u www-data php tests/Staging/secure_demo_accounts.php plan
 *   cd /var/www/jewelflow-staging && sudo -u www-data php tests/Staging/secure_demo_accounts.php apply
 *   cd /var/www/jewelflow-staging && sudo -u www-data php tests/Staging/secure_demo_accounts.php verify
 *
 * plan    read-only: checks every pin, says what apply would do.
 * apply   proves the exposure first (a web session and a mobile token obtained
 *         with the published password, through the application itself), then
 *         disables the accounts the way staff removal does (employment
 *         terminated, is_active false: EnsureAccountIsActive refuses them on
 *         every authenticated web, API and mobile route), deletes their
 *         personal access tokens and their rows in the session table, rotates
 *         the remember token, sets a random password nobody knows, and proves
 *         that the session, the token and the published password are refused.
 * verify  read-only re-check of the end state, any time later (a minute or more
 *         after apply: the login routes allow five attempts a minute).
 *
 * It refuses unless the session driver is `database`: deleting session rows
 * revokes nothing under a file or Redis driver. It never prints a password or
 * a token. Mail goes to the in-memory mailer for this process. Recovery, if
 * the demo shop is wanted again: StaffController::reactivate()'s fields
 * (employment_status active, is_active true) plus a password of your own.
 *
 * Exit 0 = every check passed; 1 = a check failed; 2 = wrong place; 3 = a pin
 * did not match (nothing was changed).
 */

use App\Models\Platform\PlatformAdmin;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\PilotDemoSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(HttpKernel::class);
$kernel->bootstrap();

$mode = $argv[1] ?? 'plan';
if (! in_array($mode, ['plan', 'apply', 'verify'], true)) {
    fwrite(STDERR, "usage: secure_demo_accounts.php plan|apply|verify\n");
    exit(2);
}

// What staging is expected to hold (observed read-only 2026-10-01).
$pins = [
    'shop' => ['id' => 2, 'name' => 'Jewelflow Demo Jewellers', 'owner_mobile' => '9000000111'],
    'accounts' => [1 => ['9000000111', 'Demo Owner'], 2 => ['9000000112', 'Demo Manager'], 3 => ['9000000113', 'Demo Cashier']],
];
$onStaging = app()->environment('staging')
    && DB::connection()->getDatabaseName() === 'jewelflow_staging'
    && parse_url((string) config('app.url'), PHP_URL_HOST) === 'staging.jewelflows.com';
// To test this script: the local test database, by explicit opt-in, with its own pins. Never production.
$onLocal = getenv('SECURE_DEMO_LOCAL_TESTING') === '1' && app()->environment(['local', 'testing'])
    && DB::connection()->getDatabaseName() === 'jewelflow_testing';
if ($onLocal && ! $onStaging) {
    $pins = json_decode((string) getenv('SECURE_DEMO_LOCAL_PINS'), true) ?: $pins;
}
if (! $onStaging && ! $onLocal) {
    fwrite(STDERR, "REFUSED: runs only on staging (APP_ENV=staging, database jewelflow_staging, host staging.jewelflows.com)\n");
    exit(2);
}
config(['mail.default' => 'array', 'queue.default' => 'sync']);
Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['*']);   // this process only
app()->instance('request', Request::create((string) config('app.url')));

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("%-5s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : " — {$detail}");
}
function abortPin(string $why): void
{
    fwrite(STDOUT, "ABORT {$why}\n      Nothing was changed.\n");
    exit(3);
}

/** One request through the kernel with a cookie jar, as a separate browser process would send it. */
function http(string $method, string $uri, array $data = [], array &$jar = [], array $headers = [], ?int $shopId = null)
{
    global $kernel;
    Auth::forgetGuards();
    Auth::shouldUse('web');
    TenantContext::clear();
    app('session')->driver()->flush();   // no session state carried in memory between requests
    if ($shopId !== null) {
        TenantContext::set($shopId);     // under the CLI the tenant scope does not fall back to the signed-in user
    }
    $api = str_contains($uri, '/api/');
    $server = ['HTTP_ACCEPT' => $api ? 'application/json' : 'text/html'];
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $json = $api && $method !== 'GET';
    if ($json) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $request = Request::create(url($uri), $method, $json ? [] : $data, $jar, [], $server, $json ? json_encode($data) : null);
    app()->instance('request', $request);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) {
        $jar[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);
    Auth::forgetGuards();
    TenantContext::clear();

    return $response;
}
function toLogin($response): bool
{
    return $response->isRedirection() && parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) === '/login';
}

// ── pins: every one must hold, or nothing happens ────────────────────────────
$driver = (string) config('session.driver');
if ($driver !== 'database') {
    abortPin("the session driver is '{$driver}', not 'database': this procedure revokes sessions by deleting their rows, which revokes nothing under another driver");
}
$sessions = (string) config('session.table');
$shops = Shop::where('owner_mobile', $pins['shop']['owner_mobile'])->get();
if ($shops->count() !== 1 || (int) $shops[0]->id !== (int) $pins['shop']['id'] || $shops[0]->name !== $pins['shop']['name']) {
    abortPin('expected exactly one shop owned by the demo mobile, id '.$pins['shop']['id'].' named "'.$pins['shop']['name'].'"; found '.$shops->count()
        .($shops->count() ? ' (id '.$shops[0]->id.', "'.$shops[0]->name.'")' : ''));
}
$shop = $shops[0];
$mobiles = array_column($pins['accounts'], 0);
$users = User::withoutGlobalScopes()->whereIn('mobile_number', $mobiles)->orderBy('id')->get()->keyBy('id');
if ($users->count() !== count($pins['accounts'])) {
    abortPin('expected '.count($pins['accounts']).' accounts with the demo mobiles, found '.$users->count());
}
foreach ($pins['accounts'] as $id => [$mobile, $name]) {
    $u = $users->get($id);
    if (! $u || $u->mobile_number !== $mobile || $u->name !== $name || (int) $u->shop_id !== (int) $shop->id) {
        abortPin("account {$id} is not the pinned one (mobile, name or shop differs)");
    }
}
$inShop = User::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
if ($inShop !== count($pins['accounts'])) {
    abortPin("the demo shop has {$inShop} users, expected ".count($pins['accounts']).': an account this procedure does not know');
}
$lookalikes = User::withoutGlobalScopes()->where('mobile_number', 'like', '90000001%')->whereNotIn('id', array_keys($pins['accounts']))->count();
if ($lookalikes !== 0) {
    abortPin("{$lookalikes} other account(s) carry a demo-range mobile");
}
if (PlatformAdmin::where('mobile_number', '9000000100')->exists()) {
    abortPin("the seeder's platform admin exists here: it needs its own handling, and this procedure does not touch platform admins");
}
$published = (new ReflectionClassConstant(PilotDemoSeeder::class, 'DEMO_PASSWORD'))->getValue();
$opens = fn (User $u): bool => Hash::check($published, $u->password);
$others = fn (): array => [
    'active users elsewhere' => User::withoutGlobalScopes()->whereNotIn('id', array_keys($pins['accounts']))->whereRaw('is_active is true')->count(),
    'users in total' => User::withoutGlobalScopes()->count(),
    'sessions of other users' => DB::table($sessions)->whereNotIn('user_id', array_keys($pins['accounts']))->count(),
    'tokens of other users' => PersonalAccessToken::whereNotIn('tokenable_id', array_keys($pins['accounts']))->count(),
];

echo 'place: ', $onStaging ? 'staging' : 'LOCAL TEST', ', database ', DB::connection()->getDatabaseName(), ', session driver ', $driver, ', shop ', $shop->id, ' "', $shop->name, "\"\n";
foreach ($users as $u) {
    printf("      account %d %s: %s, employment %s; published password %s; tokens %d; session rows %d; remember token %s\n", $u->id, $u->name,
        $u->is_active ? 'active' : 'inactive', $u->employment_status, $opens($u) ? 'OPENS IT' : 'does not open it',
        $u->tokens()->count(), DB::table($sessions)->where('user_id', $u->id)->count(), $u->remember_token ? 'set' : 'none');
}
$secured = $users->every(fn (User $u) => ! $u->is_active && ! $opens($u));

if ($mode === 'plan') {
    echo $secured
        ? "plan: all three are already disabled and the published password opens none. `verify` re-checks.\n"
        : "plan: apply would terminate these three accounts, delete their tokens and session rows, rotate their remember tokens and set random passwords. Every pin matched.\n";
    exit(0);
}

$applied = false;
if ($mode === 'apply' && ! $secured) {
    $before = $others();
    $owner = $users->first();

    // ── before: the exposure, shown through the application itself ───────────
    $jar = [];
    http('GET', '/login', [], $jar);
    $login = http('POST', '/login', ['mobile_number' => $owner->mobile_number, 'password' => $published], $jar);
    $page = http('GET', '/dashboard', [], $jar, [], (int) $shop->id);
    check(! toLogin($login) && ! toLogin($page) && $page->getStatusCode() < 400, 'before: the published password signs in on the web and the session is accepted',
        'login '.$login->getStatusCode().', then /dashboard '.$page->getStatusCode());
    $api = http('POST', '/api/mobile/auth/login', ['mobile_number' => $owner->mobile_number, 'password' => $published, 'device_name' => 'lock-probe']);
    $token = (string) (json_decode((string) $api->getContent(), true)['token'] ?? '');
    $noJar = [];
    $me = http('GET', '/api/mobile/auth/me', [], $noJar, ['Authorization' => 'Bearer '.$token], (int) $shop->id);
    check($token !== '' && $me->getStatusCode() === 200, 'before: the published password signs in on the mobile API and the token is accepted',
        'login '.$api->getStatusCode().', then /auth/me '.$me->getStatusCode());
    $owner->refresh();
    $hadSession = DB::table($sessions)->where('user_id', $owner->id)->count();
    $hadToken = $owner->tokens()->count();
    check($hadSession >= 1 && $hadToken >= 1, 'before: that left a session row and a token for the account', "session rows {$hadSession}, tokens {$hadToken}");
    if ($failures > 0) {
        // Without a session and a token that were really accepted, "refused afterwards" would prove nothing.
        echo "STOPPED: the exposure could not be shown (above), so nothing was changed. A 429 is the login rate limit: wait a minute and run apply again.\n";
        exit(1);
    }

    // ── the change ───────────────────────────────────────────────────────────
    DB::transaction(function () use ($users, $sessions) {
        foreach ($users as $u) {
            $u->tokens()->delete();
            DB::table($sessions)->where('user_id', $u->id)->delete();
            $u->forceFill([
                'employment_status' => 'terminated',
                'terminated_at' => now(),
                'terminated_with_role_name' => $u->role?->name,
                'is_active' => false,
                'remember_token' => Str::random(60),
                'password' => Hash::make(bin2hex(random_bytes(32))),
            ])->save();
        }
    });
    $applied = true;
    echo "applied: three accounts terminated, tokens and session rows deleted, remember tokens rotated, passwords randomised\n";

    // ── after: what was accepted a moment ago is refused ─────────────────────
    $page = http('GET', '/dashboard', [], $jar, [], (int) $shop->id);
    check(toLogin($page), 'after: the session accepted before is sent to /login', 'status '.$page->getStatusCode());
    $me = http('GET', '/api/mobile/auth/me', [], $noJar, ['Authorization' => 'Bearer '.$token], (int) $shop->id);
    check($me->getStatusCode() === 401, 'after: the token accepted before is refused', 'status '.$me->getStatusCode());
    check($before === $others(), 'no other account, session or token changed', json_encode($others()));
    $users = User::withoutGlobalScopes()->whereIn('id', array_keys($pins['accounts']))->orderBy('id')->get()->keyBy('id');
}

// ── end state (apply and verify) ─────────────────────────────────────────────
foreach ($users as $u) {
    check(! $u->is_active && $u->employment_status === 'terminated', "account {$u->id}: disabled (EnsureAccountIsActive refuses it on every authenticated route)");
    check(! $opens($u) && ! Auth::guard('web')->validate(['mobile_number' => $u->mobile_number, 'password' => $published]),
        "account {$u->id}: the published password does not authenticate");
    check($u->tokens()->count() === 0 && DB::table($sessions)->where('user_id', $u->id)->count() === 0, "account {$u->id}: no token and no session row");
    // The login routes share one limit of five attempts a minute per address, so
    // a run makes at most five: apply tries the web sign-in for each account,
    // verify the mobile sign-in for each and the web sign-in for the first. A
    // rate-limited attempt proves nothing and is reported as not tested.
    $limited = 'rate limited (429): not tested, run verify again in a minute';
    if ($applied || $u->id === $users->keys()->first()) {
        $jar = [];
        http('GET', '/login', [], $jar);
        $login = http('POST', '/login', ['mobile_number' => $u->mobile_number, 'password' => $published], $jar);
        $page = http('GET', '/dashboard', [], $jar, [], (int) $shop->id);
        check($login->getStatusCode() !== 429 && toLogin($page), "account {$u->id}: a web sign-in with the published password gets no session",
            $login->getStatusCode() === 429 ? $limited : 'login '.$login->getStatusCode().', /dashboard '.$page->getStatusCode());
    }
    if (! $applied) {
        $api = http('POST', '/api/mobile/auth/login', ['mobile_number' => $u->mobile_number, 'password' => $published, 'device_name' => 'lock-probe']);
        check($api->getStatusCode() >= 400 && $api->getStatusCode() !== 429 && empty(json_decode((string) $api->getContent(), true)['token']),
            "account {$u->id}: a mobile sign-in with the published password gets no token", $api->getStatusCode() === 429 ? $limited : 'status '.$api->getStatusCode());
    }
    check(DB::table($sessions)->where('user_id', $u->id)->count() === 0 && $u->tokens()->count() === 0, "account {$u->id}: those attempts left nothing behind");
}

echo $failures === 0 ? "DEMO ACCOUNTS: secured, every check passed\n" : "DEMO ACCOUNTS: {$failures} check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
