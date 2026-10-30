<?php

namespace Tests\Feature\Mobile;

use App\Models\Permission;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopNotification;
use App\Models\User;
use App\Models\Vendor;
use App\Services\RetailerSalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Break sweep over every /api/mobile route, the way an attacker or a broken
 * client would call it:
 *   - signed out                       → 401 (login and the signed image excepted)
 *   - another shop's owner             → never 2xx on this shop's records, and
 *                                        never this shop's data in any answer
 *   - a user of this shop with no permissions → 403, except self-service routes
 *   - impossible ids (huge, negative, text) and junk bodies → never a 500
 * Every finding goes to a JSON report (SWEEP_OUT); the test fails if any exist.
 */
class MobileApiBreakSweepTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    /** Routes any signed-in user may call: their own session, device and shop status. */
    private const SELF_SERVICE = [
        'POST api/mobile/auth/logout', 'GET api/mobile/auth/me', 'GET api/mobile/bootstrap',
        'GET api/mobile/shop/access', 'POST api/mobile/v1/device/push-token', 'DELETE api/mobile/v1/device/push-token',
        'GET api/mobile/v1/registry/materials', 'POST api/mobile/v1/sessions/lock', 'GET api/mobile/v1/sessions/me',
        'POST api/mobile/v1/sessions/unlock',
        // Today's rates, counts and notices, as the web dashboard; money figures stay withheld (checked below).
        'GET api/mobile/dashboard',
    ];

    /** Unique strings planted in the victim shop's records. */
    private const MARKERS = ['zqvictim', 'zqvbc', 'zqvendor', 'zqrepair', 'zqbill'];

    private array $findings = [];
    private array $matrix = [];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, \App\Http\Middleware\RateLimitByShop::class]);
    }

    public function test_every_mobile_route_survives_the_break_sweep(): void
    {
        [$victim, $victimShop, $victimToken, $ids] = $this->victimShop();
        $outsider = $this->login($this->createRetailerTenant()[0]);
        $noPerm = $this->login($this->userWithPerms($victimShop, [], '9876500001'));

        foreach ($this->mobileRoutes() as [$method, $uri, $params]) {
            $key = "{$method} {$uri}";
            $path = $this->fill($uri, $ids);
            // The sweep's own calls (logout, lock, sign-out-device) can end a session: sign in again.
            $victimToken = $this->alive([$victim, $victimToken])[1];
            $outsider = $this->alive($outsider);
            $noPerm = $this->alive($noPerm);

            // 1. Signed out.
            $guest = $this->hit(null, null, $method, $path);
            $this->matrix[$key]['guest'] = $guest->status();
            $guestOk = $uri === 'api/mobile/auth/login' ? $guest->status() === 422
                : (str_ends_with($uri, '/image') ? $guest->status() === 403 : $guest->status() === 401);
            if (! $guestOk) {
                $this->find('signed-out caller not refused', $key, $guest);
            }
            if ($uri === 'api/mobile/auth/login') {
                continue;
            }

            // 2. Another shop's owner, on this shop's records.
            $out = $this->hit($outsider[0], $outsider[1], $method, $path, $this->bodyFor($uri, $ids));
            $this->matrix[$key]['outsider'] = $out->status();
            if ($params && $out->isSuccessful() && ! $this->tolerated($key, $out)) {
                $this->find('another shop reached this shop\'s record', $key, $out);
            }
            if ($this->leaks($out)) {
                $this->find('another shop sees this shop\'s data', $key, $out);
            }

            // 3. This shop's user with no permissions.
            $none = $this->hit($noPerm[0], $noPerm[1], $method, $path, $this->bodyFor($uri, $ids));
            $this->matrix[$key]['no_permission'] = $none->status();
            if ($none->isSuccessful() && ! in_array($key, self::SELF_SERVICE, true)) {
                $this->find('a user with no permissions was served', $key, $none);
            }
            if ($key === 'GET api/mobile/dashboard' && $none->json('today.revenue') !== null) {
                $this->find('money figures shown without View Reports', $key, $none);
            }

            // 4. Impossible ids: each must be a clean 4xx, never a crash.
            foreach ($params ? ['99999999999999999999', '-1', 'abc', "1'--"] : [] as $bad) {
                $res = $this->hit($victim, $victimToken, $method, $this->fill($uri, array_fill_keys(array_keys($ids), $bad)));
                if ($res->status() >= 500) {
                    $this->find("id \"{$bad}\" crashed the server", $key, $res);
                }
            }

            // 5. Junk bodies from the shop's own owner: refused, never a crash.
            if ($method !== 'GET') {
                foreach ($this->junkBodies() as $label => $junk) {
                    $res = $this->hit($victim, $victimToken, $method, $path, $junk);
                    if ($res->status() >= 500) {
                        $this->find("junk body ({$label}) crashed the server", $key, $res);
                    }
                }
            }
        }

        // 6. Body-referenced records of another shop (ids that never appear in the URL).
        $outsider = $this->alive($outsider);
        foreach ($this->bodyAttacks($ids) as [$method, $uri, $body]) {
            $res = $this->hit($outsider[0], $outsider[1], $method, $uri, $body);
            $this->matrix["{$method} {$uri} (victim ids in body)"]['outsider'] = $res->status();
            if ($res->isSuccessful()) {
                $this->find('another shop used this shop\'s record by id in the body', "{$method} {$uri}", $res);
            }
        }

        file_put_contents(
            env('SWEEP_OUT', sys_get_temp_dir() . '/mobile-break-sweep.json'),
            json_encode(['findings' => $this->findings, 'matrix' => $this->matrix], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        $this->assertSame([], $this->findings, count($this->findings) . ' break-sweep findings; see SWEEP_OUT');
    }

    // ── Fixture ────────────────────────────────────────────────────────────

    private function victimShop(): array
    {
        $this->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
        [$owner, $shop] = $this->createRetailerTenant();
        [, $token] = $this->login($owner);
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id, ['first_name' => 'Zqvictim', 'last_name' => 'Zqvictim']);
        $item = $this->createItem($shop->id, $lot->id, ['barcode' => 'ZQVBC1', 'status' => 'in_stock']);
        $sold = $this->createItem($shop->id, $lot->id, ['barcode' => 'ZQVBC2', 'status' => 'in_stock']);

        $draft = TenantContext::runFor($shop->id, function () use ($owner, $customer, $sold) {
            $this->actingAs($owner);

            return RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$sold->id]);
        });
        $plan = $this->hit($owner, $token, 'POST', '/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id, 'down_payment' => 0, 'total_emis' => 6, 'interest_rate_annual' => 3,
        ], keep: true);
        $this->hit($owner, $token, 'POST', '/api/mobile/repairs', [
            'customer_id' => $customer->id, 'item_description' => 'Zqrepair chain', 'gross_weight' => 5, 'purity' => 22, 'estimated_cost' => 800,
        ], keep: true);
        $this->hit($owner, $token, 'POST', '/api/mobile/vendors', ['name' => 'Zqvendor Traders'], keep: true);
        $bill = $this->hit($owner, $token, 'POST', '/api/mobile/quick-bills', [
            'bill_date' => now()->toDateString(), 'pricing_mode' => 'no_gst', 'gst_rate' => 0, 'round_off' => 0,
            'save_action' => 'issue', 'customer_name' => 'Zqbill Walkin',
            'items' => [['description' => 'Zqbill ring', 'pcs' => 1, 'gross_weight' => 1, 'stone_weight' => 0, 'net_weight' => 1,
                'rate' => 1000, 'making_charge' => 0, 'stone_charge' => 0, 'wastage_percent' => 0, 'line_discount' => 0]],
            'payments' => [['payment_mode' => 'cash', 'amount' => 1000]],
        ], keep: true);
        $note = ShopNotification::withoutGlobalScopes()->create([
            'shop_id' => $shop->id, 'recipient_user_id' => $owner->id, 'type' => 'sale', 'counter_type' => 'pos',
            'actor_name' => 'Zqvictim', 'amount' => 1, 'customer_name' => 'Zqvictim', 'invoice_id' => $draft->id, 'invoice_type' => 'invoice',
        ]);

        $ids = [
            'customer' => $customer->id,
            'item' => $item->id,
            'barcode' => 'ZQVBC1',
            'invoice' => $draft->id,
            'plan' => $plan->json('data.id'),
            'repair' => Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->value('id'),
            'vendor' => Vendor::withoutGlobalScopes()->where('shop_id', $shop->id)->value('id'),
            'quickBill' => $bill->json('id') ?? $bill->json('data.id') ?? DB::table('quick_bills')->where('shop_id', $shop->id)->value('id'),
            'session' => DB::table('mobile_device_sessions')->where('shop_id', $shop->id)->value('id'),
            'id' => $note->id,
            'profile' => \App\Models\ShopMetalPurityProfile::withoutTenant()->where('shop_id', $shop->id)->value('id'),
        ];
        $this->matrix['_fixture'] = array_map(fn ($v) => $v === null ? 'MISSING' : $v, $ids);

        // A record the fixture lacks gets a well-formed id that names nothing.
        return [$owner, $shop, $token, array_map(fn ($v) => $v ?? 999999, $ids)];
    }

    /** The same session if it still works, else a fresh sign-in. */
    private function alive(array $session): array
    {
        $probe = $this->hit($session[0], $session[1], 'GET', '/api/mobile/bootstrap');

        return $probe->isSuccessful() ? $session : $this->login($session[0]);
    }

    private function login(User $user): array
    {
        $res = $this->hit(null, null, 'POST', '/api/mobile/auth/login', [
            'mobile_number' => $user->mobile_number, 'password' => 'password', 'device_name' => 'Sweep phone', 'platform' => 'android',
        ], keep: true);
        $this->assertNotNull($res->json('token'), 'sweep login failed: ' . $res->getContent());

        return [$user, $res->json('token')];
    }

    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'sweep' . $mobile, 'display_name' => 'Sweep', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'Sweep ' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    // ── Requests ───────────────────────────────────────────────────────────

    /** @return list<array{string, string, list<string>}> */
    private function mobileRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/mobile')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [$method, $route->uri(), $route->parameterNames()];
            }
        }
        // Reads first, deletes and logout last, so the victim's records exist while they are probed.
        $rank = fn (array $r) => str_ends_with($r[1], 'logout') ? 9 : ['GET' => 0, 'POST' => 1, 'PUT' => 1, 'PATCH' => 1, 'DELETE' => 2][$r[0]] ?? 3;
        usort($routes, fn ($a, $b) => $rank($a) <=> $rank($b));

        return $routes;
    }

    private function fill(string $uri, array $ids): string
    {
        return '/' . preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => (string) ($ids[$m[1]] ?? 1), $uri);
    }

    /**
     * One request. Probes run inside a savepoint that is rolled back, so every
     * attack meets the same untouched shop (a delete, logout or lock does not
     * carry over) and a failed query cannot poison the next request.
     */
    private function hit(?User $user, ?string $token, string $method, string $path, array $body = [], bool $keep = false): TestResponse
    {
        $this->app['auth']->forgetGuards();
        TenantContext::clear();
        if ($user) {
            TenantContext::set((int) $user->shop_id); // each request ends the tenant context
        }
        $headers = ['Accept' => 'application/json', 'X-Idempotency-Key' => 'sweep-' . Str::uuid()];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        if ($keep) {
            return $this->json($method, $path, $body, $headers);
        }

        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            return $this->json($method, $path, $body, $headers);
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }
    }

    private function bodyFor(string $uri, array $ids): array
    {
        // Enough to get past validation where a write names the record again.
        return ['customer_id' => $ids['customer'], 'item_id' => $ids['item'], 'invoice_id' => $ids['invoice']];
    }

    /** Writes that name another shop's records only in the body. */
    private function bodyAttacks(array $ids): array
    {
        return [
            ['POST', '/api/mobile/pos/quote', ['item_ids' => [$ids['item']], 'customer_id' => $ids['customer']]],
            ['POST', '/api/mobile/pos/preview', ['item_ids' => [$ids['item']], 'items' => [['item_id' => $ids['item']]]]],
            ['POST', '/api/mobile/pos/sell', ['customer_id' => $ids['customer'], 'item_ids' => [$ids['item']], 'payments' => [['mode' => 'cash', 'amount' => 1]]]],
            ['POST', '/api/mobile/quick-bills', ['bill_date' => now()->toDateString(), 'pricing_mode' => 'no_gst', 'gst_rate' => 0, 'round_off' => 0,
                'save_action' => 'issue', 'customer_id' => $ids['customer'],
                'items' => [['description' => 'x', 'pcs' => 1, 'gross_weight' => 1, 'stone_weight' => 0, 'net_weight' => 1, 'rate' => 10,
                    'making_charge' => 0, 'stone_charge' => 0, 'wastage_percent' => 0, 'line_discount' => 0]],
                'payments' => [['payment_mode' => 'cash', 'amount' => 10]]]],
            ['POST', '/api/mobile/repairs', ['customer_id' => $ids['customer'], 'item_description' => 'x', 'gross_weight' => 1, 'purity' => 22, 'estimated_cost' => 1]],
            ['POST', '/api/mobile/v1/returns', ['invoice_id' => $ids['invoice'], 'reason' => 'x', 'refund_settlement' => 'cash', 'lines' => []]],
            ['POST', '/api/mobile/v1/installments/finalize', ['invoice_id' => $ids['invoice'], 'down_payment' => 0, 'total_emis' => 3, 'interest_rate_annual' => 0]],
            ['POST', '/api/mobile/v1/installments/discard-draft', ['invoice_id' => $ids['invoice']]],
            ['POST', '/api/mobile/stock/batch-status', ['item_ids' => [$ids['item']], 'status' => 'damaged']],
            ['POST', '/api/mobile/catalog/collections', ['name' => 'x', 'item_ids' => [$ids['item']]]],
            ['POST', '/api/mobile/v1/cashbook', ['type' => 'in', 'amount' => 1, 'description' => 'x', 'customer_id' => $ids['customer']]],
        ];
    }

    private function junkBodies(): array
    {
        $long = str_repeat('é', 70000);

        return [
            'empty' => [],
            'wrong types' => array_fill_keys(['customer_id', 'item_id', 'invoice_id', 'amount', 'items', 'payments', 'name', 'mobile', 'status', 'barcode', 'item_ids', 'lines', 'rate', 'token'], ['nested' => ['x']]),
            'absurd values' => array_fill_keys(['customer_id', 'item_id', 'invoice_id', 'amount', 'rate', 'gross_weight', 'total_emis', 'down_payment', 'quantity', 'pcs'], '99999999999999999999'),
            // Every money / weight / rate field name the app sends, at an impossible size, top level and in lines.
            'absurd money' => ($huge = array_fill_keys(['amount', 'estimated_cost', 'delivery_cost', 'making_charges', 'making_charge',
                'making_charge_value', 'stone_charges', 'stone_charge', 'selling_price', 'cost_price', 'mrp', 'emi_amount', 'paid_amount',
                'discount_value', 'discount', 'round_off', 'gold_rate', 'silver_rate', 'gold_24k_rate_per_gram', 'silver_999_rate_per_kg',
                'rate_per_gram', 'rate', 'gross_weight', 'net_weight', 'stone_weight', 'purity', 'gst_rate', 'wastage_percent',
                'line_discount', 'opening_balance', 'credit_limit', 'test_loss', 'counted_amount', 'interest_rate_annual', 'down_payment'],
                '9999999999999999999.99')) + [
                    'items' => [$huge + ['description' => 'x', 'pcs' => 1]],
                    'payments' => [['mode' => 'cash', 'payment_mode' => 'cash', 'amount' => '9999999999999999999.99']],
                    'lines' => [$huge],
                ],
            'negative' => array_fill_keys(['amount', 'rate', 'gross_weight', 'net_weight', 'estimated_cost', 'down_payment', 'total_emis', 'pcs'], -5),
            'huge text' => array_fill_keys(['name', 'description', 'item_description', 'notes', 'reason', 'customer_name', 'address', 'mobile', 'barcode', 'first_name'], $long),
        ];
    }

    // ── Judging ────────────────────────────────────────────────────────────

    private function leaks(TestResponse $res): bool
    {
        $body = strtolower((string) $res->getContent());
        foreach (self::MARKERS as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** A 2xx that names nothing of the victim (e.g. a scan of an unknown code). */
    private function tolerated(string $key, TestResponse $res): bool
    {
        return ! $this->leaks($res) && in_array($key, [
            'GET api/mobile/items/barcode/{barcode}', // answers "not found" with 200 on some builds
        ], true);
    }

    private function find(string $what, string $route, TestResponse $res): void
    {
        $this->findings[] = [
            'route' => $route,
            'finding' => $what,
            'status' => $res->status(),
            'body' => Str::limit((string) $res->getContent(), 300),
        ];
    }
}
