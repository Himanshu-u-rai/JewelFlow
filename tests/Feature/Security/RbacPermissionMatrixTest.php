<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Shop;
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
 * Every permission, switched off and on, on every route that asks for it — the
 * website's and the app's alike:
 *   - a staff member holding every permission EXCEPT it is refused (403);
 *   - a staff member holding ONLY it gets past the permission check;
 *   - the owner gets past every check.
 * Findings go to a JSON report (RBAC_OUT) and fail the test.
 */
class RbacPermissionMatrixTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private array $findings = [];
    private array $matrix = [];
    private array $skipped = [];
    private array $alsoNeedsRole = [];
    private array $edition = [];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, \App\Http\Middleware\RateLimitByShop::class]);
    }

    public function test_every_permission_switches_every_route_it_guards(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $ids = $this->fixtures($owner, $shop);

        $byAbility = [];
        foreach (Route::getRoutes() as $route) {
            // Dhiran (pawn loans) is its own sign-in area; shop staff are sent back to theirs first.
            if (in_array('realm:dhiran', $route->gatherMiddleware(), true)) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $mw) {
                if (is_string($mw) && preg_match('/^can:([a-z_]+\.[a-z_.]+)$/', $mw, $m)) {
                    foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                        $byAbility[$m[1]][] = [$method, $route->uri()];
                    }
                }
            }
        }
        ksort($byAbility);

        $ownerToken = $owner->createToken('rbac')->plainTextToken;
        foreach ($byAbility as $ability => $routes) {
            $without = $this->staff($shop, Permission::where('name', '!=', $ability)->pluck('name')->all(), "w-{$ability}");
            $only = $this->staff($shop, [$ability], "o-{$ability}");
            foreach ($routes as [$method, $uri]) {
                $path = $this->fill($uri, $ids);
                if ($path === null) {
                    $this->skipped[] = "{$method} {$uri}";
                    continue;
                }
                $key = "{$ability} | {$method} {$uri}";
                $w = $this->hit($without, $method, $path);
                $o = $this->hit($only, $method, $path);
                $ow = $this->hit([$owner, $ownerToken], $method, $path);
                $this->matrix[$key] = ['without' => $w->getStatusCode(), 'only' => $o->getStatusCode(), 'owner' => $ow->getStatusCode()];

                // Not this shop's kind (a manufacturer page on a retail shop): refused to the owner too, by edition.
                if ($this->editionRefusal($ow)) {
                    $this->edition[] = $key;
                    continue;
                }
                if (! $this->refused($w)) {
                    $this->find('served WITHOUT its permission', $key, $w);
                }
                if ($this->refused($ow)) {
                    $this->find('owner refused', $key, $ow);
                }
                // The permission alone is not enough here: the page also wants the owner or a manager (by design).
                if ($this->refused($o)) {
                    $this->alsoNeedsRole[] = $key;
                }
                if ($w->getStatusCode() >= 500 || $o->getStatusCode() >= 500 || $ow->getStatusCode() >= 500) {
                    $this->find('server error', $key, $w->getStatusCode() >= 500 ? $w : ($o->getStatusCode() >= 500 ? $o : $ow));
                }
            }
        }

        file_put_contents(env('RBAC_OUT', sys_get_temp_dir() . '/rbac-matrix.json'), json_encode([
            'findings' => $this->findings, 'also_needs_owner_or_manager' => $this->alsoNeedsRole, 'edition' => $this->edition,
            'skipped' => $this->skipped, 'matrix' => $this->matrix, 'abilities' => count($byAbility), 'checked' => count($this->matrix),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame([], $this->findings, count($this->findings) . ' RBAC findings; see RBAC_OUT');
    }

    /**
     * A staff member with NO permissions tries every website and app route.
     * Whatever still serves them (or reaches form validation, i.e. no
     * permission check ran first) is listed, for review against the pages any
     * signed-in user may see (their own profile, sign-out, the dashboard…).
     */
    public function test_a_staff_member_with_no_permissions_reaches_only_open_pages(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $ids = $this->fixtures($owner, $shop);
        $nobody = $this->staff($shop, [], 'nobody');

        $served = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            $mw = implode(' ', array_filter($route->gatherMiddleware(), 'is_string'));
            // Platform-admin pages (another login), guest-only pages and public links are not staff pages.
            if (str_starts_with($uri, 'admin') || str_contains($mw, 'guest') || ! str_contains($mw, 'auth')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $path = $this->fill($uri, $ids);
                if ($path === null) {
                    $this->skipped[] = "{$method} {$uri}";
                    continue;
                }
                $res = $this->hit($nobody, $method, $path);
                $s = $res->getStatusCode();
                $toLogin = $res->isRedirect() && str_contains((string) $res->headers->get('Location'), '/login');
                if (($s < 400 && ! $toLogin) || $s === 422 || $s >= 500) {
                    $served[] = ['route' => "{$method} {$uri}", 'status' => $s, 'body' => Str::limit((string) $res->getContent(), 160)];
                }
            }
        }

        file_put_contents(env('RBAC_OUT', sys_get_temp_dir() . '/rbac-nobody.json'), json_encode(
            ['served' => $served, 'skipped' => $this->skipped], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertTrue(true);
    }

    // ── Fixture: one of each record a route may name ─────────────────────────

    private function fixtures(User $owner, Shop $shop): array
    {
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id, ['barcode' => 'TAG-1', 'status' => 'in_stock']);
        $sold = $this->createItem($shop->id, $lot->id, ['barcode' => 'TAG-2', 'status' => 'in_stock']);
        $draft = TenantContext::runFor($shop->id, function () use ($owner, $customer, $sold) {
            $this->actingAs($owner);

            return RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$sold->id]);
        });
        $token = $owner->createToken('fixture')->plainTextToken;
        $plan = $this->hit([$owner, $token], 'POST', '/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id, 'down_payment' => 0, 'total_emis' => 6, 'interest_rate_annual' => 3,
        ], keep: true)->json('data.id');
        $this->hit([$owner, $token], 'POST', '/api/mobile/repairs', [
            'customer_id' => $customer->id, 'item_description' => 'Chain', 'gross_weight' => 5, 'purity' => 22, 'estimated_cost' => 800,
        ], keep: true);
        $this->hit([$owner, $token], 'POST', '/api/mobile/vendors', ['name' => 'Matrix Traders'], keep: true);
        $this->hit([$owner, $token], 'POST', '/api/mobile/quick-bills', [
            'bill_date' => now()->toDateString(), 'pricing_mode' => 'no_gst', 'gst_rate' => 0, 'round_off' => 0, 'save_action' => 'issue',
            'customer_name' => 'Walk-in', 'items' => [['description' => 'Ring', 'pcs' => 1, 'gross_weight' => 1, 'stone_weight' => 0,
                'net_weight' => 1, 'rate' => 1000, 'making_charge' => 0, 'stone_charge' => 0, 'wastage_percent' => 0, 'line_discount' => 0]],
            'payments' => [['payment_mode' => 'cash', 'amount' => 1000]],
        ], keep: true);
        $returnId = DB::table('return_orders')->insertGetId([
            'shop_id' => $shop->id, 'invoice_id' => $draft->id, 'customer_id' => $customer->id, 'return_type' => \App\Models\ReturnOrder::TYPE_CUSTOMER_RETURN,
            'status' => 'pending_approval', 'created_by_user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'customer' => $customer->id, 'item' => $item->id, 'barcode' => 'TAG-1', 'invoice' => $draft->id, 'plan' => $plan,
            'repair' => Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->value('id'),
            'vendor' => Vendor::withoutGlobalScopes()->where('shop_id', $shop->id)->value('id'),
            'quickBill' => DB::table('quick_bills')->where('shop_id', $shop->id)->value('id'),
            'returnOrder' => $returnId, 'lot' => $lot->id, 'metalLot' => $lot->id,
            // Staff management acts on a staff member (the owner's account is never managed there).
            'staff' => ($clerk = $this->staff($shop, ['sales.view'], 'fixture-clerk')[0])->id, 'user' => $clerk->id, 'role' => $clerk->role_id,
        ];
    }

    private function staff(Shop $shop, array $perms, string $tag): array
    {
        $user = TenantContext::runFor($shop->id, function () use ($shop, $perms, $tag) {
            $role = (new Role())->forceFill(['name' => 'rbac-' . substr(md5($tag), 0, 12), 'display_name' => 'Staff', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create(['name' => 'Staff ' . $tag, 'mobile_number' => '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'password' => Hash::make('password'), 'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id]);
        });

        return [$user, $user->createToken('rbac')->plainTextToken];
    }

    // ── Requests ─────────────────────────────────────────────────────────────

    private function fill(string $uri, array $ids): ?string
    {
        $missing = false;
        $path = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($ids, &$missing) {
            if (! isset($ids[$m[1]]) || $ids[$m[1]] === null) {
                $missing = true;

                return '0';
            }

            return (string) $ids[$m[1]];
        }, $uri);

        return $missing ? null : '/' . $path;
    }

    /** One request as $who ([user, token]); rolled back unless $keep, so every probe meets the same shop. */
    private function hit(array $who, string $method, string $path, array $body = [], bool $keep = false): TestResponse
    {
        [$user, $token] = $who;
        $this->app['auth']->forgetGuards();
        TenantContext::clear();
        TenantContext::set((int) $user->shop_id);
        $headers = ['Accept' => 'application/json', 'X-Idempotency-Key' => 'rbac-' . Str::uuid()];
        $call = function () use ($user, $token, $method, $path, $body, $headers) {
            if (str_starts_with($path, '/api/mobile')) {
                return $this->json($method, $path, $body, $headers + ['Authorization' => 'Bearer ' . $token]);
            }

            return $this->actingAs($user, 'web')->json($method, $path, $body, $headers);
        };
        if ($keep) {
            return $call();
        }

        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            return $call();
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }
    }

    /** A permission refusal: 403 from the permission layer (not a closed shop or a disabled feature). */
    private function refused(TestResponse $res): bool
    {
        if ($res->getStatusCode() !== 403) {
            return false;
        }
        $body = json_decode((string) $res->getContent(), true);
        $code = $body['error'] ?? $body['errors'][0]['code'] ?? null;

        return ! in_array($code, ['shop_closed', 'quick_bill_disabled'], true);
    }

    private function editionRefusal(TestResponse $res): bool
    {
        return $res->getStatusCode() === 403 && str_contains((string) $res->getContent(), 'not available for your shop');
    }

    private function find(string $what, string $key, TestResponse $res): void
    {
        $this->findings[] = ['route' => $key, 'finding' => $what, 'status' => $res->getStatusCode(), 'body' => Str::limit((string) $res->getContent(), 240)];
    }
}
