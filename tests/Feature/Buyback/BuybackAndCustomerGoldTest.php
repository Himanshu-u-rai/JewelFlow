<?php

namespace Tests\Feature\Buyback;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\CustomerGoldTransaction;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\BuybackService;
use App\Services\MetalRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Buyback / Old Gold (Module 10). No prior coverage existed. Proves the buyback
 * ledger writes (lot + cash payout + metal movement, all through the
 * MetalRegistry fine-weight authority), the customer-gold deposit HTTP flow, its
 * validation, the manufacturer-edition + customers.edit gates, and cross-shop
 * isolation.
 */
class BuybackAndCustomerGoldTest extends TestCase
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
            'shop_id' => $shop->id, 'first_name' => 'Gold', 'last_name' => 'Seller', 'mobile' => $mobile,
        ]));
    }

    // ── BuybackService::buyGold — ledger writes through the authority ───────

    public function test_buyback_creates_lot_cash_payout_and_movement_via_authority(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        $gross = 10.0; $purity = 22.0; $rate = 6000.0;
        $lot = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ? BuybackService::buyGold($gross, $purity, 0, $rate) : null);

        // Fine weight must equal the MetalRegistry authority, never inline math.
        $expectedFine = round($gross * MetalRegistry::fineWeightMultiplier('gold', $purity), 6);
        $this->assertSame('buyback', $lot->source);
        $this->assertSame($shop->id, $lot->shop_id);
        $this->assertEqualsWithDelta($expectedFine, (float) $lot->fine_weight_total, 0.0001, 'lot fine weight = authority');
        $this->assertEqualsWithDelta($expectedFine, (float) $lot->fine_weight_remaining, 0.0001);

        // Cash payout = fine × rate, recorded as an OUT cash row in cash mode.
        $cash = CashTransaction::withoutGlobalScopes()->where('source_type', 'buyback')->where('source_id', $lot->id)->first();
        $this->assertNotNull($cash, 'cash payout row written');
        $this->assertSame('out', $cash->type);
        $this->assertSame('cash', $cash->payment_mode);
        $this->assertEqualsWithDelta($expectedFine * $rate, (float) $cash->amount, 0.01, 'payout = fine × rate');

        // Metal movement credits the new lot with the same fine weight.
        $mv = MetalMovement::withoutGlobalScopes()->where('reference_type', 'buyback')->where('reference_id', $lot->id)->first();
        $this->assertNotNull($mv, 'metal movement written');
        $this->assertEqualsWithDelta($expectedFine, (float) $mv->fine_weight, 0.0001);
        $this->assertSame($lot->id, (int) $mv->to_lot_id);
    }

    public function test_buyback_applies_test_loss_to_net_weight(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        $gross = 10.0; $purity = 24.0; $rate = 6000.0; $loss = 5.0;
        $lot = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ? BuybackService::buyGold($gross, $purity, $loss, $rate) : null);

        // net = gross × (1 − loss%), fine = net × (purity/24) [=net at 24k].
        $expectedFine = round($gross * (1 - $loss / 100) * MetalRegistry::fineWeightMultiplier('gold', $purity), 6);
        $this->assertEqualsWithDelta($expectedFine, (float) $lot->fine_weight_total, 0.0001, 'test loss reduces net weight');
    }

    public function test_zero_value_buyback_throws_and_writes_nothing(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();

        $before = MetalLot::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
        try {
            TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
                ? BuybackService::buyGold(0.0, 22.0, 0, 6000.0) : null);
            $this->fail('zero-weight buyback should throw');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }
        // Atomic: the failed transaction left no lot and no cash row.
        $this->assertSame($before, MetalLot::withoutGlobalScopes()->where('shop_id', $shop->id)->count(), 'no partial lot');
        $this->assertSame(0, CashTransaction::withoutGlobalScopes()->where('shop_id', $shop->id)->where('source_type', 'buyback')->count(), 'no partial cash');
    }

    // ── Customer gold deposit (HTTP) ───────────────────────────────────────

    public function test_customer_gold_create_page_renders_for_manufacturer(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $cust = $this->customer($shop, '9815500001');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/customers/' . $cust->id . '/gold'))->assertOk();
    }

    public function test_customer_gold_deposit_creates_transaction_and_lot_via_authority(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $cust = $this->customer($shop, '9815500002');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/customers/' . $cust->id . '/gold', [
                'gross_weight' => 8.0, 'purity' => 22.0,
            ]))->assertRedirect();

        $txn = CustomerGoldTransaction::withoutGlobalScopes()->where('customer_id', $cust->id)->first();
        $this->assertNotNull($txn, 'customer gold transaction recorded');
        $this->assertSame($shop->id, $txn->shop_id);

        $lot = MetalLot::withoutGlobalScopes()->where('shop_id', $shop->id)->where('source', 'customer_advance')->first();
        $this->assertNotNull($lot, 'customer_advance lot created');
    }

    public function test_customer_gold_deposit_validation_rejects_bad_weight_and_purity(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $cust = $this->customer($shop, '9815500003');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/customers/' . $cust->id . '/gold', [
                'gross_weight' => 0, 'purity' => 0,
            ]))->assertSessionHasErrors(['gross_weight', 'purity']);
    }

    // ── Tenant isolation & permission gating ───────────────────────────────

    public function test_cannot_deposit_gold_for_another_shops_customer(): void
    {
        [, $shopB] = $this->createManufacturerTenant();
        $custB = $this->customer($shopB, '9815500010');

        [$ownerA, $shopA] = $this->createManufacturerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . '/customers/' . $custB->id . '/gold', ['gross_weight' => 5.0, 'purity' => 22.0]));

        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop customer gold must be blocked');
        $this->assertSame(0, CustomerGoldTransaction::withoutGlobalScopes()->where('customer_id', $custB->id)->count());
    }

    public function test_user_without_customers_edit_cannot_deposit_gold(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $cust = $this->customer($shop, '9815500020');
        $viewer = $this->userWithPerms($shop, ['customers.view'], '9815500021'); // no customers.edit

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/customers/' . $cust->id . '/gold'))->assertForbidden();
    }

    public function test_retailer_edition_cannot_reach_customer_gold(): void
    {
        // The route is edition:manufacturer — a retailer shop is refused.
        [$owner, $shop] = $this->createRetailerTenant();
        $cust = $this->customer($shop, '9815500030');

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/customers/' . $cust->id . '/gold'))->assertForbidden();
    }

    public function test_guest_cannot_reach_customer_gold(): void
    {
        [, $shop] = $this->createManufacturerTenant();
        $cust = $this->customer($shop, '9815500040');

        $res = $this->get(self::ERP . '/customers/' . $cust->id . '/gold');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }
}
