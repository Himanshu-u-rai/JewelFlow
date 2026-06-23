<?php

namespace Tests\Feature\Scheme;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InstallmentPlan;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Schemes / EMI / Installments (Module 14). The maturity, cancel/refund,
 * default-close, EMI-draft-sale and mobile-installment flows are already
 * covered; this closes the untested web surface: scheme create shop-scoping,
 * the enroll cross-shop customer guard, cross-shop scheme / enrollment /
 * installment-plan isolation, the catalog.manage / sales.* permission gates,
 * and the retailer-edition gate.
 */
class SchemeAndInstallmentAccessTest extends TestCase
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

    private function scheme(Shop $shop): Scheme
    {
        return TenantContext::runFor($shop->id, function () use ($shop) {
            $s = new Scheme();
            $s->forceFill([
                'shop_id' => $shop->id, 'name' => 'Gold Savings', 'type' => 'gold_savings',
                'start_date' => now()->subMonth()->toDateString(), 'total_installments' => 11, 'is_active' => true,
            ])->save();

            return $s;
        });
    }

    private function enrollment(Shop $shop, Scheme $scheme): SchemeEnrollment
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $scheme) {
            $cust = $this->createCustomer($shop->id);
            $e = new SchemeEnrollment();
            $e->forceFill([
                'shop_id' => $shop->id, 'scheme_id' => $scheme->id, 'customer_id' => $cust->id,
                'start_date' => now()->subMonth()->toDateString(), 'monthly_amount' => 1000,
                'total_paid' => 2000, 'installments_paid' => 2, 'total_installments' => 11, 'status' => 'active',
            ])->save();

            return $e;
        });
    }

    private function installmentPlan(Shop $shop): InstallmentPlan
    {
        return TenantContext::runFor($shop->id, function () use ($shop) {
            $cust = $this->createCustomer($shop->id);
            $inv = new Invoice();
            $inv->forceFill([
                'shop_id' => $shop->id, 'customer_id' => $cust->id, 'invoice_number' => 'INV-EMI-' . $shop->id,
                'status' => Invoice::STATUS_FINALIZED, 'gold_rate' => 6000, 'subtotal' => 12000, 'gst' => 360, 'total' => 12360,
            ])->save();

            $plan = new InstallmentPlan();
            $plan->forceFill([
                'shop_id' => $shop->id, 'invoice_id' => $inv->id, 'customer_id' => $cust->id,
                'total_amount' => 12360, 'remaining_amount' => 12360, 'emi_amount' => 1030, 'total_emis' => 12,
            ])->save();

            return $plan;
        });
    }

    // ── Scheme create + isolation ──────────────────────────────────────────

    public function test_scheme_create_is_shop_scoped(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/schemes', [
                'name' => 'Diwali Dhamaka', 'type' => 'gold_savings',
                'start_date' => now()->toDateString(), 'total_installments' => 11,
            ]))->assertRedirect();

        $s = Scheme::withoutGlobalScopes()->where('name', 'Diwali Dhamaka')->first();
        $this->assertNotNull($s);
        $this->assertSame($shop->id, $s->shop_id, 'scheme shop_id from auth');
    }

    public function test_scheme_index_renders(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->scheme($shop);
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/schemes'))->assertOk();
    }

    public function test_cannot_view_another_shops_scheme(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $schemeB = $this->scheme($shopB);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/schemes/' . $schemeB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop scheme must not be viewable');
    }

    public function test_enroll_rejects_another_shops_customer(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $scheme = $this->scheme($shop);
        [, $shopB] = $this->createRetailerTenant();
        $custB = $this->createCustomer($shopB->id);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/schemes/' . $scheme->id . '/enroll', [
                'customer_id' => $custB->id, // cross-shop
                'monthly_amount' => 1000, 'accept_terms' => 1,
            ]))->assertSessionHasErrors('customer_id');

        $this->assertSame(0, SchemeEnrollment::withoutGlobalScopes()->where('customer_id', $custB->id)->count());
    }

    public function test_cannot_view_another_shops_enrollment(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $enrollB = $this->enrollment($shopB, $this->scheme($shopB));

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/scheme-enrollments/' . $enrollB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop enrollment must not be viewable');
    }

    public function test_cannot_pay_another_shops_enrollment(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $enrollB = $this->enrollment($shopB, $this->scheme($shopB));

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . '/scheme-enrollments/' . $enrollB->id . '/pay', ['amount' => 1000]));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop enrollment pay must be blocked');
    }

    public function test_cannot_view_another_shops_installment_plan(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $planB = $this->installmentPlan($shopB);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/installments/' . $planB->id));
        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop installment plan must not be viewable');
    }

    // ── Permission & edition gating ────────────────────────────────────────

    public function test_user_without_catalog_manage_cannot_open_schemes(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.view'], '9819900010'); // no catalog.manage

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/schemes'))->assertForbidden();
    }

    public function test_user_without_sales_create_cannot_open_enroll(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $scheme = $this->scheme($shop);
        $viewer = $this->userWithPerms($shop, ['catalog.manage', 'sales.view'], '9819900011'); // no sales.create

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/schemes/' . $scheme->id . '/enroll'))->assertForbidden();
    }

    public function test_user_without_sales_view_cannot_open_installments(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['catalog.manage'], '9819900012'); // no sales.view

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/installments'))->assertForbidden();
    }

    public function test_manufacturer_edition_cannot_reach_schemes(): void
    {
        // /schemes is inside an edition:retailer group.
        [$owner, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/schemes'))->assertForbidden();
    }

    public function test_guest_cannot_reach_schemes(): void
    {
        $res = $this->get(self::ERP . '/schemes');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
