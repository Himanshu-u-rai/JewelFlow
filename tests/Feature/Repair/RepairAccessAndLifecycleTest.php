<?php

namespace Tests\Feature\Repair;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Repairs (Module 13). The metal-type validation, delivered-visibility filter
 * and image-upload flows are already covered; this closes the untested HTTP
 * surface: repair create shop-scoping, the cross-shop customer guard, cross-shop
 * repair view/update/deliver isolation, the deliver cash collection +
 * double-deliver guard, and the repairs.* permission gates.
 */
class RepairAccessAndLifecycleTest extends TestCase
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

    private function createRepair(User $owner, Shop $shop, Customer $customer): Repair
    {
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/repairs', [
                'customer_id' => $customer->id,
                'item_description' => 'Gold chain clasp',
                'gross_weight' => 5.0,
            ]))->assertRedirect();

        return Repair::withoutGlobalScopes()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
    }

    // ── Create: shop-scoping + cross-shop customer guard ───────────────────

    public function test_repair_create_is_shop_scoped_to_its_customer(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $repair = $this->createRepair($owner, $shop, $customer);
        $this->assertSame($shop->id, $repair->shop_id);
        $this->assertSame($customer->id, $repair->customer_id);
    }

    public function test_repair_create_rejects_another_shops_customer(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $custB = $this->createCustomer($shopB->id);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . '/repairs', [
                'customer_id' => $custB->id, // cross-shop customer
                'item_description' => 'Ring', 'gross_weight' => 3.0,
            ]))->assertSessionHasErrors('customer_id');

        $this->assertSame(0, Repair::withoutGlobalScopes()->where('customer_id', $custB->id)->count());
    }

    // ── Tenant isolation ───────────────────────────────────────────────────

    public function test_cannot_view_another_shops_repair(): void
    {
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $repairB = $this->createRepair($ownerB, $shopB, $this->createCustomer($shopB->id));

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/repairs/' . $repairB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop repair must not be viewable');
    }

    public function test_cannot_deliver_another_shops_repair(): void
    {
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $repairB = $this->createRepair($ownerB, $shopB, $this->createCustomer($shopB->id));

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . '/repairs/' . $repairB->id . '/deliver', ['amount' => 500]));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop deliver must be blocked');
    }

    // ── Delivery: cash collection + double-deliver guard ───────────────────

    public function test_deliver_collects_cash_and_blocks_double_deliver(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->createRepair($owner, $shop, $this->createCustomer($shop->id));

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/repairs/' . $repair->id . '/deliver', ['amount' => 500, 'include_gst' => 0]))
            ->assertRedirect();

        $this->assertSame('delivered', $repair->fresh()->status);
        $cashCount = CashTransaction::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('type', 'in')->where('description', 'like', 'Repair delivery%')->count();
        $this->assertSame(1, $cashCount, 'one cash-in row for the delivery');

        // Second delivery is refused (status already 'delivered') — no second cash row.
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/repairs/' . $repair->id . '/deliver', ['amount' => 500, 'include_gst' => 0]))
            ->assertSessionHas('error');

        $this->assertSame(1, CashTransaction::withoutGlobalScopes()->where('shop_id', $shop->id)
            ->where('type', 'in')->where('description', 'like', 'Repair delivery%')->count(), 'no duplicate cash on double-deliver');
    }

    public function test_repair_index_and_show_render(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->createRepair($owner, $shop, $this->createCustomer($shop->id));

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/repairs'))->assertOk();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/repairs/' . $repair->id))->assertOk();
    }

    // ── Permission gating ──────────────────────────────────────────────────

    public function test_user_without_repairs_view_is_denied(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9818800010');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/repairs'))->assertForbidden();
    }

    public function test_user_without_repairs_create_cannot_store(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $viewer = $this->userWithPerms($shop, ['repairs.view'], '9818800011'); // view only

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->post(self::ERP . '/repairs', [
                'customer_id' => $customer->id, 'item_description' => 'X', 'gross_weight' => 1.0,
            ]))->assertForbidden();
    }

    public function test_user_without_repairs_edit_cannot_deliver(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $repair = $this->createRepair($owner, $shop, $this->createCustomer($shop->id));
        $viewer = $this->userWithPerms($shop, ['repairs.view'], '9818800012'); // no repairs.edit

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->post(self::ERP . '/repairs/' . $repair->id . '/deliver', ['amount' => 500]))->assertForbidden();
    }

    public function test_guest_cannot_reach_repairs(): void
    {
        $res = $this->get(self::ERP . '/repairs');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
