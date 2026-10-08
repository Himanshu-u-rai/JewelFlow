<?php

/**
 * Stabilization batch, checked on the deployed code through the HTTP kernel
 * with a synthetic tenant, inside ONE transaction that is rolled back.
 *
 *   sudo -u www-data env APP_BASE=/var/www/jewelflow-staging php verify_stabilization.php
 *
 * Staging only (or, to debug it, the local test database with
 * VERIFY_LOCAL_TESTING=1). Never production: it refuses.
 *
 * What survives the rollback: sequence values (shops, users, roles, plans,
 * quick bills, tokens and so on advance), a minute of rate-limit counters for
 * a user id that no longer exists, and dead tuples. No row.
 */

use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\QuickBill;
use App\Models\QuickBillPayment;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$base = getenv('APP_BASE') ?: dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->instance('request', Request::create('https://staging.jewelflows.com'));   // production-like boots force https and need one
$kernel = $app->make(HttpKernel::class);
$kernel->bootstrap();

$finished = false;
register_shutdown_function(function () use (&$finished) { if (! $finished) { fwrite(STDERR, "DID NOT FINISH\n"); exit(3); } });

$onStaging = app()->environment('staging') && DB::connection()->getDatabaseName() === 'jewelflow_staging';
$onLocal = getenv('VERIFY_LOCAL_TESTING') === '1' && ! app()->environment('production') && DB::connection()->getDatabaseName() === 'jewelflow_testing';
if (! $onStaging && ! $onLocal) {
    fwrite(STDERR, "REFUSED: runs only on staging (APP_ENV=staging, database jewelflow_staging)\n");
    $finished = true;
    exit(2);
}
config(['mail.default' => 'array', 'queue.default' => 'sync', 'hashing.bcrypt.rounds' => 4]);

$failures = 0;
function check(bool $ok, string $label, string $detail = ''): void
{
    global $failures;
    $failures += $ok ? 0 : 1;
    printf("%-5s %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail === '' ? '' : " — {$detail}");
}

$stamp = strtolower(Str::random(6));
$mobile = fn () => '9'.random_int(100000000, 999999999);
$before = ['shops' => Shop::withoutGlobalScopes()->count(), 'users' => User::withoutGlobalScopes()->count(), 'bills' => QuickBill::withoutGlobalScopes()->count(),
    'claims' => DB::table('idempotency_keys')->count(), 'tokens' => DB::table('personal_access_tokens')->count()];

DB::beginTransaction();
try {
    $admin = PlatformAdmin::create(['first_name' => 'Synth', 'last_name' => $stamp, 'name' => "Synth {$stamp}", 'email' => "synth-{$stamp}@example.invalid",
        'mobile_number' => $mobile(), 'password' => Hash::make(Str::random(24)), 'role' => 'platform_operator', 'is_active' => true,
        'email_verified_at' => now(), 'password_changed_at' => now()->subMinute()]);
    $plan = App\Models\Platform\Plan::whereRaw('is_active is true')->get()->first(fn ($p) => $p->grantsEdition() === 'retailer')
        ?? App\Models\Platform\Plan::create(['code' => "retailer_synth_{$stamp}", 'name' => 'Synth', 'price_monthly' => 999, 'grace_days' => 5,
            'downgrade_to_read_only_on_due' => true, 'is_active' => true]);
    $shop = Shop::create(['name' => "SYNTH-{$stamp}", 'shop_type' => 'retailer', 'phone' => '9000000000', 'owner_first_name' => 'Synth',
        'owner_last_name' => 'Stab', 'owner_mobile' => $mobile(), 'gst_rate' => 3.00, 'wastage_recovery_percent' => 100.00, 'access_mode' => 'active', 'is_active' => true]);
    app(App\Services\TenantRoleService::class)->ensureDefaultsForShop((int) $shop->id);
    $owner = new User;
    $owner->forceFill(['shop_id' => $shop->id, 'role_id' => Role::withoutTenant()->where('shop_id', $shop->id)->where('name', 'owner')->firstOrFail()->id,
        'name' => 'Synth Owner', 'mobile_number' => $mobile(), 'password' => Hash::make(Str::random(24)), 'is_active' => true, 'email_verified_at' => now()])->save();
    ShopSubscription::create(['shop_id' => $shop->id, 'plan_id' => $plan->id, 'status' => 'active', 'starts_at' => now()->subDay()->toDateString(),
        'ends_at' => now()->addMonth()->toDateString(), 'grace_ends_at' => now()->addDays(37)->toDateString(), 'updated_by_admin_id' => $admin->id]);
    (new App\Models\ShopBillingSettings)->forceFill(['shop_id' => $shop->id, 'invoice_prefix' => 'SYN-', 'invoice_start_number' => 1001])->save();
    App\Models\ShopPreferences::withoutTenant()->firstOrNew(['shop_id' => $shop->id])->forceFill(['shop_id' => $shop->id, 'opening_setup_skipped_at' => now()])->save();
    $token = $owner->createToken('verify-stabilization')->plainTextToken;

    $post = function (array $payload, ?string $key) use ($kernel, $token) {
        Auth::forgetGuards();
        TenantContext::clear();
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTPS' => 'on'];
        if ($key !== null) {
            $server['HTTP_X_IDEMPOTENCY_KEY'] = $key;
        }
        $request = Request::create(url('/api/mobile/quick-bills'), 'POST', [], [], [], $server, json_encode($payload));
        app()->instance('request', $request);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    };
    $payload = fn (int $rate) => ['bill_date' => now()->toDateString(), 'pricing_mode' => 'no_gst', 'gst_rate' => 0, 'round_off' => 0, 'save_action' => 'issue',
        'customer_name' => 'Synthetic Walk-in', 'items' => [['description' => 'Synthetic item', 'pcs' => 1, 'gross_weight' => 1, 'net_weight' => 1, 'rate' => $rate]],
        'payments' => [['payment_mode' => 'cash', 'amount' => $rate]]];
    $booked = function () use ($shop): string {
        $ids = QuickBill::withoutGlobalScopes()->where('shop_id', $shop->id)->pluck('id');

        return $ids->count().' bill(s), '.QuickBillPayment::withoutGlobalScopes()->whereIn('quick_bill_id', $ids)->count().' payment(s)';
    };
    $key = 'verify-stab-'.$stamp.'-0001';

    $first = $post($payload(1000), $key);
    $firstBody = json_decode($first->getContent(), true);
    check($first->getStatusCode() === 201, 'quick bill: created with a key', 'status '.$first->getStatusCode().' '.Str::limit((string) ($firstBody['message'] ?? ''), 80));
    $second = $post($payload(1000), $key);
    check($second->getStatusCode() === 201 && $second->headers->get('X-Idempotent-Replay') === 'true'
        && (json_decode($second->getContent(), true)['quick_bill']['id'] ?? 0) === ($firstBody['quick_bill']['id'] ?? -1), 'quick bill: the same request again is a replay of the first', 'status '.$second->getStatusCode());
    check($booked() === '1 bill(s), 1 payment(s)', 'quick bill: one bill and one payment booked', $booked());
    $third = $post($payload(2000), $key);
    check($third->getStatusCode() === 409 && (json_decode($third->getContent(), true)['errors'][0]['code'] ?? '') === 'idempotency_key_conflict', 'quick bill: the key with another payload is refused', 'status '.$third->getStatusCode());
    check($booked() === '1 bill(s), 1 payment(s)', 'quick bill: still one bill and one payment', $booked());
    $legacy = $post($payload(500), null);
    check($legacy->getStatusCode() === 201 && $booked() === '2 bill(s), 2 payment(s)', 'quick bill: a client that sends no key is served as before', 'status '.$legacy->getStatusCode().', '.$booked());

    // The platform-admin sidebar, rendered by the deployed layout for an operator (not a super admin).
    Auth::forgetGuards();
    Auth::guard('platform_admin')->setUser($admin);
    app()->instance('request', Request::create(route('admin.dashboard')));
    $html = Blade::render('<x-super-admin.layout>body</x-super-admin.layout>');
    check(str_contains($html, '<a href="'.route('admin.account.show').'" class="admin-nav-link '), 'admin sidebar: links to Account Security for an operator');
    check(! str_contains($html, route('admin.platform-admins.index')), 'admin sidebar: an operator still does not see Platform Admins');
    Auth::forgetGuards();

    check(! array_key_exists('platform:archive-audit-logs', Illuminate\Support\Facades\Artisan::all()), 'the retired archive command is not registered');
    check(config('session.secure') === true || $onLocal, 'session.secure is true in the running configuration', var_export(config('session.secure'), true));
} catch (Throwable $e) {
    check(false, 'the check ran to its end', get_class($e).': '.$e->getMessage());
} finally {
    DB::rollBack();
}

$after = ['shops' => Shop::withoutGlobalScopes()->count(), 'users' => User::withoutGlobalScopes()->count(), 'bills' => QuickBill::withoutGlobalScopes()->count(),
    'claims' => DB::table('idempotency_keys')->count(), 'tokens' => DB::table('personal_access_tokens')->count()];
check($after === $before, 'nothing is left behind: shops, users, quick bills, idempotency claims and tokens count as before', json_encode($after));
echo "LASTING EFFECTS: sequences advanced; a minute of rate-limit counters; no row.\n";
echo $failures === 0 ? "STABILIZATION CHECK PASSED\n" : "STABILIZATION CHECK FAILED ({$failures})\n";
$finished = true;
exit($failures === 0 ? 0 : 1);
