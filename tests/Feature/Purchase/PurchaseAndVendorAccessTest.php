<?php

namespace Tests\Feature\Purchase;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StockPurchase;
use App\Models\User;
use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Purchases / Suppliers (Module 15). The stock-purchase reversal flow and the
 * purchase-efficiency / dead-stock reports are already covered; this closes the
 * untested HTTP surface: vendor create shop-binding + validation, the
 * cross-shop vendor guard on purchase create, cross-shop vendor / purchase view
 * + reverse isolation, the vendors.* / inventory.* permission gates, and the
 * retailer-edition gate.
 */
class PurchaseAndVendorAccessTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    private function vendor(Shop $shop, string $name = 'Supplier'): Vendor
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $name) {
            $v = new Vendor();
            $v->forceFill(['shop_id' => $shop->id, 'name' => $name, 'is_active' => true])->save();

            return $v;
        });
    }

    private function purchase(Shop $shop): StockPurchase
    {
        return TenantContext::runFor($shop->id, function () use ($shop) {
            $p = new StockPurchase();
            $p->forceFill([
                'shop_id' => $shop->id, 'purchase_number' => 'PO-' . uniqid(),
                'purchase_date' => now()->toDateString(), 'status' => 'confirmed',
            ])->save();

            return $p;
        });
    }

    // ── Vendor create + validation ─────────────────────────────────────────

    public function test_vendor_create_forces_auth_shop_id(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/vendors', ['name' => 'Mumbai Bullion', 'shop_id' => 999999]))
            ->assertRedirect();

        $v = Vendor::withoutGlobalScopes()->where('name', 'Mumbai Bullion')->first();
        $this->assertNotNull($v);
        $this->assertSame($shop->id, $v->shop_id, 'shop_id from auth, never request body');
    }

    public function test_vendor_create_validation_requires_name(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/vendors', ['name' => '']))
            ->assertSessionHasErrors('name');
    }

    public function test_vendor_index_renders(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->vendor($shop);
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/vendors'))->assertOk();
    }

    // ── Tenant isolation ───────────────────────────────────────────────────

    public function test_cannot_view_another_shops_vendor(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $vendorB = $this->vendor($shopB, 'Foreign Supplier');

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/vendors/' . $vendorB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop vendor must not be viewable');
    }

    public function test_cannot_edit_another_shops_vendor(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $vendorB = $this->vendor($shopB, 'Foreign Supplier 2');

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/vendors/' . $vendorB->id . '/edit'));
        $this->assertContains($res->getStatusCode(), [403, 404]);
    }

    public function test_cannot_view_another_shops_purchase(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $purchaseB = $this->purchase($shopB);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/inventory/purchases/' . $purchaseB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop purchase must not be viewable');
    }

    public function test_cannot_reverse_another_shops_purchase(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $purchaseB = $this->purchase($shopB);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->patch(self::ERP . '/inventory/purchases/' . $purchaseB->id . '/reverse'));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop reverse must be blocked');
        $this->assertSame('confirmed', $purchaseB->fresh()->status, 'shop B purchase untouched');
    }

    public function test_purchase_create_rejects_another_shops_vendor(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $vendorB = $this->vendor($shopB, 'Cross Vendor');

        [$ownerA, $shopA] = $this->createRetailerTenant();
        TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . '/inventory/purchases', [
                'vendor_id' => $vendorB->id, // cross-shop vendor
                'purchase_date' => now()->toDateString(),
            ]))->assertSessionHasErrors('vendor_id');

        $this->assertSame(0, StockPurchase::withoutGlobalScopes()->where('shop_id', $shopA->id)->count());
    }

    // ── Permission & edition gating ────────────────────────────────────────

    public function test_user_without_vendors_view_is_denied(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9821100010');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/vendors'))->assertForbidden();
    }

    public function test_user_without_vendors_manage_cannot_create(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $viewer = $this->userWithPerms($shop, ['vendors.view'], '9821100011'); // view only

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/vendors/create'))->assertForbidden();
    }

    public function test_user_without_inventory_view_cannot_open_purchases(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['vendors.view'], '9821100012'); // no inventory.view

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/inventory/purchases'))->assertForbidden();
    }

    public function test_manufacturer_edition_cannot_reach_vendors(): void
    {
        // /vendors and /inventory/purchases are inside an edition:retailer group.
        [$owner, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/vendors'))->assertForbidden();
    }

    public function test_guest_cannot_reach_vendors(): void
    {
        $res = $this->get(self::ERP . '/vendors');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
