<?php

namespace Tests\Feature\Dhiran;

use App\Models\Customer;
use App\Models\Dhiran\DhiranSettings;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\ShopEdition;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Dhiran borrower creation + customer isolation (audit fix).
 *
 * Dhiran shares the customers table but must be strictly shop-scoped: a Dhiran
 * shop creates/sees only its own borrowers, never ERP or another Dhiran shop's.
 * Inline borrower creation is Dhiran-scoped; loan attachment is IDOR-protected.
 */
class DhiranCustomerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const DHIRAN = 'https://dhiran.jewelflows.com';

    /** A Dhiran shop (module enabled) + an owner user with full perms. */
    private function dhiranShopWithOwner(string $mobile = '9390000060'): array
    {
        $shop = Shop::create([
            'name' => 'Pawn Co', 'shop_type' => 'dhiran', 'phone' => '9990000060',
            'owner_first_name' => 'O', 'owner_last_name' => 'W', 'owner_mobile' => '9990000060',
            'country' => 'India', 'gst_rate' => 3.0, 'wastage_recovery_percent' => 0,
        ]);
        DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);

        $role = new Role();
        $role->forceFill(['name' => 'owner', 'display_name' => 'Owner', 'shop_id' => $shop->id]);
        $role->save();
        $role->permissions()->sync(Permission::query()->pluck('id'));

        $owner = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran',
            'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
        ]);

        return [$shop, $owner];
    }

    private function customerFor(Shop $shop, string $mobile, string $first = 'Cust'): Customer
    {
        return TenantContext::runFor($shop->id, fn () => Customer::create([
            'first_name' => $first, 'last_name' => 'Omer', 'mobile' => $mobile,
        ]));
    }

    // 1. Dhiran shop can create its own borrower.
    public function test_dhiran_shop_can_create_borrower(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner();

        $res = $this->actingAs($owner)->postJson(self::DHIRAN.'/dhiran/customers', [
            'first_name' => 'Ramesh', 'last_name' => 'Sharma', 'mobile' => '9811112222',
            'pan' => 'ABCDE1234F', 'address' => '12 Market Rd',
        ]);
        $res->assertStatus(201)->assertJsonPath('ok', true);

        $c = Customer::withoutGlobalScope('shop')->where('mobile', '9811112222')->first();
        $this->assertNotNull($c);
        // 2. Created under the current Dhiran shop.
        $this->assertSame($shop->id, $c->shop_id);
        // 3. customer_code generated.
        $this->assertNotEmpty($c->customer_code);
    }

    // 4. Dhiran new loan can use an own-shop customer.
    public function test_dhiran_loan_accepts_own_shop_customer(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner();
        $customer = $this->customerFor($shop, '9811113333');

        $res = $this->actingAs($owner)->post(self::DHIRAN.'/dhiran', [
            'customer_id' => $customer->id,
            'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [[
                'description' => 'Chain', 'purity' => 22, 'gross_weight' => 50,
                'rate_per_gram_at_pledge' => 6000,
            ]],
        ]);
        // Redirects to the new loan (not a validation bounce).
        $res->assertRedirect();
        $this->assertDatabaseHas('dhiran_loans', ['shop_id' => $shop->id, 'customer_id' => $customer->id]);
    }

    // 5. Dhiran loan rejects an ERP-shop customer id.
    public function test_dhiran_loan_rejects_erp_customer(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner();
        [$erpOwner, $erpShop] = $this->createRetailerTenant();
        $erpCustomer = $this->customerFor($erpShop, '9811114444');

        $this->actingAs($owner)->post(self::DHIRAN.'/dhiran', [
            'customer_id' => $erpCustomer->id,
            'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'C', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
        ])->assertSessionHasErrors('customer_id');

        $this->assertDatabaseMissing('dhiran_loans', ['customer_id' => $erpCustomer->id]);
    }

    // 6. Dhiran loan rejects another Dhiran shop's customer id.
    public function test_dhiran_loan_rejects_other_dhiran_shop_customer(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner('9390000061');
        [$shopB] = $this->dhiranShopWithOwner('9390000062');
        $other = $this->customerFor($shopB, '9811115555');

        $this->actingAs($owner)->post(self::DHIRAN.'/dhiran', [
            'customer_id' => $other->id,
            'principal_amount' => 10000, 'gold_rate_on_date' => 6000,
            'items' => [['description' => 'C', 'purity' => 22, 'gross_weight' => 50, 'rate_per_gram_at_pledge' => 6000]],
        ])->assertSessionHasErrors('customer_id');
    }

    // 7. Dhiran customer dropdown shows only the current shop's customers.
    public function test_dhiran_create_lists_only_own_shop_customers(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner();
        $mine  = $this->customerFor($shop, '9811116666', 'MineBorrower');
        [$shopB] = $this->dhiranShopWithOwner('9390000063');
        $theirs = $this->customerFor($shopB, '9811117777', 'TheirBorrower');

        $response = $this->actingAs($owner)->get(self::DHIRAN.'/dhiran/create');
        $response->assertOk();
        $response->assertSee('MineBorrower');
        $response->assertDontSee('TheirBorrower');
    }

    // 8 & 9. ERP customers don't appear in Dhiran; Dhiran customers don't appear in ERP.
    public function test_customers_are_isolated_between_erp_and_dhiran(): void
    {
        [$shop] = $this->dhiranShopWithOwner();
        $dhiranCustomer = $this->customerFor($shop, '9811118888', 'DhiranBorrower');
        [$erpOwner, $erpShop] = $this->createRetailerTenant();
        $erpCustomer = $this->customerFor($erpShop, '9811119999', 'ErpBuyer');

        // ERP shop sees only its own.
        $erpVisible = TenantContext::runFor($erpShop->id, fn () => Customer::pluck('id')->all());
        $this->assertContains($erpCustomer->id, $erpVisible);
        $this->assertNotContains($dhiranCustomer->id, $erpVisible);

        // Dhiran shop sees only its own.
        $dhVisible = TenantContext::runFor($shop->id, fn () => Customer::pluck('id')->all());
        $this->assertContains($dhiranCustomer->id, $dhVisible);
        $this->assertNotContains($erpCustomer->id, $dhVisible);
    }

    // 10. Same mobile can exist separately under an ERP shop and a Dhiran shop.
    public function test_same_mobile_allowed_across_shops(): void
    {
        [$shop] = $this->dhiranShopWithOwner();
        [$erpOwner, $erpShop] = $this->createRetailerTenant();

        $dh  = $this->customerFor($shop, '9800000000');
        $erp = $this->customerFor($erpShop, '9800000000');

        $this->assertNotSame($dh->id, $erp->id);
        $this->assertSame(2, Customer::withoutGlobalScope('shop')->where('mobile', '9800000000')->count());
    }

    // 11. Duplicate mobile in the SAME Dhiran shop returns the existing borrower (no dup).
    public function test_duplicate_mobile_in_same_shop_is_deduped(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner();
        $existing = $this->customerFor($shop, '9822223333', 'Existing');

        $res = $this->actingAs($owner)->postJson(self::DHIRAN.'/dhiran/customers', [
            'first_name' => 'Different Name', 'mobile' => '9822223333',
        ]);
        $res->assertOk()->assertJsonPath('duplicate', true)
            ->assertJsonPath('customer.id', $existing->id);

        $this->assertSame(1, Customer::withoutGlobalScope('shop')
            ->where('shop_id', $shop->id)->where('mobile', '9822223333')->count());
    }

    // 12. A Dhiran-only shop user cannot reach the ERP customer screens.
    public function test_dhiran_user_cannot_access_erp_customer_screens(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner();

        // On the main host the realm gate already bounces a Dhiran user; the
        // edition gate is the in-app defence. Either way it must not be a 200.
        $response = $this->actingAs($owner)->get('https://jewelflows.com/customers');
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // storeCustomer requires a valid mobile.
    public function test_borrower_create_validates_mobile(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner();

        $this->actingAs($owner)->postJson(self::DHIRAN.'/dhiran/customers', [
            'first_name' => 'NoMobile', 'mobile' => '123',
        ])->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    // storeCustomer never trusts a request shop_id.
    public function test_borrower_create_ignores_request_shop_id(): void
    {
        [$shop, $owner] = $this->dhiranShopWithOwner();
        [$erpOwner, $erpShop] = $this->createRetailerTenant();

        $this->actingAs($owner)->postJson(self::DHIRAN.'/dhiran/customers', [
            'first_name' => 'Forced', 'mobile' => '9844445555', 'shop_id' => $erpShop->id,
        ])->assertStatus(201);

        $c = Customer::withoutGlobalScope('shop')->where('mobile', '9844445555')->first();
        $this->assertSame($shop->id, $c->shop_id, 'shop_id must come from the auth shop, never the request.');
    }
}
