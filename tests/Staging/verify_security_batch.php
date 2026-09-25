<?php

/**
 * Staging verification for the security batch — synthetic tenants inside one
 * database transaction that is ROLLED BACK at the end.
 *
 *   cd /var/www/jewelflow-staging && sudo -u www-data php tests/Staging/verify_security_batch.php
 *
 * Refuses unless APP_ENV is staging and the database is jewelflow_staging:
 * it never runs against production. Mail goes to the in-memory mailer and
 * sessions to the array driver for this process only, so nothing is sent and
 * no session row is written. The only effects outside the transaction are
 * files the checks write (an export, two signatures), deleted at the end.
 *
 * Covers, through the deployed code, config and schema: cross-shop reads and
 * writes with guessed ids (web and mobile), an inconsistent stored reference in
 * a report, staff permission grant and revocation, the platform-admin guards
 * (S3-19 legacy URLs, a revoked session) and the bootstrap refusal (S3-21),
 * payment-route idempotency (replay, duplicate, conflicting payload), a queued
 * export with its notification and download isolation, signature snapshots on
 * a reprint, tenant-context cleanup, loyalty expiry inactive, and private
 * files not served by nginx.
 */

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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
config(['mail.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync']);
Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::except(['*']);   // this process only
app()->instance('request', Request::create((string) config('app.url')));

$failures = 0;
$files = [];
$dirs = [];
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("%-5s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : " — {$detail}");
}

/**
 * One request through the kernel, with a cookie jar per browser. $as is
 * ['web', $user] (session guard) or ['token', $user, $plainTextToken] (a real
 * Sanctum personal access token). Tenant context is set first: under the CLI,
 * BelongsToShop does not fall back to the authenticated user.
 */
function http(string $method, string $uri, array $data = [], ?array $as = null, array &$jar = [], array $headers = [], array $files = [])
{
    global $kernel;
    Auth::forgetGuards();
    Auth::shouldUse('web');   // a fresh process starts on the default guard; an earlier API request left sanctum
    TenantContext::clear();
    // Each request is its own browser process in real life: start it with no
    // session state in memory (the array handler keeps each jar's session by id).
    app('session')->driver()->flush();
    $server = ['HTTP_ACCEPT' => str_contains($uri, '/api/') ? 'application/json' : 'text/html'];
    if ($as !== null) {
        TenantContext::set((int) $as[1]->shop_id);
        if ($as[0] === 'web') {
            Auth::guard('web')->setUser($as[1]);
        } else {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$as[2];
        }
    }
    foreach ($headers as $k => $v) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
    }
    // The mobile API takes JSON, and the idempotency fingerprint is the raw
    // body: send it as the app does, not as form parameters.
    $json = str_contains($uri, '/api/') && $method !== 'GET';
    if ($json) {
        $server['CONTENT_TYPE'] = 'application/json';
    }
    $request = Request::create(url($uri), $method, $json ? [] : $data, $jar, $files, $server, $json ? json_encode($data) : null);
    app()->instance('request', $request);
    $response = $kernel->handle($request);
    foreach ($response->headers->getCookies() as $cookie) {
        $jar[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);
    Auth::forgetGuards();

    return $response;
}

function location($response): string
{
    return (string) parse_url((string) $response->headers->get('Location'), PHP_URL_PATH);
}

function probe(string $path): string
{
    $host = parse_url((string) config('app.url'), PHP_URL_HOST);

    return trim((string) shell_exec('curl -sk -o /dev/null -w "%{http_code}" -m 15 --resolve '.escapeshellarg("{$host}:443:127.0.0.1").' '.escapeshellarg("https://{$host}{$path}")));
}

$stamp = now()->format('YmdHis');
$mobile = fn () => '9'.random_int(100000000, 999999999);

DB::beginTransaction();
try {
    // ── synthetic tenants (rolled back) ──────────────────────────────────────
    $adminPassword = Str::random(24);
    $admin = PlatformAdmin::create(['first_name' => 'Synth', 'last_name' => $stamp, 'name' => "Synth {$stamp}", 'email' => "synth-{$stamp}@example.invalid",
        'mobile_number' => $mobile(), 'password' => Hash::make($adminPassword), 'role' => 'super_admin', 'is_active' => true,
        'email_verified_at' => now(), 'password_changed_at' => now()->subMinute()]);
    $plan = App\Models\Platform\Plan::whereRaw('is_active is true')->get()->first(fn ($p) => $p->grantsEdition() === 'retailer')
        ?? App\Models\Platform\Plan::create(['code' => "retailer_synth_{$stamp}", 'name' => 'Synth', 'price_monthly' => 999, 'grace_days' => 5,
            'downgrade_to_read_only_on_due' => true, 'is_active' => true]);   // rolled back with the rest
    $tenant = function (string $tag) use ($stamp, $mobile, $admin, $plan): array {
        $shop = Shop::create(['name' => "SYNTH-{$stamp}-{$tag}", 'shop_type' => 'retailer', 'phone' => '9000000000', 'owner_first_name' => 'Synth',
            'owner_last_name' => $tag, 'owner_mobile' => $mobile(), 'gst_rate' => 3.00, 'wastage_recovery_percent' => 100.00, 'access_mode' => 'active', 'is_active' => true]);
        app(App\Services\TenantRoleService::class)->ensureDefaultsForShop((int) $shop->id);
        $role = fn (string $name) => Role::withoutTenant()->where('shop_id', $shop->id)->where('name', $name)->firstOrFail();
        $user = function (string $roleName) use ($shop, $role, $tag, $mobile): User {
            $u = new User;
            $u->forceFill(['shop_id' => $shop->id, 'role_id' => $role($roleName)->id, 'name' => "Synth {$tag} {$roleName}", 'mobile_number' => $mobile(),
                'password' => Hash::make(Str::random(24)), 'is_active' => true]
                + (Illuminate\Support\Facades\Schema::hasColumn('users', 'email_verified_at') ? ['email_verified_at' => now()] : []))->save();

            return $u;
        };
        $owner = $user('owner');
        $staff = $user('staff');
        ShopSubscription::create(['shop_id' => $shop->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay()->toDateString(),
            'ends_at' => now()->addMonth()->toDateString(), 'grace_ends_at' => now()->addDays(37)->toDateString(), 'updated_by_admin_id' => $admin->id]);
        $billing = new App\Models\ShopBillingSettings;
        $billing->forceFill(['shop_id' => $shop->id, 'invoice_prefix' => 'SYN-', 'invoice_start_number' => 1001])->save();
        App\Models\ShopPreferences::withoutTenant()->firstOrNew(['shop_id' => $shop->id])->forceFill(['shop_id' => $shop->id, 'opening_setup_skipped_at' => now()])->save();
        $customer = new Customer;
        $customer->forceFill(['shop_id' => $shop->id, 'first_name' => "SynthCustomer{$tag}", 'last_name' => $stamp, 'mobile' => $mobile()])->save();

        return compact('shop', 'owner', 'staff', 'customer');
    };
    $a = $tenant('A');
    $b = $tenant('B');
    $tokenA = $a['owner']->createToken('synth-verify')->plainTextToken;
    $jarA = [];
    $jarB = [];

    // ── cross-shop reads and writes with guessed ids ─────────────────────────
    $r = http('GET', "/customers/{$b['customer']->id}", [], ['web', $a['owner']], $jarA);
    $own = http('GET', "/customers/{$a['customer']->id}", [], ['web', $a['owner']], $jarA);
    check($r->getStatusCode() === 404 && $own->getStatusCode() === 200, 'web: another shop\'s customer id 404s; the own customer 200s', $r->getStatusCode().'/'.$own->getStatusCode());
    $r = http('GET', "/api/mobile/v1/customers/{$b['customer']->id}", [], ['token', $a['owner'], $tokenA]);
    $own = http('GET', "/api/mobile/v1/customers/{$a['customer']->id}", [], ['token', $a['owner'], $tokenA]);
    check($r->getStatusCode() === 404 && $own->getStatusCode() === 200, 'mobile: another shop\'s customer id 404s; the own customer 200s', $r->getStatusCode().'/'.$own->getStatusCode());
    $r = http('PUT', "/customers/{$b['customer']->id}", ['first_name' => 'Overwritten', 'last_name' => 'X', 'mobile' => $mobile()], ['web', $a['owner']], $jarA);
    check($r->getStatusCode() === 404 && Customer::withoutTenant()->find($b['customer']->id)->first_name === 'SynthCustomerB',
        'web: writing another shop\'s customer 404s and changes nothing', (string) $r->getStatusCode());

    // ── an inconsistent stored reference in a report (S3-20) ─────────────────
    TenantContext::runFor((int) $a['shop']->id, fn () => Invoice::issue(['shop_id' => $a['shop']->id, 'customer_id' => $b['customer']->id, 'gold_rate' => 7200,
        'subtotal' => 1000, 'gst' => 0, 'total' => 1000, 'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now()]));
    TenantContext::runFor((int) $a['shop']->id, fn () => Invoice::issue(['shop_id' => $a['shop']->id, 'customer_id' => $a['customer']->id, 'gold_rate' => 7200,
        'subtotal' => 1000, 'gst' => 0, 'total' => 1000, 'status' => Invoice::STATUS_FINALIZED, 'finalized_at' => now()]));
    $book = json_encode(TenantContext::runFor((int) $a['shop']->id,
        fn () => app(App\Reporting\LedgerService::class)->dayBook((int) $a['shop']->id, App\Reporting\ReportPeriod::day(now()->toDateString()))));
    check(str_contains($book, 'SynthCustomerA') && ! str_contains($book, 'SynthCustomerB'), 'report: an inconsistent reference brings no other shop\'s customer into the day book');
    TenantContext::clear();

    // ── staff permission, granted then revoked ───────────────────────────────
    $staffRole = Role::withoutTenant()->findOrFail($a['staff']->role_id);
    $perm = App\Models\Permission::where('name', 'settings.edit')->firstOrFail();
    $before = http('GET', '/profile', [], ['web', $a['staff']->fresh()])->getStatusCode();
    $staffRole->permissions()->attach($perm->id);
    $granted = http('GET', '/profile', [], ['web', $a['staff']->fresh()])->getStatusCode();
    $staffRole->permissions()->detach($perm->id);
    $revoked = http('GET', '/profile', [], ['web', $a['staff']->fresh()])->getStatusCode();
    check($before === 403 && $granted === 200 && $revoked === 403, 'staff: settings.edit denied, granted, revoked', "{$before}/{$granted}/{$revoked}");

    // ── platform admin: password-only session, revoked session, bootstrap ────
    $jarAdmin = [];
    $login = http('POST', '/admin/login', ['mobile_number' => $admin->mobile_number, 'password' => $adminPassword], null, $jarAdmin);
    $legacy = http('GET', '/super-admin/shops', [], null, $jarAdmin);
    $current = http('GET', '/admin/shops', [], null, $jarAdmin);
    check(location($login) === '/admin/mfa' && location($legacy) === '/admin/mfa' && location($current) === '/admin/mfa',
        'admin: a password-only session reaches neither /admin nor the legacy /super-admin views (S3-19)', location($login).' '.location($legacy).' '.location($current));
    $admin->forceFill(['password_changed_at' => now()->addMinute()])->save();
    $revokedLegacy = http('GET', "/super-admin/users/{$a['owner']->id}", [], null, $jarAdmin);
    check(location($revokedLegacy) === '/admin/login', 'admin: a session revoked by a password change is signed out on a legacy URL', location($revokedLegacy));
    // ── cookies: this environment's own names, host-only ────────────────────
    // Staging shares the parent domain with production; one set of names let a
    // staging page load replace production's session cookie in the browser.
    $productionNames = ['jewelflows-session', 'jewelflows-platform-admin-session', 'jewelflows-dhiran-session'];
    $cookieProblems = function ($response) use ($productionNames, $onStaging): array {
        $bad = [];
        foreach ($response->headers->getCookies() as $c) {
            if ($c->getDomain() !== null) {
                $bad[] = $c->getName().' domain='.$c->getDomain();
            }
            if ($onStaging && in_array($c->getName(), $productionNames, true)) {
                $bad[] = $c->getName().' is a production name';
            }
        }

        return $bad;
    };
    $tenantPassword = Str::random(24);
    $a['owner']->forceFill(['password' => Hash::make($tenantPassword)])->save();
    $jarTenant = [];
    $in = http('POST', '/login', ['mobile_number' => $a['owner']->mobile_number, 'password' => $tenantPassword], null, $jarTenant);
    $out = http('POST', '/logout', [], null, $jarTenant);
    $bad = array_merge($cookieProblems($login), $cookieProblems($in), $cookieProblems($out));
    // One long-lived process keeps one session store, so the per-route cookie
    // NAME is not observable here (RealmAuthHardeningTest and the real-HTTP
    // probe cover it); scope, and the absence of production's names, are.
    $names = array_unique(array_map(fn ($c) => $c->getName(), array_merge($login->headers->getCookies(), $in->headers->getCookies(), $out->headers->getCookies())));
    $ownNames = [config('session.tenant_cookie'), config('session.platform_admin_cookie'), config('session.dhiran_cookie')];
    check($bad === [] && $in->getStatusCode() === 302 && ! in_array(location($in), ['/login', ''], true) && $out->getStatusCode() === 302
        && (! $onStaging || count(array_filter($ownNames, fn ($n) => str_ends_with($n, '-staging'))) === 3),
        'cookies: tenant login/logout and admin login set no production cookie name and nothing scoped to a parent domain',
        $bad ? implode('; ', $bad) : implode(',', $names).' | login '.$in->getStatusCode().'->'.location($in).' | own: '.implode(',', $ownNames));
    $supers = PlatformAdmin::where('role', 'super_admin')->count();
    $form = http('GET', '/admin/register');
    $post = http('POST', '/admin/register', ['first_name' => 'Late', 'last_name' => 'Boot', 'mobile_number' => $mobile(), 'password' => 'Late-Boot-Pass-1', 'password_confirmation' => 'Late-Boot-Pass-1']);
    check($form->getStatusCode() === 302 && location($form) === '/admin/login' && location($post) === '/admin/login' && PlatformAdmin::where('role', 'super_admin')->count() === $supers,
        'admin: once configured, the bootstrap form redirects and a registration is refused (S3-21)', $form->getStatusCode().' '.location($post));

    // ── payment-route idempotency: replay, duplicate, conflicting payload ────
    $cash = ['type' => 'in', 'amount' => 2500.00, 'source_type' => 'other', 'payment_mode' => 'cash', 'description' => 'synthetic float'];
    $count = fn () => DB::table('cash_transactions')->where('shop_id', $a['shop']->id)->count();
    $key = "synth-{$stamp}-k1";
    $first = http('POST', '/api/mobile/v1/cashbook', $cash, ['token', $a['owner'], $tokenA], $jarA, ['X-Idempotency-Key' => $key]);
    $replay = http('POST', '/api/mobile/v1/cashbook', $cash, ['token', $a['owner'], $tokenA], $jarA, ['X-Idempotency-Key' => $key]);
    $conflict = http('POST', '/api/mobile/v1/cashbook', ['amount' => 9999.00] + $cash, ['token', $a['owner'], $tokenA], $jarA, ['X-Idempotency-Key' => $key]);
    $afterConflict = $count();
    $other = http('POST', '/api/mobile/v1/cashbook', $cash, ['token', $a['owner'], $tokenA], $jarA, ['X-Idempotency-Key' => "synth-{$stamp}-k2"]);
    check($first->getStatusCode() === 201 && $replay->getStatusCode() === 201 && $replay->headers->get('X-Idempotent-Replay') === 'true',
        'payments: a retried key replays the first response without running the controller', $first->getStatusCode().'/'.$replay->getStatusCode());
    check($conflict->getStatusCode() === 409 && str_contains((string) $conflict->getContent(), 'idempotency_key_conflict') && $afterConflict === 1,
        'payments: the same key with a different payload is refused; one cash row', $conflict->getStatusCode().", rows {$afterConflict}");
    check($other->getStatusCode() === 201 && $count() === 2, 'payments: a distinct key records a distinct entry', "rows {$count()}");

    // ── a queued export, its notification, and download isolation ────────────
    [$exportId, $payload] = TenantContext::runFor((int) $a['shop']->id, function () use ($a) {
        $owner = $a['owner'];
        $shop = $a['shop'];
        $definition = app(App\Services\Reporting\Definition\ReportRegistry::class)->definition('customers');
        $period = app(App\Services\Reporting\Filters\FilterResolver::class)->resolve(App\Services\Reporting\Filters\DatePreset::ThisMonth);
        $columns = app(App\Services\Reporting\ColumnPolicy::class)->resolve($definition, App\Services\Reporting\Definition\ReportProfile::Detailed, $owner);
        $request = new App\Services\Reporting\Dataset\ReportRequest(definition: $definition, shopId: (int) $shop->id, userId: (int) $owner->id,
            userName: (string) $owner->name, profile: App\Services\Reporting\Definition\ReportProfile::Detailed,
            format: App\Services\Reporting\Definition\ExportFormat::Csv, filters: ['period' => ['from' => $period->from, 'to' => $period->to]],
            columnKeys: $columns->columnKeys, includeSensitive: false, revealMasked: false);
        $export = app(App\Services\Reporting\ExportAuditService::class)->recordQueued($request, false);

        return [$export->id, ['export_id' => $export->id, 'report_key' => 'customers', 'shop_id' => (int) $shop->id, 'user_id' => (int) $owner->id,
            'user_name' => (string) $owner->name, 'profile' => App\Services\Reporting\Definition\ReportProfile::Detailed->value,
            'format' => App\Services\Reporting\Definition\ExportFormat::Csv->value, 'date_preset' => App\Services\Reporting\Filters\DatePreset::ThisMonth->value,
            'date_from' => $period->from->toIso8601String(), 'date_to' => $period->to->toIso8601String(), 'fy_name' => null,
            'filters' => $request->filters, 'column_keys' => $request->columnKeys, 'include_sensitive' => false, 'reveal_masked' => false,
            'filters_applied' => ['Period' => $period->label], 'watermark' => null,
            'shop' => ['legal_name' => (string) $shop->name, 'address' => null, 'gstin' => null, 'state_code' => null]]];
    });
    App\Jobs\Reporting\GenerateQueuedExportJob::dispatchSync($payload);
    $export = App\Models\Reporting\ReportExport::withoutGlobalScopes()->find($exportId);
    $dirs[] = [$export->file_disk, $export->storageDirectory()];
    $notified = DB::table('notifications')->where('notifiable_id', $a['owner']->id)->whereRaw("(data::jsonb ->> 'export_id') = ?", [(string) $exportId])->count();
    check($export->status === 'done' && $export->notified_at !== null && $notified === 1 && preg_match('#^reporting-exports/\d+/\d+/#', (string) $export->file_path) === 1,
        'exports: finished in its own directory and delivered once', "{$export->status}, {$export->file_path}, notifications {$notified}");
    $link = (new App\Notifications\Reporting\ExportReadyNotification($export))->toDatabase($a['owner'])['download_url'];
    $mine = http('GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY), [], ['web', $a['owner']], $jarA);
    $theirs = http('GET', (string) parse_url($link, PHP_URL_PATH).'?'.parse_url($link, PHP_URL_QUERY), [], ['web', $b['owner']], $jarB);
    check($mine->getStatusCode() === 200 && $theirs->getStatusCode() === 404, 'exports: the requester downloads; another shop\'s user following the link gets 404',
        $mine->getStatusCode().'/'.$theirs->getStatusCode());
    if ($onStaging) {
        // 404, or 403 from the private disk's signed serve route that an
        // unmatched /storage/ path falls through to: either way, not served.
        $served = probe('/storage/'.$export->file_path);
        check(in_array($served, ['403', '404'], true), 'exports: the private file is not served under /storage', $served);
    }

    // ── signature snapshot on a reprint (quick bill) ─────────────────────────
    $upload = function (int $w, int $h) use ($a, &$jarA, &$files): string {
        http('PATCH', route('settings.update.billing'), ['invoice_prefix' => 'SYN-', 'invoice_start_number' => 1001, 'show_digital_signature' => '1'], ['web', $a['owner']], $jarA, [],
            ['digital_signature' => UploadedFile::fake()->image('sig.png', $w, $h)]);
        $row = DB::table('shop_billing_settings')->where('shop_id', $a['shop']->id)->first();
        $files[] = [$row->digital_signature_disk, $row->digital_signature_path];

        return base64_encode(Storage::disk($row->digital_signature_disk)->get($row->digital_signature_path));
    };
    $sigA = $upload(40, 20);
    http('POST', route('quick-bills.store'), ['bill_date' => now()->toDateString(), 'pricing_mode' => 'gst_exclusive', 'gst_rate' => 3, 'save_action' => 'issue',
        'customer_name' => 'Walk-in', 'items' => [['description' => 'Gold chain', 'metal_type' => 'gold', 'net_weight' => 10, 'rate' => 7200, 'line_total' => 1000]],
        'payments' => [['payment_mode' => 'cash', 'amount' => 1030]]], ['web', $a['owner']], $jarA);
    $billId = (int) DB::table('quick_bills')->where('shop_id', $a['shop']->id)->orderByDesc('id')->value('id');
    $sigB = $upload(60, 30);
    http('PATCH', route('settings.update.billing'), ['invoice_prefix' => 'SYN-', 'invoice_start_number' => 1001], ['web', $a['owner']], $jarA);
    $print = (string) http('GET', route('quick-bills.print', $billId), [], ['web', $a['owner']], $jarA)->getContent();
    check($billId > 0 && str_contains($print, $sigA) && ! str_contains($print, $sigB), 'signatures: a bill issued under A still prints A after B replaced it and signatures were switched off');
    check(Storage::disk('public')->exists((string) end($files)[1]) === false, 'signatures: stored on the private disk, not the public one', (string) end($files)[0]);

    // ── tenant context, queue worker cleanup, loyalty ────────────────────────
    check(TenantContext::get() === null, 'tenant context is clear after the requests');
    check(app('events')->hasListeners(Illuminate\Queue\Events\Looping::class), 'the worker clears tenant context between jobs (Looping listener registered)');
    check(config('loyalty.expiry_active_from') === null, 'loyalty expiry is inactive (loyalty.expiry_active_from unset)');

    // ── nginx: probe paths only, never a real customer file ──────────────────
    foreach ($onStaging ? ['/storage/kyc/'.Str::random(12).'.jpg', '/storage/reporting-exports/'.Str::random(12).'.csv', '/storage/signatures/'.Str::random(12).'.webp'] : [] as $path) {
        $code = probe($path);
        check(in_array($code, ['403', '404'], true), "nginx: probe {$path} is not served", $code);
    }
} catch (Throwable $e) {
    check(false, 'verification aborted', get_class($e).': '.$e->getMessage().' @ '.basename($e->getFile()).':'.$e->getLine());
} finally {
    DB::rollBack();
    foreach ($files as [$disk, $path]) {
        if ($disk && $path) {
            Storage::disk($disk)->delete($path);
        }
    }
    foreach ($dirs as [$disk, $dir]) {
        if ($disk && $dir) {
            Storage::disk($disk)->deleteDirectory($dir);
        }
    }
}
echo "\n(transaction rolled back; ".count($files).' signature file(s) and '.count($dirs)." export directory removed)\n";
echo $failures === 0 ? "STAGING VERIFICATION: all checks passed\n" : "STAGING VERIFICATION: {$failures} check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
