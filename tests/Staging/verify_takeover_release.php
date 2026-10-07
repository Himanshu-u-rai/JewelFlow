<?php

/**
 * Staging acceptance for the takeover release (navigation moves, Categories,
 * the landing page, product preferences) — synthetic tenants inside one
 * database transaction that is ROLLED BACK at the end.
 *
 *   cd /var/www/jewelflow-staging && sudo -u www-data php tests/Staging/verify_takeover_release.php
 *
 * Refuses unless APP_ENV is staging and the database is jewelflow_staging: it
 * never runs against production. Mail, sessions, cache (the rate limiter) and
 * the queue are in-memory for this process only, so nothing is sent and
 * nothing is left in the session table or the cache directory. Requests go
 * through the deployed HTTP kernel, routes, middleware, config and schema.
 *
 * Retail realm only. Staging has no Dhiran host and must never be paired with
 * the production one, so the two-product steps (approve on the other product,
 * finish, revoke an established recognition) are NOT RUN here; they were run
 * between two local hosts (tests/js/product-promotion-live.browser.cjs, the
 * race and interleaving harnesses). This script prints them as NOT RUN.
 *
 * What a browser does with the page (Turbo Back, where the toast sits, the
 * touch area of Open POS) cannot be exercised from PHP. This checks the page
 * carries the markup those behaviours hang on and that the built bundle on
 * disk is the one holding them; the behaviours themselves were measured in a
 * real browser against the same commit.
 */

use App\Models\Category;
use App\Models\Permission;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\SubCategory;
use App\Models\User;
use App\Services\ProductPromotionService;
use App\Support\Realm;
use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(HttpKernel::class);
$kernel->bootstrap();

// Staging, or — to debug this script — the local test database by explicit opt-in.
// Never production.
$onStaging = app()->environment('staging') && DB::connection()->getDatabaseName() === 'jewelflow_staging';
$onLocal = getenv('VERIFY_LOCAL_TESTING') === '1' && ! app()->environment('production') && DB::connection()->getDatabaseName() === 'jewelflow_testing';
if (! $onStaging && ! $onLocal) {
    fwrite(STDERR, "REFUSED: runs only on staging (APP_ENV=staging, database jewelflow_staging)\n");
    exit(2);
}
config(['mail.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4]);
Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['*']);   // this process only
app()->instance('request', Request::create((string) config('app.url')));

$failures = 0;
$notRun = [];
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("%-5s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : " — {$detail}");
}

/**
 * One request through the kernel, with a cookie jar per browser. Tenant
 * context is set first: under the CLI, BelongsToShop does not fall back to the
 * authenticated user. (The same helper as verify_security_batch.php.)
 */
function http(string $method, string $uri, array $data = [], ?User $as = null, array &$jar = [], array $headers = [])
{
    global $kernel;
    Auth::forgetGuards();
    Auth::shouldUse('web');
    TenantContext::clear();
    app('session')->driver()->flush();
    $server = ['HTTP_ACCEPT' => 'text/html'];
    if ($as !== null) {
        TenantContext::set((int) $as->shop_id);
        Auth::guard('web')->setUser($as);
    }
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    $request = Request::create(url($uri), $method, $data, $jar, [], $server);
    app()->instance('request', $request);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) {
        $jar[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);
    Auth::forgetGuards();

    return $response;
}

function between(string $html, string $from, string $to): string
{
    $start = strpos($html, $from);
    if ($start === false) {
        return '';
    }
    $end = strpos($html, $to, $start);

    return $end === false ? substr($html, $start) : substr($html, $start, $end - $start);
}

/** href="…" as the page writes it for an address of this application. */
function href(string $path): string
{
    return 'href="'.e(url($path)).'"';
}

/** Categories / Sub-Categories / Need Setup, as the Categories page shows them. */
function totals(string $html): string
{
    preg_match_all('~<p class="categories-kpi-value">\s*([^<]*?)\s*</p>~', $html, $m);

    return implode('/', $m[1]);
}

$stamp = now()->format('YmdHis');
$mobile = fn () => '9'.random_int(100000000, 999999999);
$env = app()->environment();

DB::beginTransaction();
try {
    // ── synthetic tenants (rolled back) ──────────────────────────────────────
    $admin = PlatformAdmin::create(['first_name' => 'Synth', 'last_name' => $stamp, 'name' => "Synth {$stamp}", 'email' => "synth-{$stamp}@example.invalid",
        'mobile_number' => $mobile(), 'password' => Hash::make(Str::random(24)), 'role' => 'super_admin', 'is_active' => true,
        'email_verified_at' => now(), 'password_changed_at' => now()->subMinute()]);
    $plan = App\Models\Platform\Plan::whereRaw('is_active is true')->get()->first(fn ($p) => $p->grantsEdition() === 'retailer')
        ?? App\Models\Platform\Plan::create(['code' => "retailer_synth_{$stamp}", 'name' => 'Synth', 'price_monthly' => 999, 'grace_days' => 5,
            'downgrade_to_read_only_on_due' => true, 'is_active' => true]);
    $passwords = [];
    $tenant = function (string $tag) use ($stamp, $mobile, $admin, $plan, &$passwords): array {
        $shop = Shop::create(['name' => "SYNTH-{$stamp}-{$tag}", 'shop_type' => 'retailer', 'phone' => '9000000000', 'owner_first_name' => 'Synth',
            'owner_last_name' => $tag, 'owner_mobile' => $mobile(), 'gst_rate' => 3.00, 'wastage_recovery_percent' => 100.00, 'access_mode' => 'active', 'is_active' => true]);
        app(App\Services\TenantRoleService::class)->ensureDefaultsForShop((int) $shop->id);
        $user = function (Role $role, string $label) use ($shop, $tag, $mobile, &$passwords): User {
            $u = new User;
            $passwords[$id = "{$tag}:{$label}"] = Str::random(24);
            $u->forceFill(['shop_id' => $shop->id, 'role_id' => $role->id, 'name' => "Synth {$tag} {$label}", 'mobile_number' => $mobile(),
                'password' => Hash::make($passwords[$id]), 'is_active' => true]
                + (Illuminate\Support\Facades\Schema::hasColumn('users', 'email_verified_at') ? ['email_verified_at' => now()] : []))->save();

            return $u;
        };
        $default = fn (string $name) => Role::withoutTenant()->where('shop_id', $shop->id)->where('name', $name)->firstOrFail();
        // A role holding exactly these permissions, as the owner would make one in Settings.
        $restricted = function (string $name, array $permissions) use ($shop, $user): User {
            $role = TenantContext::runFor((int) $shop->id, function () use ($shop, $name, $permissions) {
                $role = new Role;
                $role->forceFill(['shop_id' => $shop->id, 'name' => $name, 'display_name' => ucwords(str_replace('_', ' ', $name))])->save();
                $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id')->all());

                return $role;
            });

            return $user($role, $name);
        };
        $owner = $user($default('owner'), 'owner');
        $staff = $user($default('staff'), 'staff');
        ShopSubscription::create(['shop_id' => $shop->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(), 'grace_ends_at' => now()->addDays(37)->toDateString(), 'updated_by_admin_id' => $admin->id]);
        $billing = new App\Models\ShopBillingSettings;
        $billing->forceFill(['shop_id' => $shop->id, 'invoice_prefix' => 'SYN-', 'invoice_start_number' => 1001])->save();
        App\Models\ShopPreferences::withoutTenant()->firstOrNew(['shop_id' => $shop->id])->forceFill(['shop_id' => $shop->id, 'opening_setup_skipped_at' => now()])->save();

        return compact('shop', 'owner', 'staff', 'restricted');
    };
    $a = $tenant('A');
    $b = $tenant('B');
    $jarA = [];
    $jarB = [];
    $jarGuest = [];
    $shopA = (int) $a['shop']->id;

    // ── the landing page: Retail front door, Dhiran destinations disabled ────
    $landing = http('GET', '/', [], null, $jarGuest);
    $html = (string) $landing->getContent();
    check($landing->getStatusCode() === 200 && str_contains($html, href('/login')) && str_contains($html, href('/register')),
        'landing: 200 with visible Retail log-in and register controls', (string) $landing->getStatusCode());
    check(str_contains($html, 'images/laptopview-960.webp') && str_contains($html, 'images/phoneview-480.webp')
        && is_file(public_path('images/laptopview-960.webp')) && is_file(public_path('images/phoneview-480.webp')),
        'landing: both device illustrations are in the page and on disk');
    check(! str_contains($html, 'Dhiran login') && ! str_contains($html, 'Start with Dhiran') && str_contains($html, 'Ask about Dhiran')
        && ! preg_match('~https?://dhiran\.~i', $html),
        'landing: no Dhiran log-in or register destination is offered (an enquiry link instead); no dhiran.* address in the page');
    $configured = config('platform.cross_promotion.dhiran_register_url');
    check(Realm::dhiranRegisterUrl(request()) === null && ($onLocal || $configured === ''),
        'config: the Dhiran destination resolves to none'.($onStaging ? ', by an explicit empty DHIRAN_REGISTER_URL' : ''), var_export($configured, true));
    check(app(ProductPromotionService::class)->targetUrl(Request::create(url('/dashboard')), $a['owner']) === null,
        'config: a Retail owner on this environment is given no other-product address (no pairing with another environment)');

    // ── navigation: what moved, where it is offered, who may open it ─────────
    $dash = http('GET', '/dashboard', [], $a['owner'], $jarA);
    $sidebar = between((string) $dash->getContent(), 'id="sidebar-nav"', 'class="sidebar-footer"');
    check($dash->getStatusCode() === 200 && $sidebar !== ''
        && ! str_contains($sidebar, href('/tags')) && ! str_contains($sidebar, href('/reorder')) && ! str_contains($sidebar, href('/returns'))
        && str_contains($sidebar, href('/inventory/items')) && str_contains($sidebar, href('/invoices')),
        'owner: the sidebar no longer lists Tag Printing, Reorder Alerts or Returns / Exchange; Jewellery Stock and Invoices stay', (string) $dash->getStatusCode());
    $stock = http('GET', '/inventory/items', [], $a['owner'], $jarA);
    $stockNav = between((string) $stock->getContent(), 'class="items-page-nav', '</nav>');
    check($stock->getStatusCode() === 200 && str_contains($stockNav, href('/categories')) && str_contains($stockNav, href('/tags')) && str_contains($stockNav, href('/reorder')),
        'owner: the Stock page row offers Categories, Tag Printing and Reorder Alerts', (string) $stock->getStatusCode());
    $invoices = http('GET', '/invoices', [], $a['owner'], $jarA);
    $invoicesHtml = (string) $invoices->getContent();
    $invoicesNav = between($invoicesHtml, 'class="invoices-page-nav', '</nav>');
    check($invoices->getStatusCode() === 200 && str_contains($invoicesNav, href('/returns')) && str_contains($invoicesNav, href('/historical'))
        && substr_count($invoicesHtml, href('/returns')) === 1,
        'owner: the Invoices page row offers Historical Sales and Returns / Exchange, each once', (string) $invoices->getStatusCode());
    $back = [];
    foreach (['/categories', '/tags', '/reorder'] as $page) {
        $r = http('GET', $page, [], $a['owner'], $jarA);
        $back[] = $r->getStatusCode().(str_contains(between((string) $r->getContent(), 'content-header', 'content-inner'), href('/inventory/items')) ? ' back' : ' NO-BACK');
    }
    check($back === ['200 back', '200 back', '200 back'], 'owner: Categories, Tag Printing and Reorder Alerts open and each leads back to Stock', implode(', ', $back));

    $returnsOnly = $a['restricted']('synth_returns_only', ['returns.view']);
    $r = http('GET', '/returns', [], $returnsOnly);
    $refused = http('GET', '/invoices', [], $returnsOnly)->getStatusCode();
    check($r->getStatusCode() === 200 && $refused === 403
        && str_contains(between((string) $r->getContent(), 'id="sidebar-nav"', 'class="sidebar-footer"'), href('/returns')),
        'returns-only role: Invoices refuses it, so the sidebar keeps its way into Returns / Exchange, and it opens', $r->getStatusCode().'/'.$refused);
    $salesOnly = $a['restricted']('synth_sales_only', ['sales.view']);
    $r = http('GET', '/invoices', [], $salesOnly);
    $refused = http('GET', '/returns', [], $salesOnly)->getStatusCode();
    check($r->getStatusCode() === 200 && $refused === 403 && ! str_contains((string) $r->getContent(), 'invoices-page-nav') && ! str_contains((string) $r->getContent(), href('/returns')),
        'sales-only role: Invoices opens with no row and no Returns link; Returns refuses it', $r->getStatusCode().'/'.$refused);
    $inventoryOnly = $a['restricted']('synth_inventory_only', ['inventory.view']);
    $codes = array_map(fn ($p) => http('GET', $p, [], $inventoryOnly)->getStatusCode(), ['/inventory/items', '/tags', '/reorder', '/categories', '/invoices']);
    check($codes === [200, 200, 200, 200, 403], 'inventory-only role: Stock and the three pages on its row open; Invoices refuses it', implode('/', $codes));

    // ── Categories: totals, place kept, refusals, another shop's rows ────────
    $made = [];
    TenantContext::runFor($shopA, function () use ($shopA, &$made) {
        foreach (range(1, 21) as $n) {
            $made[$n] = Category::forceCreate(['shop_id' => $shopA, 'name' => sprintf('SynthCat %02d', $n)]);
        }
    });
    $other = TenantContext::runFor((int) $b['shop']->id, fn () => Category::forceCreate(['shop_id' => $b['shop']->id, 'name' => 'OtherShopCat']));
    $pageTwo = url('/categories').'?q=SynthCat&page=2';
    $from = ['Referer' => $pageTwo];

    $r = http('POST', '/categories', ['_intent' => 'add_category', 'name' => 'SynthCat 22'], $a['owner'], $jarA, $from);
    $after = http('GET', '/categories?q=SynthCat&page=2', [], $a['owner'], $jarA);
    $html = (string) $after->getContent();
    check($r->getStatusCode() === 302 && $r->headers->get('Location') === $pageTwo && str_contains($html, 'content="Category created successfully!"')
        && totals($html) === '22/0/22' && str_contains($html, 'SynthCat 22') && ! str_contains($html, 'OtherShopCat'),
        'categories: an accepted save returns to the same search and page, shows its message and the new totals', $r->headers->get('Location').' totals '.totals($html));
    $r = http('POST', '/categories', ['_intent' => 'add_category', 'name' => 'synthcat 01'], $a['owner'], $jarA, $from);
    $html = (string) http('GET', '/categories?q=SynthCat&page=2', [], $a['owner'], $jarA)->getContent();
    check($r->getStatusCode() === 302 && $r->headers->get('Location') === $pageTwo && str_contains($html, 'data-field-error')
        && (bool) preg_match('~id="addCategoryName" value="synthcat 01"~', $html) && totals($html) === '22/0/22',
        'categories: a rejected save returns there with what was typed and its message; totals unchanged', totals($html));
    $r = http('POST', '/sub-categories', ['_intent' => 'add_sub_category', 'category_id' => $made[21]->id, 'name' => 'SynthSub'], $a['owner'], $jarA, $from);
    $html = (string) http('GET', '/categories?q=SynthCat&page=2', [], $a['owner'], $jarA)->getContent();
    check($r->getStatusCode() === 302 && $r->headers->get('Location') === $pageTwo && totals($html) === '22/1/21', 'categories: a new sub-category shows in the totals', totals($html));
    $r = http('DELETE', "/categories/{$made[21]->id}", [], $a['owner'], $jarA, $from);
    $html = (string) http('GET', '/categories?q=SynthCat&page=2', [], $a['owner'], $jarA)->getContent();
    check($r->headers->get('Location') === $pageTwo && str_contains($html, 'Cannot delete this category') && str_contains($html, 'SynthCat 21') && totals($html) === '22/1/21',
        'categories: a delete the server refuses says why, keeps the card and the totals', totals($html));
    $sub = SubCategory::withoutGlobalScopes()->where('category_id', $made[21]->id)->firstOrFail();
    http('DELETE', "/sub-categories/{$sub->id}", [], $a['owner'], $jarA, $from);
    $last = Category::withoutGlobalScopes()->where('shop_id', $shopA)->where('name', 'SynthCat 22')->firstOrFail();
    http('DELETE', "/categories/{$last->id}", [], $a['owner'], $jarA, $from);
    $r = http('DELETE', "/categories/{$made[21]->id}", [], $a['owner'], $jarA, $from);
    $emptied = http('GET', '/categories?q=SynthCat&page=2', [], $a['owner'], $jarA);
    $landed = http('GET', '/categories?q=SynthCat&page=1', [], $a['owner'], $jarA);
    $html = (string) $landed->getContent();
    check($r->headers->get('Location') === $pageTwo && $emptied->getStatusCode() === 302 && $emptied->headers->get('Location') === url('/categories').'?q=SynthCat&page=1'
        && $landed->getStatusCode() === 200 && substr_count($html, 'class="categories-card ') === 20 && str_contains($html, 'content="Category deleted successfully!"') && totals($html) === '20/0/20',
        'categories: deleting the last one on a page lands on the nearest page that exists, with its message', $emptied->getStatusCode().' -> '.$emptied->headers->get('Location').' totals '.totals($html));
    $put = http('PUT', "/categories/{$other->id}", ['name' => 'Overwritten'], $a['owner'], $jarA)->getStatusCode();
    $del = http('DELETE', "/categories/{$other->id}", [], $a['owner'], $jarA)->getStatusCode();
    check(in_array($put, [403, 404], true) && in_array($del, [403, 404], true) && Category::withoutGlobalScopes()->find($other->id)?->name === 'OtherShopCat',
        'tenant isolation: another shop\'s category can be neither renamed nor deleted', "{$put}/{$del}");

    // ── what the browser behaviours hang on ─────────────────────────────────
    $manifest = json_decode((string) @file_get_contents(public_path('build/manifest.json')), true) ?: [];
    $css = (string) @file_get_contents(public_path('build/'.($manifest['resources/css/app.css']['file'] ?? 'missing')));
    $js = (string) @file_get_contents(public_path('build/'.($manifest['resources/js/app.js']['file'] ?? 'missing')));
    check(str_contains($invoicesHtml, 'invoices-open-pos-btn') && str_contains($invoicesHtml, 'id="global-toast"')
        && str_contains($invoicesHtml, 'build/'.($manifest['resources/css/app.css']['file'] ?? 'missing'))
        && str_contains($css, '--toast-space') && str_contains($js, '--toast-space') && (bool) preg_match('~invoices-open-pos-btn::?after\{[^}]*inset:-6px 0~', $css),
        'bundle: the page loads the built stylesheet on disk, and that bundle holds the toast strip and the Open POS touch area', 'css '.strlen($css).' bytes, js '.strlen($js).' bytes');

    // ── product preferences (Retail realm) ──────────────────────────────────
    $prefs = http('GET', '/product-preferences', [], $a['owner'], $jarA);
    $codes = [http('GET', '/product-preferences', [], null, $jarGuest)->getStatusCode(), http('GET', '/product-preferences', [], $a['staff'])->getStatusCode(),
        http('GET', '/dhiran/product-preferences', [], $a['owner'], $jarA)->getStatusCode()];
    check($prefs->getStatusCode() === 200 && str_contains((string) $prefs->getContent(), 'Product preferences')
        && $codes[0] === 302 && $codes[1] === 403 && in_array($codes[2], [302, 403, 404], true),
        'preferences: the owner opens the page; a guest is sent to log in, staff are refused, the other product\'s path is not served here', $prefs->getStatusCode().' / '.implode('/', $codes));
    $row = fn (User $u) => DB::table('product_promotion_preferences')->where(['environment' => $env, 'realm' => 'erp', 'shop_id' => $u->shop_id, 'user_id' => $u->id, 'target' => 'dhiran'])->value('choice');
    // These routes share one budget of six posts a minute per owner; this script makes exactly six as owner A.
    $before = $row($a['owner']);
    $r = http('POST', '/product-preferences/preference', ['choice' => 'opt_out'], $a['owner'], $jarA);
    check($before === null && $r->getStatusCode() === 302 && $row($a['owner']) === 'opt_out' && $row($b['owner']) === null,
        'preferences: "Don\'t show again" is recorded for this owner only', var_export($row($a['owner']), true));
    $requests = fn (User $u) => DB::table('product_recognition_requests')->where('source_user_id', $u->id);
    http('POST', '/product-preferences/start', ['password' => 'not-the-password', 'consent' => '1'], $a['owner'], $jarA);
    $wrong = $requests($a['owner'])->count();
    http('POST', '/product-preferences/start', ['password' => $passwords['A:owner']], $a['owner'], $jarA);
    $noConsent = $requests($a['owner'])->count();
    $r = http('POST', '/product-preferences/start', ['password' => $passwords['A:owner'], 'consent' => '1'], $a['owner'], $jarA);
    $shown = (string) http('GET', '/product-preferences', [], $a['owner'], $jarA)->getContent();
    $request = $requests($a['owner'])->whereNull('consumed_at')->first();
    check($wrong === 0 && $noConsent === 0 && $r->getStatusCode() === 302 && $request !== null && $request->environment === $env
        && (bool) preg_match('~<code[^>]*>\s*[0-9A-F]{40}\s*</code>~', $shown) && ! str_contains($shown, (string) $request->code_hash),
        'recognition: a request needs the owner\'s own password and consent; its code is shown once and only its hash is stored', "wrong {$wrong}, no consent {$noConsent}");
    $foreign = http('POST', '/product-preferences/cancel', ['request_id' => $request->id], $b['owner'], $jarB)->getStatusCode();
    $stillOpen = $requests($a['owner'])->whereNull('consumed_at')->count();
    http('POST', '/product-preferences/cancel', ['request_id' => $request->id], $a['owner'], $jarA);
    check(in_array($foreign, [403, 404], true) && $stillOpen === 1 && $requests($a['owner'])->whereNull('consumed_at')->count() === 0,
        'tenant isolation: another shop\'s owner cannot cancel the request; its own owner can', "{$foreign}, open before/after {$stillOpen}/0");
    http('POST', '/product-preferences/start', ['password' => $passwords['A:owner'], 'consent' => '1'], $a['owner'], $jarA);
    $open = $requests($a['owner'])->whereNull('consumed_at')->count();
    $owner = User::withoutGlobalScopes()->findOrFail($a['owner']->id);
    TenantContext::runFor($shopA, fn () => $owner->forceFill(['password' => Hash::make(Str::random(24))])->save());
    check($open === 1 && $requests($a['owner'])->whereNull('consumed_at')->count() === 0,
        'lifecycle: changing the owner\'s password withdraws their pending request in the same save', "open before/after {$open}/".$requests($a['owner'])->whereNull('consumed_at')->count());
    $notRun[] = 'two-product recognition on staging (approval by a Dhiran owner, finish, revoke, per-product cookies and logout): no Dhiran staging host exists and none is to be created; run between two local hosts instead';
    $notRun[] = 'Turbo Back, toast placement and the Open POS touch area in a signed-in browser on staging: browser-only; measured locally on the same commit, with the bundle identity checked above';
} catch (Throwable $e) {
    $failures++;
    printf("FAIL  the run stopped: %s: %s (%s:%d)\n", $e::class, $e->getMessage(), basename($e->getFile()), $e->getLine());
} finally {
    DB::rollBack();
    TenantContext::clear();
}

$left = Shop::withoutGlobalScopes()->where('name', 'like', "SYNTH-{$stamp}-%")->count();
check($left === 0, 'cleanup: the transaction was rolled back; no synthetic shop remains', (string) $left);
foreach ($notRun as $line) {
    echo "NOT RUN  {$line}\n";
}
echo $failures === 0 ? "\nTAKEOVER RELEASE VERIFIED on ".config('app.env')." ({$stamp})\n" : "\n{$failures} CHECK(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
