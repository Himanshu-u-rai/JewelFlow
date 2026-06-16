<?php
namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformProduct;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionGateService;
use App\Services\SubscriptionPaymentService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class FreshOnboardingSolidityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;
    protected function setUp(): void {
        $this->skipIfNotPostgres();
        parent::setUp();
        config(['platform.enforce_subscriptions' => true]);
        // seed the REAL plans/products as production would
        $this->seed(\Database\Seeders\PlatformProductSeeder::class);
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    public function test_first_retailer_shop_yearly_purchase_is_solid(): void
    {
        // A brand-new owner + shop (as onboarding's shops.store would create).
        $admin = $this->createPlatformAdmin();
        $shop = Shop::create([
            'name'=>'First Shop','shop_type'=>'retailer','phone'=>'9000000001',
            'owner_first_name'=>'A','owner_last_name'=>'B','owner_mobile'=>'9000000002',
            'is_active'=>true,'access_mode'=>'active',
        ]);
        $user = User::create([
            'name'=>'Owner','mobile_number'=>'9000000002','shop_id'=>$shop->id,
            'password'=>bcrypt('x'),'is_active'=>true,
        ]);
        $this->actingAs($user);

        $plan = Plan::where('code','retailer_yearly')->firstOrFail();
        $this->assertSame(0, (int)$plan->trial_days, 'paid plan must have NO trial');

        // The exact call the payment callback makes after Razorpay verifies.
        $sub = app(SubscriptionPaymentService::class)->createSubscription(
            $plan, 'yearly', (float)$plan->price_yearly, 'pay_first', 'order_first'
        );

        // 1) Full year, active, NOT trial.
        $this->assertSame('active', $sub->status);
        $this->assertEquals(Carbon::parse($sub->starts_at)->addYear()->toDateString(),
            Carbon::parse($sub->ends_at)->toDateString(), 'yearly = full year');
        // 2) grace = ends_at + plan grace_days
        $this->assertEquals(Carbon::parse($sub->ends_at)->addDays($plan->grace_days)->toDateString(),
            Carbon::parse($sub->grace_ends_at)->toDateString());
        // 3) Edition granted from the subscription
        $this->assertTrue($shop->fresh()->hasEdition('retailer'));
        // 4) Shop is writable (gate passes for a paid active shop)
        SubscriptionGateService::assertShopWritable($shop->id); // must NOT throw
        $this->assertTrue(true, 'gate allows writes for paid active retailer');
    }

    public function test_unpaid_fresh_shop_cannot_write(): void
    {
        // A shop created but NO subscription yet (seed edition only) must be blocked.
        $shop = Shop::create([
            'name'=>'No Pay Shop','shop_type'=>'retailer','phone'=>'9000000003',
            'owner_first_name'=>'C','owner_last_name'=>'D','owner_mobile'=>'9000000004',
            'is_active'=>true,'access_mode'=>'active',
        ]);
        $blocked=false;
        try { SubscriptionGateService::assertShopWritable($shop->id); }
        catch (LogicException $e){ $blocked=true; }
        $this->assertTrue($blocked, 'a shop with a seed edition but no subscription must NOT write');
    }
}
