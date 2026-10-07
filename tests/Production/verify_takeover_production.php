<?php

/**
 * Production check for the takeover release, run INSIDE the maintenance window,
 * before `up`, and only with the owner's explicit approval for that window:
 *
 *   cd /var/www/jewelflow && sudo -u www-data env TAKEOVER_PRODUCTION_CHECK=approved \
 *       php tests/Production/verify_takeover_production.php
 *
 * Rehearsal on the disposable local database (never the preview database):
 *
 *   APP_ENV=testing VERIFY_LOCAL_TESTING=1 php8.2 tests/Production/verify_takeover_production.php
 *
 * Scope, deliberately narrow. It creates dedicated test identities (two Retail
 * shops and one Dhiran shop, each with its default roles and one owner) and
 * exercises promotion metadata only: the configured product addresses, product
 * preferences, the recognition flow across the two products, suppression,
 * tenant isolation and lifecycle invalidation. It creates no invoice, payment,
 * loan, stock, customer, subscription or platform-admin row and touches no
 * existing shop, user or customer. Requests go through the deployed HTTP
 * kernel with the product's own host.
 *
 * Everything happens in ONE database transaction that is rolled back. A
 * rollback does not undo everything, so the script measures what is left and
 * prints it under LASTING EFFECTS:
 *   - rolled back: every row it wrote (proved by a count and content hash of
 *     every table, before and after) and the row and advisory locks it held;
 *   - NOT rolled back: sequence values (ids are consumed and leave gaps; they
 *     are reported and never reset), dead row versions and WAL in PostgreSQL,
 *     and any file written (log lines; compiled views of pages the view cache does not
 *     hold, such as the framework's own error pages);
 *   - prevented for this process: queued jobs, notifications, mail and HTTP
 *     calls are captured in memory and counted, sessions and the cache (the
 *     rate limiter) are in-memory arrays;
 *   - not exercised at all: anything deferred until after a commit.
 * It refuses to pass if a table outside its declared list was written, if the
 * transaction was committed or replaced under it, or if a second database
 * connection was opened.
 *
 * What a browser does (cookies per product, logout, Turbo, the pages on a
 * phone) is not exercised here: see the handoff's post-release browser list.
 */

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\ProductPromotionService;
use App\Services\TenantRoleService;
use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\MaintenanceModeBypassCookie;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

// A crash must never look like a pass. The framework's exception handler prints the error
// and lets PHP exit 0, so anything that ends this script before its last line exits 3.
$finished = false;
register_shutdown_function(function () use (&$finished) {
    if (! $finished) {
        fwrite(STDERR, "\nTHE CHECK DID NOT FINISH (crashed or stopped early): this is a FAILURE\n");
        exit(3);
    }
});

$app = require __DIR__.'/../../bootstrap/app.php';
// In production the application forces the https scheme while it boots, and the URL
// generator cannot be built without a request. Give it one before booting; the real
// host replaces it below. (Found by rehearsing in production mode; staging never hit it.)
$app->instance('request', Request::create('https://localhost'));
$kernel = $app->make(HttpKernel::class);
$kernel->bootstrap();

/** Tables this script may write inside its transaction. Anything else fails the run. */
const WRITES_ALLOWED = [
    'shops', 'shop_editions', 'platform_counters', 'roles', 'role_permission', 'users',
    'product_promotion_preferences', 'product_promotion_exposures', 'product_recognition_requests', 'product_recognitions',
];

$down = storage_path('framework/down');
$onProduction = app()->environment('production') && DB::connection()->getDatabaseName() === 'jewelflow';
$onLocal = getenv('VERIFY_LOCAL_TESTING') === '1' && ! app()->environment('production') && DB::connection()->getDatabaseName() === 'jewelflow_testing';
if ($onProduction && (getenv('TAKEOVER_PRODUCTION_CHECK') !== 'approved' || ! is_file($down))) {
    fwrite(STDERR, "REFUSED: on production this runs only inside the maintenance window (artisan down) with TAKEOVER_PRODUCTION_CHECK=approved\n");
    $finished = true;
    exit(2);
}
if (! $onProduction && ! $onLocal) {
    fwrite(STDERR, "REFUSED: runs on production in its window, or on jewelflow_testing with VERIFY_LOCAL_TESTING=1\n");
    $finished = true;
    exit(2);
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    fwrite(STDERR, "REFUSED: running as root would leave root-owned files under storage; run as the web user\n");
    $finished = true;
    exit(2);
}

config(['mail.default' => 'array', 'session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
if ($onLocal) {
    // The rehearsal has no .env: give it the shape production is configured with.
    config(['app.url' => 'https://jewelflows.test', 'platform.cross_promotion.enabled' => true,
        'platform.cross_promotion.dhiran_register_url' => 'https://dhiran.jewelflows.test/register',
        'platform.cross_promotion.erp_register_url' => 'https://jewelflows.test/register']);
}
// The rate limiter is built while the application boots, on the configured cache store (files,
// in production), before the line above can redirect it. Point it at the in-memory store too,
// keeping its named limiters, so that no throttle counter is left in the cache directory.
app()->forgetInstance('cache.store');
(function ($store) {
    $this->cache = $store;
})->call(app(Illuminate\Cache\RateLimiter::class), app('cache')->store('array'));
Queue::fake();          // nothing is queued or run; counted below
Notification::fake();   // nothing is sent or stored; counted below
Http::fake();           // no request leaves this process; counted below
Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['*']);   // this process only

$host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
$retail = "https://{$host}";
$www = "https://www.{$host}";
$dhiran = "https://dhiran.{$host}";
$bypass = [];
if (is_file($down) && ($secret = json_decode((string) file_get_contents($down), true)['secret'] ?? null)) {
    $bypass['laravel_maintenance'] = MaintenanceModeBypassCookie::create($secret)->getValue();
}
app()->instance('request', Request::create($retail));

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("%-5s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : " — {$detail}");
}

/** One request through the kernel on the given host, with a cookie jar per browser. */
function http(string $method, string $url, array $data = [], ?User $as = null, array &$jar = [])
{
    global $kernel, $bypass;
    Auth::forgetGuards();
    Auth::shouldUse('web');
    TenantContext::clear();
    app('session')->driver()->flush();
    if ($as !== null) {
        TenantContext::set((int) $as->shop_id);
        Auth::guard('web')->setUser($as);
    }
    $request = Request::create($url, $method, $data, $jar + $bypass, [], ['HTTP_ACCEPT' => 'text/html']);
    app()->instance('request', $request);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) {
        $jar[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);
    Auth::forgetGuards();

    return $response;
}

/** Every table of the public schema: its row count and a hash of its content. */
function tables(): array
{
    $out = [];
    foreach (DB::select("select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p') order by 1") as $t) {
        $r = DB::selectOne(sprintf('select count(*) as n, md5(coalesce(string_agg(md5(t::text), \'\' order by md5(t::text) collate "C"), \'\')) as h from public."%s" t', $t->relname));
        $out[$t->relname] = $r->n.':'.$r->h;
    }

    return $out;
}

function sequences(): array
{
    return collect(DB::select("select sequencename, coalesce(last_value, 0) as v from pg_sequences where schemaname = 'public'"))->pluck('v', 'sequencename')->all();
}

/** Size and modification time of every file the application can write. */
function files(): array
{
    $out = [];
    foreach ([storage_path(), base_path('bootstrap/cache')] as $root) {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
        foreach ($walk as $f) {
            if ($f->isFile()) {
                $out[$f->getPathname()] = $f->getSize().':'.$f->getMTime();
            }
        }
    }

    return $out;
}

function changed(array $before, array $after): array
{
    $keys = array_keys($before + $after);

    return array_values(array_filter($keys, fn ($k) => ($before[$k] ?? null) !== ($after[$k] ?? null)));
}

$stamp = now()->format('YmdHis');
$mobile = fn () => '9'.random_int(100000000, 999999999);
$env = app()->environment();
$tablesBefore = tables();
$sequencesBefore = sequences();
$filesBefore = files();
$touched = [];
$xidStart = $xidEnd = null;
$level = null;

DB::beginTransaction();
try {
    $xidStart = DB::selectOne('select txid_current() as x')->x;

    // ── dedicated test identities (rolled back) ──────────────────────────────
    $passwords = [];
    $owner = function (string $tag, string $realm) use ($stamp, $mobile, &$passwords): User {
        $shop = Shop::create(['name' => "SYNTH-{$stamp}-{$tag}", 'shop_type' => $realm === 'dhiran' ? 'dhiran' : 'retailer', 'phone' => '9000000000',
            'owner_first_name' => 'Synth', 'owner_last_name' => $tag, 'owner_mobile' => $mobile(), 'gst_rate' => 3.00,
            'wastage_recovery_percent' => 100.00, 'access_mode' => 'active', 'is_active' => true]);
        app(TenantRoleService::class)->ensureDefaultsForShop((int) $shop->id);
        $role = Role::withoutTenant()->where('shop_id', $shop->id)->where('name', 'owner')->firstOrFail();
        $u = new User;
        $u->forceFill(['shop_id' => $shop->id, 'role_id' => $role->id, 'realm' => $realm, 'name' => "Synth {$tag}", 'mobile_number' => $mobile(),
            'password' => Hash::make($passwords[$tag] = Str::random(24)), 'is_active' => true])->save();

        return $u->fresh();
    };
    $a = $owner('RETAIL-A', 'erp');
    $b = $owner('RETAIL-B', 'erp');
    $d = $owner('DHIRAN-D', 'dhiran');
    $jarA = $jarB = $jarD = $jarGuest = [];
    $service = app(ProductPromotionService::class);
    $as = function (User $u, string $url) {
        TenantContext::set((int) $u->shop_id);
        $request = Request::create($url);
        $request->setUserResolver(fn () => $u->fresh());

        return $request;
    };
    $retailPrefs = "{$retail}/product-preferences";
    $dhiranPrefs = "{$dhiran}/dhiran/product-preferences";

    // ── the window holds: a visitor without the bypass gets nothing ──────────
    if ($bypass !== []) {
        [$held, $bypass] = [$bypass, []];
        $visitor = http('GET', "{$retail}/")->getStatusCode();
        $bypass = $held;
        check($visitor === 503, 'maintenance: a visitor without the bypass is refused while this runs', (string) $visitor);
    } else {
        check($onLocal, 'maintenance: not in a window (allowed only for the local rehearsal)');
    }

    // ── the addresses this environment hands out ─────────────────────────────
    $toDhiran = "{$dhiran}/register";
    $toRetail = "{$retail}/register";
    check(config('platform.cross_promotion.dhiran_register_url') === $toDhiran && config('platform.cross_promotion.erp_register_url') === $toRetail,
        'config: both product addresses are set explicitly', "{$toDhiran} | {$toRetail}");
    $pages = [];
    foreach ([$retail, $www] as $root) {
        $r = http('GET', "{$root}/", [], null, $jarGuest);
        $html = (string) $r->getContent();
        $pages[] = $r->getStatusCode() === 200 && str_contains($html, 'Start with Retail') && str_contains($html, 'href="'.$toDhiran.'"')
            && str_contains($html, 'href="'.$dhiran.'/login"') && ! str_contains($html, 'dhiran.www.') ? 'ok' : 'WRONG '.$r->getStatusCode();
    }
    $root = http('GET', "{$dhiran}/", [], null, $jarGuest);
    check($pages === ['ok', 'ok'] && $root->getStatusCode() === 302 && str_contains((string) $root->headers->get('Location'), '/login'),
        'landing: the bare and the www host both link to the one Dhiran address (no dhiran.www.); the Dhiran root still redirects to its log-in',
        implode('/', $pages).' / '.$root->getStatusCode());
    check($service->targetUrl(Request::create("{$www}/dashboard"), $a) === $toDhiran && $service->targetUrl(Request::create("{$dhiran}/dhiran/dashboard"), $d) === $toRetail,
        'addresses: a Retail owner arriving on www is pointed at the Dhiran address, a Dhiran owner at the Retail address');

    // ── pages and who may open them ──────────────────────────────────────────
    $codes = [http('GET', $retailPrefs, [], $a, $jarA)->getStatusCode(), http('GET', $dhiranPrefs, [], $d, $jarD)->getStatusCode(),
        http('GET', $retailPrefs, [], null, $jarGuest)->getStatusCode(), http('GET', $dhiranPrefs, [], $a, $jarA)->getStatusCode(),
        http('GET', $retailPrefs, [], $d, $jarD)->getStatusCode()];
    check($codes === [200, 200, 302, 302, 302],
        'preferences: each owner opens the page of their own product; a guest and an owner of the other product are sent away', implode('/', $codes));

    // ── one introduction per owner, permanent choices ────────────────────────
    $first = $service->claimIntroduction($as($a, "{$retail}/dashboard"));
    $again = $service->claimIntroduction($as($a, "{$retail}/dashboard"));
    check($first === $toDhiran && $again === null, 'introduction: shown once to an owner, with the configured address, and not again', var_export($first, true));
    $choice = fn (User $u) => DB::table('product_promotion_preferences')->where(['environment' => $env, 'shop_id' => $u->shop_id, 'user_id' => $u->id])->value('choice');
    $r = http('POST', "{$retailPrefs}/preference", ['choice' => 'opt_out'], $b, $jarB);
    check($r->getStatusCode() === 302 && $choice($b) === 'opt_out' && $choice($a) === null && $service->claimIntroduction($as($b, "{$retail}/dashboard")) === null,
        'preferences: "Don\'t show again" is recorded for that owner only and stops their introduction', var_export($choice($b), true));

    // ── recognition across the two products ──────────────────────────────────
    $requests = fn (User $u) => DB::table('product_recognition_requests')->where('source_user_id', $u->id);
    $live = fn (User $u) => $service->recognitions($u->fresh())->count();
    $code = function () use ($retailPrefs, $a, &$jarA): string {
        preg_match('~<code[^>]*>\s*([0-9A-F]{40})\s*</code>~', (string) http('GET', $retailPrefs, [], $a, $jarA)->getContent(), $m);

        return $m[1] ?? '';
    };
    http('POST', "{$retailPrefs}/start", ['password' => 'not-the-password', 'consent' => '1'], $a, $jarA);
    $wrong = $requests($a)->count();
    http('POST', "{$retailPrefs}/start", ['password' => $passwords['RETAIL-A'], 'consent' => '1'], $a, $jarA);
    $shown = $code();
    $request = $requests($a)->whereNull('consumed_at')->first();
    check($wrong === 0 && $request !== null && $shown !== '' && $request->code_hash !== $shown,
        'recognition: a request needs the owner\'s own password; the code is shown to them and only its hash is stored', "wrong password made {$wrong}");
    $foreign = [http('POST', "{$retailPrefs}/cancel", ['request_id' => $request->id], $b, $jarB)->getStatusCode(),
        http('POST', "{$retailPrefs}/finish", ['password' => $passwords['RETAIL-B'], 'consent' => '1', 'request_id' => $request->id], $b, $jarB)->getStatusCode()];
    check($requests($a)->whereNull('consumed_at')->count() === 1 && $live($b) === 0,
        'tenant isolation: another shop\'s owner can neither cancel nor finish the request', implode('/', $foreign));
    http('POST', "{$dhiranPrefs}/approve", ['password' => $passwords['DHIRAN-D'], 'consent' => '1', 'code' => $shown], $d, $jarD);
    $approved = $requests($a)->whereNotNull('target_user_id')->whereNull('consumed_at')->count();
    http('POST', "{$retailPrefs}/finish", ['password' => $passwords['RETAIL-A'], 'consent' => '1', 'request_id' => $request->id], $a, $jarA);
    check($approved === 1 && $live($a) === 1 && $live($d) === 1 && $live($b) === 0,
        'recognition: the Dhiran owner approves on the Dhiran host with their own password, the Retail owner finishes; both now see it, nobody else does',
        "approved {$approved}, A {$live($a)}, D {$live($d)}, B {$live($b)}");
    check($service->claimIntroduction($as($d, "{$dhiran}/dhiran/dashboard")) === null && $service->claimIntroduction($as($a, "{$retail}/dashboard")) === null,
        'suppression: neither recognised owner is shown the other product\'s introduction');
    $recognition = DB::table('product_recognitions')->where('request_id', $request->id)->first();
    $foreign = http('POST', "{$retailPrefs}/revoke", ['recognition_id' => $recognition->id], $b, $jarB)->getStatusCode();
    $held = $live($a);
    http('POST', "{$dhiranPrefs}/revoke", ['recognition_id' => $recognition->id], $d, $jarD);
    check($held === 1 && $live($a) === 0 && $live($d) === 0, 'revoke: another shop\'s owner cannot remove it; either party can, and it ends for both', "{$foreign}, then A {$live($a)} D {$live($d)}");

    // ── lifecycle: a security change withdraws consent in the same save ──────
    http('POST', "{$retailPrefs}/start", ['password' => $passwords['RETAIL-A'], 'consent' => '1'], $a, $jarA);
    $shown = $code();
    $request = $requests($a)->whereNull('consumed_at')->first();
    http('POST', "{$dhiranPrefs}/approve", ['password' => $passwords['DHIRAN-D'], 'consent' => '1', 'code' => $shown], $d, $jarD);
    http('POST', "{$retailPrefs}/finish", ['password' => $passwords['RETAIL-A'], 'consent' => '1', 'request_id' => $request?->id], $a, $jarA);
    $again = $live($a);
    $fresh = User::withoutGlobalScopes()->findOrFail($d->id);
    TenantContext::runFor((int) $d->shop_id, fn () => $fresh->forceFill(['password' => Hash::make(Str::random(24))])->save());
    check($again === 1 && $live($a) === 0 && $live($d) === 0, 'lifecycle: changing the Dhiran owner\'s password ends the recognition for both, in that save', "before {$again}, after {$live($a)}/{$live($d)}");

    // ── what this run wrote, while it can still be seen ──────────────────────
    $touched = changed($tablesBefore, tables());
    $xidEnd = DB::selectOne('select txid_current() as x')->x;
    $level = DB::transactionLevel();
} catch (Throwable $e) {
    $failures++;
    printf("FAIL  the run stopped: %s: %s (%s:%d)\n", $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine());
} finally {
    DB::rollBack();
    TenantContext::clear();
}

// ── lasting effects: measured, not assumed ──────────────────────────────────
$outside = array_values(array_diff($touched, WRITES_ALLOWED));
check($touched !== [] && $outside === [], 'scope: only test identities and promotion metadata were written', $outside === [] ? implode(', ', $touched) : 'OUTSIDE THE LIST: '.implode(', ', $outside));
check($xidStart !== null && $xidStart === $xidEnd && $level === 1, 'transaction: one transaction from first write to rollback; nothing committed or replaced it', "xid {$xidStart}/{$xidEnd}, level ".var_export($level, true));
$left = changed($tablesBefore, tables());
check($left === [], 'rollback: every table has the row count and content it had before the run', $left === [] ? count($tablesBefore).' tables compared, '.count(array_filter($tablesBefore, fn ($v) => ! str_starts_with($v, '0:'))).' of them holding rows' : implode(', ', $left));
check(array_keys(DB::getConnections()) === [config('database.default')], 'connections: the default database connection is the only one that was opened', implode(', ', array_keys(DB::getConnections())));
$jobs = array_sum(array_map('count', Queue::pushedJobs()));
$notifications = count(Notification::sentNotifications());
$mails = count(app('mail.manager')->mailer()->getSymfonyTransport()->messages());
$calls = Http::recorded()->count();
check($jobs + $notifications + $mails + $calls === 0, 'outbound: nothing was queued, notified, mailed or requested over HTTP', "{$jobs} jobs, {$notifications} notifications, {$mails} mails, {$calls} HTTP calls");

echo "\nLASTING EFFECTS of this run\n";
$sequencesAfter = sequences();
$advanced = [];
foreach (changed($sequencesBefore, $sequencesAfter) as $s) {
    $advanced[] = $s.' +'.($sequencesAfter[$s] - $sequencesBefore[$s]);
}
echo '  sequences advanced (ids consumed, gaps left, not reset): '.($advanced === [] ? 'none' : implode(', ', $advanced))."\n";
$filesAfter = files();
$written = array_map(fn ($f) => str_replace(base_path().'/', '', $f).(isset($filesBefore[$f]) ? ' (changed)' : ' (new)'), changed($filesBefore, $filesAfter));
echo '  files written under storage and bootstrap/cache: '.($written === [] ? 'none' : implode(', ', $written))."\n";
echo "  database rows: none (see rollback above); PostgreSQL keeps the dead row versions and WAL of the rolled-back writes until vacuum\n";
echo "  not exercised: anything deferred until after a commit; browser behaviour\n";

echo $failures === 0 ? "\nTAKEOVER PRODUCTION CHECK PASSED on ".config('app.env')." ({$stamp})\n" : "\n{$failures} CHECK(S) FAILED\n";
$finished = true;
exit($failures === 0 ? 0 : 1);
