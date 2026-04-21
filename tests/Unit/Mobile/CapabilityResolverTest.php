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

        // Empty feature set — every gated feature absent.
        [$shop, $user] = $this->buildTenant(
            features: [],
            permissions: ['cash.view'],
            roleName: 'staff',
        );

        $caps = $resolver->resolve($shop, $user);

        $this->assertTrue($caps->dashboard, 'dashboard is always-on');
        $this->assertTrue($caps->scanner, 'scanner is always-on');
        $this->assertTrue($caps->purchases, 'purchases is always-on');
        $this->assertTrue($caps->cashbook, 'cashbook is always-on (permission satisfied)');
        $this->assertTrue($caps->catalog, 'catalog defaults to true when plan has no catalog keys');
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
