<?php

namespace Tests\Feature\Mobile;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The app asks for exactly the permission the website asks for, endpoint by
 * endpoint (web leads; the app never gets a bypass).
 */
class MobileRbacParityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** A signed-in staff member of $shop holding exactly $perms; returns the bearer token. */
    private function staffToken(Shop $shop, array $perms, string $mobile = '9876512345'): string
    {
        $user = TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'staff' . $mobile, 'display_name' => 'Staff', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create(['name' => 'Staff ' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id]);
        });

        // A token without the phone-seat login: many staff in one test would hit the shop's seat limit.
        return $user->createToken('mobile-app')->plainTextToken;
    }

    private function send(?string $token, string $method, string $uri, array $body = [], ?int $shopId = null)
    {
        $this->app['auth']->forgetGuards();
        TenantContext::clear();
        if ($shopId) {
            TenantContext::set($shopId);
        }
        $headers = ['Accept' => 'application/json', 'X-Idempotency-Key' => 'rbac-' . Str::uuid()];
        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $this->json($method, $uri, $body, $headers);
    }

    /** A pending return on a draft sale of $shop, written directly (only its id matters here). */
    private function pendingReturn(User $owner, Shop $shop): int
    {
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);
        $invoice = TenantContext::runFor($shop->id, function () use ($owner, $customer, $item) {
            $this->actingAs($owner);

            return \App\Services\RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$item->id]);
        });

        return \Illuminate\Support\Facades\DB::table('return_orders')->insertGetId([
            'shop_id' => $shop->id, 'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'return_type' => \App\Models\ReturnOrder::TYPE_CUSTOMER_RETURN,
            'status' => 'pending_approval', 'created_by_user_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_staff_see_todays_rates_and_notices_but_money_figures_need_reports(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $owner, ['gold_24k_rate_per_gram' => 7350, 'silver_999_rate_per_gram' => 96.5]);
        $cashier = $this->staffToken($shop, ['sales.view', 'sales.create', 'sales.pos', 'repairs.view']);
        $ownerToken = $this->send(null, 'POST', '/api/mobile/auth/login', ['mobile_number' => $owner->mobile_number, 'password' => 'password'])->json('token');

        $staffView = $this->send($cashier, 'GET', '/api/mobile/dashboard', [], $shop->id)->assertOk();
        $staffView->assertJsonPath('metal_rates.gold', 7350)->assertJsonPath('figures_visible', false)
            ->assertJsonPath('today.revenue', null)->assertJsonPath('today.profit', null)->assertJsonPath('today.invoices', 0)
            ->assertJsonPath('trend', []);
        $this->assertIsArray($staffView->json('alerts.warnings'));

        $this->send($ownerToken, 'GET', '/api/mobile/dashboard', [], $shop->id)->assertOk()
            ->assertJsonPath('figures_visible', true)->assertJsonStructure(['today' => ['revenue', 'profit', 'invoices']]);

        // The website dashboard: the same rule.
        $staff = User::where('mobile_number', '9876512345')->first();
        TenantContext::set($shop->id);
        // Unescaped: the page writes "Today's" with a plain apostrophe.
        $this->actingAs($staff)->get('/dashboard')->assertOk()
            ->assertSee("Today's Metal Rates", false)->assertSee('Recent Invoices', false)
            ->assertDontSee("Today's Revenue", false)->assertDontSee("Today's Profit", false)->assertDontSee('Top Customers', false);
        TenantContext::set($shop->id);
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee("Today's Revenue", false)->assertSee('Top Customers', false);
    }

    public function test_capabilities_follow_the_websites_permissions_and_say_why_one_is_off(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $cashier = $this->staffToken($shop, ['sales.view', 'sales.create', 'sales.pos', 'customers.view', 'customers.create', 'repairs.view', 'repairs.create']);

        $caps = $this->send($cashier, 'GET', '/api/mobile/bootstrap', [], $shop->id)->assertOk()->json('data.capabilities')
            ?? $this->send($cashier, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('capabilities');
        $expected = [
            // The scanner is the counter's too (sales.pos), as the web POS's tag lookup.
            'catalog' => false, 'stock' => false, 'scanner' => true, 'suppliers' => false, 'dashboard' => false,
            'quick_bill' => true, 'quick_bill_create' => true, 'customers_create' => true, 'repairs_create' => true,
            'items_edit' => false, 'pricing' => false, 'installments' => true,
        ];
        $actual = array_intersect_key($caps, $expected);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);

        $reasons = $this->send($cashier, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('capability_reasons')
            ?? $this->send($cashier, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('data.capability_reasons');
        // Which permission is never sent: staff are not told why.
        $this->assertSame(['reason' => 'permission'], $reasons['catalog']);
        $this->assertArrayNotHasKey('quick_bill', $reasons, 'only refused capabilities carry a reason');

        $ownerToken = $this->send(null, 'POST', '/api/mobile/auth/login', ['mobile_number' => $owner->mobile_number, 'password' => 'password'])->json('token');
        $ownerCaps = $this->send($ownerToken, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('capabilities')
            ?? $this->send($ownerToken, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('data.capabilities');
        $this->assertTrue($ownerCaps['catalog'] && $ownerCaps['stock'] && $ownerCaps['dashboard'] && $ownerCaps['items_edit'] && $ownerCaps['pricing']);
    }

    public function test_each_app_capability_switches_off_and_on_with_its_web_permission(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $map = [
            'items' => 'inventory.view', 'stock' => 'inventory.view', 'purchases' => 'inventory.view', 'items_create' => 'inventory.create',
            'items_edit' => 'inventory.edit', 'customers' => 'customers.view', 'customers_create' => 'customers.create',
            'suppliers' => 'vendors.view', 'suppliers_manage' => 'vendors.manage', 'pos' => 'sales.pos', 'invoice' => 'sales.view',
            'invoice_payment' => 'sales.create',
            'quick_bill' => 'sales.view', 'quick_bill_create' => 'sales.create', 'installments' => 'sales.view',
            'repairs' => 'repairs.view', 'repairs_create' => 'repairs.create', 'repairs_edit' => 'repairs.edit',
            'catalog' => 'catalog.manage', 'dashboard' => 'reports.view', 'returns_approve' => 'returns.approve',
            'devices' => 'settings.view', 'pricing' => 'pricing.update', 'expenses' => 'cash.view',
        ];
        $caps = function (array $perms, string $mobile) use ($shop) {
            $token = $this->staffToken($shop, $perms, $mobile);

            return $this->send($token, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('capabilities')
                ?? $this->send($token, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('data.capabilities');
        };
        $all = Permission::pluck('name')->all();
        $wrong = [];
        $n = 0;
        foreach (array_unique(array_values($map)) as $permission) {
            $without = $caps(array_values(array_diff($all, [$permission])), '98' . str_pad((string) ++$n, 8, '0', STR_PAD_LEFT));
            $only = $caps([$permission], '97' . str_pad((string) $n, 8, '0', STR_PAD_LEFT));
            foreach (array_keys($map, $permission, true) as $capability) {
                if ($without[$capability] !== false) $wrong[] = "{$capability} stays ON without {$permission}";
                if ($only[$capability] !== true) $wrong[] = "{$capability} stays OFF with only {$permission}";
            }
        }
        // The scanner: either inventory.view or sales.pos.
        $this->assertFalse($caps(array_values(array_diff($all, ['inventory.view', 'sales.pos'])), '9600000001')['scanner'], 'scanner without both');

        $this->assertSame([], $wrong);
    }

    public function test_the_scanner_works_for_pos_staff_like_the_web_pos_tag_lookup(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['barcode' => 'TAG-1', 'status' => 'in_stock', 'cost_price' => 41000, 'selling_price' => 52000]);
        $caps = fn (string $token) => $this->send($token, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('capabilities')
            ?? $this->send($token, 'GET', '/api/mobile/bootstrap', [], $shop->id)->json('data.capabilities');

        // ON: Access POS alone (the web's /api/item-by-barcode asks for sales.pos).
        $cashier = $this->staffToken($shop, ['sales.pos']);
        $this->assertTrue($caps($cashier)['scanner']);
        $tag = $this->send($cashier, 'GET', '/api/mobile/items/barcode/TAG-1', [], $shop->id)->assertOk();
        $this->assertEquals(52000, $tag->json('selling_price'));
        // As the web POS lookup: cost price yes (owner 2026-10-31), supplier no.
        $this->assertEquals(41000, $tag->json('cost_price'));
        $this->assertNull($tag->json('vendor_id'));
        $this->assertNull($tag->json('karigar_id'));
        $this->send($cashier, 'GET', '/api/mobile/items/barcodes', [], $shop->id)->assertOk();

        // ON: View Inventory alone: the full stock view, cost included.
        $stockist = $this->staffToken($shop, ['inventory.view'], '9876512346');
        $this->assertTrue($caps($stockist)['scanner']);
        $this->assertEquals(41000, $this->send($stockist, 'GET', '/api/mobile/items/barcode/TAG-1', [], $shop->id)->assertOk()->json('cost_price'));

        // OFF: neither.
        $other = $this->staffToken($shop, ['sales.view'], '9876512347');
        $this->assertFalse($caps($other)['scanner']);
        $this->send($other, 'GET', '/api/mobile/items/barcode/TAG-1', [], $shop->id)->assertForbidden();
        $this->send($other, 'GET', '/api/mobile/items/barcodes', [], $shop->id)->assertForbidden();
    }

    public function test_owner_only_screens_refuse_staff_plainly(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $manager = $this->staffToken($shop, ['pricing.update', 'settings.view', 'settings.edit']);
        $plain = \App\Support\NotAvailable::MESSAGE;

        $this->send($manager, 'GET', '/api/mobile/pricing/purity-rates', [], $shop->id)->assertForbidden()->assertJsonPath('message', $plain);
        $this->send($manager, 'PATCH', '/api/mobile/shop/access', ['shop_access_enabled' => false], $shop->id)->assertForbidden()->assertJsonPath('message', $plain);
    }

    /** No refusal anywhere in the shop's code explains itself to a staff member. */
    public function test_no_refusal_text_gives_a_reason(): void
    {
        $reasons = '/(Only the shop owner|Only an owner|only the owner|owner or (a )?manager can|do not have permission|don\'t have permission|requires the [^.]{0,60}permission|different account or role|cannot be managed from staff management)/';
        $hits = [];
        foreach ([app_path('Http'), app_path('Services'), resource_path('views')] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
                if ($file->getExtension() !== 'php') continue;
                foreach (file($file->getPathname()) as $n => $line) {
                    if (preg_match($reasons, $line) && ! preg_match('#^\s*(//|\*|/\*|\{\{--)#', $line)) {
                        $hits[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($n + 1);
                    }
                }
            }
        }
        $this->assertSame([], $hits);
    }

    public function test_a_refusal_never_says_why(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $token = $this->staffToken($shop, ['sales.view']);
        $inventory = Permission::where('name', 'inventory.view')->value('display_name');
        $plain = \App\Support\NotAvailable::MESSAGE;

        $this->send($token, 'GET', '/api/mobile/stock', [], $shop->id)->assertForbidden()
            ->assertJsonPath('error', 'permission_denied')->assertJsonPath('message', $plain);
        $this->assertSame($plain, $this->send($token, 'GET', '/api/mobile/v1/returns', [], $shop->id)->assertForbidden()->json('errors.0.message'));

        // The website's refusal page: the same plain words, no permission name.
        $staff = User::where('mobile_number', '9876512345')->first();
        TenantContext::set($shop->id);
        // Escaped: the page prints the message through {{ }} ("isn&#039;t").
        $this->actingAs($staff)->get('/inventory/items')->assertForbidden()->assertSee($plain)->assertDontSee($inventory, false);
    }

    public function test_returns_need_the_websites_returns_permissions_not_sales(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $returnId = $this->pendingReturn($owner, $shop);
        $cashier = $this->staffToken($shop, ['sales.view', 'sales.create']);

        $this->send($cashier, 'GET', '/api/mobile/v1/returns', [], $shop->id)->assertForbidden();
        $this->send($cashier, 'GET', "/api/mobile/v1/returns/{$returnId}", [], $shop->id)->assertForbidden();
        $this->send($cashier, 'POST', '/api/mobile/v1/returns', [], $shop->id)->assertForbidden();

        $clerk = $this->staffToken($shop, ['returns.view', 'returns.create'], '9876512346');
        $this->send($clerk, 'GET', '/api/mobile/v1/returns', [], $shop->id)->assertOk();
        $this->send($clerk, 'GET', "/api/mobile/v1/returns/{$returnId}", [], $shop->id)->assertOk();
        $this->send($clerk, 'POST', '/api/mobile/v1/returns', [], $shop->id)->assertUnprocessable(); // past the gate

        // An approver opens the return to review it, as the web's approve-review page (returns.approve).
        $approver = $this->staffToken($shop, ['returns.approve'], '9876512347');
        $this->send($approver, 'GET', "/api/mobile/v1/returns/{$returnId}", [], $shop->id)->assertOk();
        $this->send($approver, 'GET', '/api/mobile/v1/returns', [], $shop->id)->assertForbidden();
    }
}
