<?php

namespace Tests\Unit\Mobile;

use App\Models\Permission;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\Mobile\CapabilityResolver;
use Mockery;
use Tests\TestCase;

/**
 * Pure unit coverage for CapabilityResolver.
 *
 * We deliberately avoid booting the full application / DB — the resolver
 * only touches model relations, so we wire them up with `setRelation()`
 * and the permission lookup with a Mockery partial mock on the User.
 */
class CapabilityResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * (a) Capability returns true when the plan feature is present
     *     AND the user holds the required permission.
     */
    public function test_returns_true_when_plan_feature_on_and_user_has_permission(): void
    {
        $resolver = new CapabilityResolver();

        [$shop, $user] = $this->buildTenant(
            features: [
                'inventory' => true,
                'pos' => true,
                'invoices' => true,
                'customers' => true,
                'repairs' => true,
                'schemes' => true,
                'loyalty' => true,
                'installments' => true,
                'vendors' => true,
            ],
            permissions: [
                'inventory.view',
                'sales.pos',
                // The invoice capability gates on sales.view (the same permission
                // the web invoices.show route uses), not invoices.view — grant it
                // so the "user has permission" path is actually exercised.
                'sales.view',
                'invoices.view',
                'customers.view',
                'repairs.view',
                'cash.view',
            ],
            roleName: 'manager',
        );

        $caps = $resolver->resolve($shop, $user);

        $this->assertTrue($caps->items);
        $this->assertTrue($caps->stock);
        $this->assertTrue($caps->pos);
        $this->assertTrue($caps->invoice);
        $this->assertTrue($caps->schemes);
        $this->assertTrue($caps->loyalty);
        $this->assertTrue($caps->installments);
    }

    /**
     * (b) Capability returns false when its gating plan feature is
     *     explicitly disabled — even if the user has the permission.
     */
    public function test_returns_false_when_plan_feature_disabled(): void
    {
        $resolver = new CapabilityResolver();

        [$shop, $user] = $this->buildTenant(
            features: [
                'inventory' => true,
                'pos' => true,
                'schemes' => false,      // explicitly off on plan
                'loyalty' => false,      // explicitly off on plan
                'installments' => false, // explicitly off on plan
                'invoices' => false,
            ],
            permissions: ['invoices.view'],
            roleName: 'staff',
        );

        $caps = $resolver->resolve($shop, $user);

        $this->assertFalse($caps->schemes);
        $this->assertFalse($caps->loyalty);
        $this->assertFalse($caps->installments);
        $this->assertFalse($caps->invoice, 'invoice should be gated off when plan disables invoices');
    }

    /**
     * (c) Capability returns true when not mapped to any plan feature
     *     ("always-on" defaults like dashboard, scanner, purchases, cashbook).
     */
    public function test_always_on_capabilities_return_true_even_when_plan_is_silent(): void
    {
        $resolver = new CapabilityResolver();

        // Empty feature set — every gated feature absent. A silent plan never
        // blocks; the user still needs the permission the web route asks for.
        [$shop, $user] = $this->buildTenant(
            features: [],
            permissions: ['cash.view', 'reports.view', 'inventory.view', 'catalog.manage'],
            roleName: 'staff',
        );

        $caps = $resolver->resolve($shop, $user);

        $this->assertTrue($caps->dashboard, 'dashboard money figures: plan-silent, reports.view held');
        $this->assertTrue($caps->scanner, 'scanner: plan-silent, inventory.view held');
        $this->assertTrue($caps->purchases, 'purchases: plan-silent, inventory.view held');
        $this->assertTrue($caps->catalog, 'catalog defaults to on when plan has no catalog keys, catalog.manage held');
        // Cash Book is NOT purely always-on: it is role+permission gated. A staff
        // user with cash.view is still denied (see the dedicated matrix test).
        $this->assertFalse($caps->cashbook, 'cashbook is denied for staff even with cash.view');

        // Without the permissions the same silent plan still does not block: the
        // reason is the permission, never the plan.
        [$shop2, $user2] = $this->buildTenant(features: [], permissions: [], roleName: 'staff2');
        $caps2 = $resolver->resolve($shop2, $user2);
        $this->assertFalse($caps2->catalog);
        $this->assertSame('permission', $resolver->reasons()['catalog']['reason']);
        $this->assertSame('permission', $resolver->reasons()['dashboard']['reason']);
    }

    /**
     * (#8) Cash Book capability follows the mobile product rule, not plan/perm
     * alone: only owner/manager holding the matching cash permission get it;
     * a cashier/staff member is denied even if the role carries the permission.
     */
    public function test_cashbook_capability_reflects_role_and_permission(): void
    {
        $resolver = new CapabilityResolver();

        // Owner with cash.view + cash.create → both true.
        [$shop, $user] = $this->buildTenant([], ['cash.view', 'cash.create'], 'owner');
        $caps = $resolver->resolve($shop, $user);
        $this->assertTrue($caps->cashbook);
        $this->assertTrue($caps->cashbook_create);

        // Manager with cash.view only → view true, create false.
        [$shop, $user] = $this->buildTenant([], ['cash.view'], 'manager');
        $caps = $resolver->resolve($shop, $user);
        $this->assertTrue($caps->cashbook);
        $this->assertFalse($caps->cashbook_create);

        // Manager without cash.view → both false.
        [$shop, $user] = $this->buildTenant([], [], 'manager');
        $caps = $resolver->resolve($shop, $user);
        $this->assertFalse($caps->cashbook);
        $this->assertFalse($caps->cashbook_create);

        // Staff WITH cash.view + cash.create → STILL both false (role gates).
        [$shop, $user] = $this->buildTenant([], ['cash.view', 'cash.create'], 'staff');
        $caps = $resolver->resolve($shop, $user);
        $this->assertFalse($caps->cashbook, 'staff denied even with permission');
        $this->assertFalse($caps->cashbook_create, 'staff denied even with permission');
    }

    /**
     * The app shows "Add item" on the stock page only to someone the server
     * would let add stock (POST /items is gated on inventory.create).
     */
    public function test_items_create_follows_the_create_items_permission(): void
    {
        $resolver = new CapabilityResolver();

        [$shop, $user] = $this->buildTenant([], [], 'owner');
        $this->assertTrue($resolver->resolve($shop, $user)->items_create, 'owner');

        [$shop, $user] = $this->buildTenant([], ['inventory.view', 'inventory.create'], 'staff');
        $this->assertTrue($resolver->resolve($shop, $user)->items_create, 'staff with Create Items');

        [$shop, $user] = $this->buildTenant([], ['inventory.view'], 'staff');
        $this->assertFalse($resolver->resolve($shop, $user)->items_create, 'staff who can only view stock');
    }

    /**
     * The app shows Approve / Reject on a pending return only to someone the
     * server would let do it (both routes are gated on returns.approve, as on the web).
     */
    public function test_returns_approve_follows_the_approve_returns_permission(): void
    {
        $resolver = new CapabilityResolver();

        [$shop, $user] = $this->buildTenant([], [], 'owner');
        $this->assertTrue($resolver->resolve($shop, $user)->returns_approve, 'owner');

        [$shop, $user] = $this->buildTenant([], ['sales.view', 'returns.approve'], 'staff');
        $this->assertTrue($resolver->resolve($shop, $user)->returns_approve, 'staff with Approve Returns');

        [$shop, $user] = $this->buildTenant([], ['sales.view', 'returns.create'], 'staff');
        $this->assertFalse($resolver->resolve($shop, $user)->returns_approve, 'staff who can only create returns');
    }

    /**
     * Status, delivery and edit on a repair are all gated on repairs.edit (web and
     * app routes); the app shows them only to someone who has it.
     */
    public function test_repairs_edit_follows_the_edit_repairs_permission(): void
    {
        $resolver = new CapabilityResolver();

        [$shop, $user] = $this->buildTenant([], [], 'owner');
        $this->assertTrue($resolver->resolve($shop, $user)->repairs_edit, 'owner');

        [$shop, $user] = $this->buildTenant([], ['repairs.view', 'repairs.edit'], 'staff');
        $this->assertTrue($resolver->resolve($shop, $user)->repairs_edit, 'staff with Edit Repairs');

        [$shop, $user] = $this->buildTenant([], ['repairs.view'], 'staff');
        $this->assertFalse($resolver->resolve($shop, $user)->repairs_edit, 'staff who can only view repairs');
    }

    /**
     * Suppliers follow the web's vendor permissions: seeing them needs vendors.view
     * (it was plan-only, so a user without it saw the menu and got refused);
     * adding / editing / archiving needs vendors.manage.
     */
    public function test_suppliers_follow_the_view_and_manage_vendor_permissions(): void
    {
        $resolver = new CapabilityResolver();

        [$shop, $user] = $this->buildTenant([], [], 'owner');
        $this->assertTrue($resolver->resolve($shop, $user)->suppliers, 'owner');
        $this->assertTrue($resolver->resolve($shop, $user)->suppliers_manage, 'owner');

        [$shop, $user] = $this->buildTenant([], ['vendors.view'], 'staff');
        $this->assertTrue($resolver->resolve($shop, $user)->suppliers, 'staff who can view suppliers');
        $this->assertFalse($resolver->resolve($shop, $user)->suppliers_manage, 'staff who can only view suppliers');

        [$shop, $user] = $this->buildTenant([], ['sales.view'], 'staff');
        $this->assertFalse($resolver->resolve($shop, $user)->suppliers, 'staff without supplier access');
    }

    /**
     * Owner role should bypass per-permission gates.
     */
    public function test_owner_role_bypasses_permission_gates(): void
    {
        $resolver = new CapabilityResolver();

        [$shop, $user] = $this->buildTenant(
            features: [
                'inventory' => true,
                'pos' => true,
                'invoices' => true,
                'customers' => true,
                'repairs' => true,
            ],
            permissions: [], // explicitly no permissions attached
            roleName: 'owner',
        );

        $caps = $resolver->resolve($shop, $user);

        $this->assertTrue($caps->items);
        $this->assertTrue($caps->pos);
        $this->assertTrue($caps->invoice);
        $this->assertTrue($caps->customers);
        $this->assertTrue($caps->repairs);
    }

    /**
     * Build a Shop + User with hydrated relations and a stubbed permission
     * lookup. No DB access required.
     *
     * @param  array<string, mixed>  $features
     * @param  list<string>  $permissions
     */
    private function buildTenant(array $features, array $permissions, string $roleName): array
    {
        $plan = new Plan();
        $plan->forceFill([
            'id' => 1,
            'code' => 'test_plan',
            'name' => 'Test',
            'features' => $features, // cast to array on access
        ]);

        $subscription = new ShopSubscription();
        $subscription->forceFill(['id' => 1, 'shop_id' => 1, 'plan_id' => 1, 'status' => 'active']);
        $subscription->setRelation('plan', $plan);

        $shop = new Shop();
        $shop->forceFill(['id' => 1, 'name' => 'Test Shop']);
        $shop->setRelation('subscription', $subscription);

        $role = new Role();
        $role->forceFill(['id' => 1, 'name' => $roleName, 'display_name' => ucfirst($roleName)]);

        // Build a Mockery partial of User so hasPermission() can short-circuit
        // without hitting the DB.
        /** @var User&Mockery\MockInterface $user */
        $user = Mockery::mock(User::class)->makePartial();
        $user->forceFill(['id' => 1, 'shop_id' => 1, 'role_id' => 1, 'name' => 'Test']);
        $user->setRelation('role', $role);
        $user->setRelation('shop', $shop);

        $user->shouldReceive('hasPermission')
            ->andReturnUsing(fn (string $perm) => in_array($perm, $permissions, true));
        $user->shouldReceive('isOwner')
            ->andReturn($roleName === 'owner');

        return [$shop, $user];
    }
}
