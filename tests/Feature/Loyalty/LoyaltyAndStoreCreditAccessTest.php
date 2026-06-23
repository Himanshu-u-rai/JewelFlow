<?php

namespace Tests\Feature\Loyalty;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Loyalty / Store Credit access & isolation (Module 20). Store-credit balance,
 * the non-negative DB guard, the apply route gate and route registration are
 * already covered (StoreCreditReconnection/StoreCreditApplyUi). This adds the
 * untested surface: the Loyalty screens (no dedicated test existed) and the
 * store-credit owner-only + cross-shop gates.
 */
class LoyaltyAndStoreCreditAccessTest extends TestCase
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

    private function customer(Shop $shop, string $mobile): Customer
    {
        return TenantContext::runFor($shop->id, fn () => Customer::create([
            'shop_id' => $shop->id, 'first_name' => 'Loyal', 'last_name' => 'Cust', 'mobile' => $mobile,
        ]));
    }

    // ── Loyalty (no prior coverage) ────────────────────────────────────────

    public function test_guest_cannot_reach_loyalty(): void
    {
        $res = $this->get(self::ERP . '/loyalty');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    public function test_user_without_customers_view_is_denied_loyalty(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9825500001');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/loyalty'))->assertForbidden();
    }

    public function test_customers_view_user_can_open_loyalty(): void
    {
        // /loyalty is a redirect shim to the customers page's loyalty tab; a
        // permitted user is let through (302 to customers.index, not 403).
        [, $shop] = $this->createRetailerTenant();
        $viewer = $this->userWithPerms($shop, ['customers.view'], '9825500002');

        $res = TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/loyalty'));
        $res->assertRedirect(route('customers.index', ['view' => 'loyalty']));
    }

    public function test_cannot_view_another_shops_loyalty_history(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $custB = $this->customer($shopB, '9825500010');

        [, $shopA] = $this->createRetailerTenant();
        $viewerA = $this->userWithPerms($shopA, ['customers.view'], '9825500011');

        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($viewerA)
            ->get(self::ERP . '/loyalty/' . $custB->id . '/history'));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop loyalty history must be blocked');
    }

    public function test_user_without_customers_edit_cannot_open_loyalty_adjust(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $cust = $this->customer($shop, '9825500020');
        $viewer = $this->userWithPerms($shop, ['customers.view'], '9825500021'); // no customers.edit

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/loyalty/' . $cust->id . '/adjust'))->assertForbidden();
    }

    // ── Store credit: owner-only adjust + cross-shop ───────────────────────

    public function test_non_owner_cannot_open_store_credit_adjust(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $cust = $this->customer($shop, '9825500030');
        // Full perms but NOT the owner role — store-credit adjust is role:owner.
        $manager = $this->userWithPerms($shop, ['customers.view', 'customers.edit', 'sales.create'], '9825500031');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($manager)
            ->get(self::ERP . '/customers/' . $cust->id . '/store-credit/adjust'))->assertForbidden();
    }

    public function test_owner_can_open_store_credit_adjust_for_own_customer(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $cust = $this->customer($shop, '9825500040');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/customers/' . $cust->id . '/store-credit/adjust'))->assertOk();
    }

    public function test_owner_cannot_adjust_another_shops_customer_store_credit(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $custB = $this->customer($shopB, '9825500050');

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/customers/' . $custB->id . '/store-credit/adjust'));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop store-credit must be blocked');
    }

    public function test_guest_cannot_reach_store_credit_adjust(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $cust = $this->customer($shop, '9825500060');

        $res = $this->get(self::ERP . '/customers/' . $cust->id . '/store-credit/adjust');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
