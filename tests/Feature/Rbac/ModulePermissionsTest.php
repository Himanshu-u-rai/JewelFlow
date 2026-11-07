<?php

namespace Tests\Feature\Rbac;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Owner decision 2026-11-07: schemes, EMI, quick bills, loyalty adjustments, categories/products and the audit log
 * have their own permissions instead of riding on sales.*, customers.edit, catalog.manage and settings.view.
 */
class ModulePermissionsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withoutVite();
        [, $this->shop] = $this->createRetailerTenant();
        ShopSubscription::query()->where('shop_id', $this->shop->id)->update(['status' => 'active']);
    }

    private function role(string $name, array $permissions): Role
    {
        $role = new Role();
        $role->forceFill(['name' => $name, 'display_name' => ucfirst($name), 'shop_id' => $this->shop->id])->save();
        $role->permissions()->sync(Permission::query()->whereIn('name', $permissions)->pluck('id'));

        return $role;
    }

    private function as(array $permissions): User
    {
        $user = User::factory()->create(['shop_id' => $this->shop->id, 'role_id' => $this->role('r' . uniqid(), $permissions)->id, 'is_active' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function get403(string $url): void
    {
        TenantContext::runFor($this->shop->id, fn () => $this->get($url))->assertForbidden();
    }

    private function getOk(string $url): void
    {
        TenantContext::runFor($this->shop->id, fn () => $this->get($url))->assertOk();
    }

    public function test_upgrade_grants_each_new_permission_to_roles_that_held_the_one_it_replaces(): void
    {
        $seller = $this->role('seller', ['sales.view', 'sales.create']);
        $merch = $this->role('merch', ['catalog.manage']);
        $admin = $this->role('admin', ['settings.view', 'customers.edit', 'sales.void']);

        (require base_path('database/migrations/2026_11_07_100000_add_module_permissions.php'))->up();

        $has = fn (Role $r) => $r->permissions()->pluck('name')->all();
        $this->assertEqualsCanonicalizing(
            ['sales.view', 'sales.create', 'schemes.view', 'schemes.enroll', 'installments.view', 'installments.create',
                'installments.collect', 'quick_bills.view', 'quick_bills.create'],
            $has($seller)
        );
        $this->assertEqualsCanonicalizing(['catalog.manage', 'schemes.view', 'schemes.manage', 'categories.manage'], $has($merch));
        $this->assertEqualsCanonicalizing(
            ['settings.view', 'customers.edit', 'sales.void', 'audit.view', 'loyalty.adjust', 'schemes.cancel',
                'installments.default', 'quick_bills.void'],
            $has($admin)
        );
    }

    public function test_quick_bills_follow_their_own_permissions(): void
    {
        $this->as(['sales.view', 'sales.create', 'sales.void', 'sales.pos']);
        $this->get403('/quick-bills');
        $this->get403('/quick-bills/create');

        $this->as(['quick_bills.view', 'quick_bills.create']);
        $this->getOk('/quick-bills');
        $this->getOk('/quick-bills/create');
    }

    public function test_emi_plans_need_their_own_permission_even_on_the_customers_page(): void
    {
        $this->as(['customers.view', 'sales.view', 'sales.create']);
        $this->get403('/installments');
        $page = TenantContext::runFor($this->shop->id, fn () => $this->get('/customers'))->assertOk()->getContent();
        $this->assertStringNotContainsString('EMI / Installments', $page);

        $this->as(['customers.view', 'installments.view']);
        $this->getOk('/installments');
        $this->assertStringContainsString('EMI / Installments',
            TenantContext::runFor($this->shop->id, fn () => $this->get('/customers'))->getContent());
    }

    public function test_scheme_counter_staff_can_reach_schemes_without_catalog_rights(): void
    {
        $this->as(['schemes.view', 'schemes.enroll']);
        $this->getOk('/schemes');
        $this->get403('/schemes/create');

        $this->as(['catalog.manage', 'sales.create']);
        $this->get403('/schemes');
    }

    public function test_loyalty_adjustment_categories_and_audit_log_have_their_own_switches(): void
    {
        $customer = TenantContext::runFor($this->shop->id, fn () => Customer::create([
            'shop_id' => $this->shop->id, 'first_name' => 'Asha', 'last_name' => 'Rao', 'mobile' => '9810011199',
        ]));

        $this->as(['customers.view', 'customers.edit', 'catalog.manage', 'settings.view', 'inventory.view']);
        $this->get403("/loyalty/{$customer->id}/adjust");
        TenantContext::runFor($this->shop->id, fn () => $this->post('/categories', ['name' => 'Anklet']))->assertForbidden();
        $this->get403('/report/audit');
        TenantContext::runFor($this->shop->id, fn () => $this->get('/settings?tab=audit'))->assertRedirect();

        $this->as(['customers.view', 'loyalty.adjust', 'audit.view', 'settings.view']);
        $this->getOk("/loyalty/{$customer->id}/adjust");
        $this->getOk('/settings?tab=audit');
    }

    public function test_billing_view_is_no_longer_offered_on_the_roles_screen(): void
    {
        $owner = User::query()->where('shop_id', $this->shop->id)->orderBy('id')->firstOrFail();
        $this->role('cashier', ['sales.pos']); // the matrix is drawn for non-owner roles
        $this->actingAs($owner);
        $html = TenantContext::runFor($this->shop->id, fn () => $this->get('/settings?tab=roles'))->assertOk()->getContent();

        $this->assertStringNotContainsString('View Billing &amp; Invoices', $html);
        $this->assertStringContainsString('Collect EMI Payments', $html);
    }
}
