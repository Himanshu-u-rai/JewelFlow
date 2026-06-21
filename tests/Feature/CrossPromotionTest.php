<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\User;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Cross-promotion between ERP and Dhiran — Phase 4.
 *
 * Lightweight, non-intrusive: an ERP customer without Dhiran sees a calm "Explore
 * Dhiran" card; a Dhiran customer without the Retail ERP sees an "Explore JewelFlow
 * ERP" card. Each links to the OTHER product's SEPARATE register front door — it
 * never grants an edition or links accounts. The account separation stays intact.
 */
class CrossPromotionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const DHIRAN_REGISTER = 'https://dhiran.jewelflows.com/register';
    private const ERP_REGISTER    = 'https://jewelflows.com/register';

    protected function setUp(): void
    {
        parent::setUp();

        // Dhiran yearly plan for the Dhiran onboarding path (RefreshDatabase has no plans).
        Plan::create([
            'code' => 'dhiran_yearly', 'name' => 'Dhiran Yearly',
            'price_monthly' => 0, 'price_yearly' => 14999, 'grace_days' => 5, 'is_active' => true,
        ]);
    }

    /** Fully onboard a Dhiran-realm customer (paid sub → Dhiran shop, dhiran edition only). */
    private function onboardDhiranUser(string $mobile = '9394000000'): User
    {
        $user = User::create([
            'mobile_number' => $mobile, 'password' => bcrypt('x'), 'realm' => 'dhiran', 'is_active' => true,
            'email_verified_at' => now(),
        ]);

        ShopSubscription::create([
            'shop_id' => null,
            'plan_id' => Plan::where('code', 'dhiran_yearly')->first()->id,
            'user_id' => $user->id, 'status' => 'active', 'billing_cycle' => 'yearly',
            'price_paid' => 14999, 'starts_at' => now(), 'ends_at' => now()->addYear(),
        ]);

        $this->actingAs($user)->post('https://dhiran.jewelflows.com/dhiran/onboarding', [
            'name' => 'Dhiran Biz', 'owner_name' => 'Owner', 'phone' => '9876500009',
            'address' => 'Addr', 'city' => 'City', 'state' => 'State',
        ]);

        return $user->fresh();
    }

    // 1. ERP user without Dhiran sees the Dhiran promo.
    public function test_erp_user_without_dhiran_sees_dhiran_promo(): void
    {
        [$erpOwner] = $this->createRetailerTenant();

        $response = $this->actingAs($erpOwner)->get('https://jewelflows.com/dashboard');
        $response->assertOk();
        $response->assertSee('Explore Dhiran');
        $response->assertSee(self::DHIRAN_REGISTER, false);
    }

    // 1b. The promo is a dismissable, once-per-day toast — not a body card.
    public function test_promo_is_a_dismissable_once_per_day_toast(): void
    {
        [$erpOwner] = $this->createRetailerTenant();

        $response = $this->actingAs($erpOwner)->get('https://jewelflows.com/dashboard');
        $response->assertOk();
        // Fixed-position toast container (overlays, does not push content), starts hidden.
        $response->assertSee('cross-promo-toast', false);
        $response->assertSee('data-cross-promo', false);
        // Dismiss control present.
        $response->assertSee('data-cross-promo-close', false);
        // Once-per-day storage key, namespaced per promo.
        $response->assertSee('cross_promo_seen:dhiran', false);
    }

    // 2. ERP user WITH Dhiran access does not see the Dhiran promo.
    public function test_erp_user_with_dhiran_does_not_see_dhiran_promo(): void
    {
        [$erpOwner, $erpShop] = $this->createRetailerTenant();
        // Grant the Dhiran edition to the ERP shop.
        ShopEdition::grantTo($erpShop, ShopEdition::DHIRAN, $erpOwner);

        $response = $this->actingAs($erpOwner->fresh())->get('https://jewelflows.com/dashboard');
        $response->assertOk();
        $response->assertDontSee('Explore Dhiran');
    }

    // 3. Dhiran user without ERP sees the ERP promo.
    public function test_dhiran_user_without_erp_sees_erp_promo(): void
    {
        $dhiran = $this->onboardDhiranUser('9394000001');

        $response = $this->actingAs($dhiran)->get('https://dhiran.jewelflows.com/dhiran');
        $response->assertOk();
        $response->assertSee('Explore JewelFlow ERP');
        $response->assertSee(self::ERP_REGISTER, false);
    }

    // 4. Dhiran user WITH ERP access does not see the ERP promo.
    public function test_dhiran_user_with_erp_does_not_see_erp_promo(): void
    {
        $dhiran = $this->onboardDhiranUser('9394000002');
        // Grant a Retail ERP edition to the Dhiran shop.
        ShopEdition::grantTo($dhiran->shop, ShopEdition::RETAILER, $dhiran);

        $response = $this->actingAs($dhiran->fresh())->get('https://dhiran.jewelflows.com/dhiran');
        $response->assertOk();
        $response->assertDontSee('Explore JewelFlow ERP');
    }

    // 5. Promo links target the OTHER product's separate register front door.
    public function test_promo_links_point_to_separate_register_front_doors(): void
    {
        [$erpOwner] = $this->createRetailerTenant();
        $erpResponse = $this->actingAs($erpOwner)->get('https://jewelflows.com/dashboard');
        $erpResponse->assertSee('href="'.self::DHIRAN_REGISTER.'"', false);

        $dhiran = $this->onboardDhiranUser('9394000003');
        $dhiranResponse = $this->actingAs($dhiran)->get('https://dhiran.jewelflows.com/dhiran');
        $dhiranResponse->assertSee('href="'.self::ERP_REGISTER.'"', false);
    }

    // 6. Viewing the promo grants NO edition (no silent account/edition mutation).
    public function test_viewing_promo_grants_no_edition(): void
    {
        [$erpOwner, $erpShop] = $this->createRetailerTenant();
        $before = ShopEdition::activeFor($erpShop->fresh());

        $this->actingAs($erpOwner)->get('https://jewelflows.com/dashboard')->assertOk();

        $after = ShopEdition::activeFor($erpShop->fresh());
        $this->assertSame($before, $after);
        $this->assertNotContains(ShopEdition::DHIRAN, $after);

        // And the Dhiran side: a Dhiran shop stays dhiran-only after viewing the ERP promo.
        $dhiran = $this->onboardDhiranUser('9394000004');
        $this->actingAs($dhiran)->get('https://dhiran.jewelflows.com/dhiran')->assertOk();
        $this->assertSame([ShopEdition::DHIRAN], ShopEdition::activeFor($dhiran->shop));
    }

    // 7. Existing ERP dashboard still works (renders, no promo break).
    public function test_erp_dashboard_still_works(): void
    {
        [$erpOwner] = $this->createRetailerTenant();
        $this->actingAs($erpOwner)->get('https://jewelflows.com/dashboard')->assertOk();
    }

    // 8. Existing Dhiran dashboard still works.
    public function test_dhiran_dashboard_still_works(): void
    {
        $dhiran = $this->onboardDhiranUser('9394000005');
        $this->actingAs($dhiran)->get('https://dhiran.jewelflows.com/dhiran')->assertOk();
    }

    // 9. The card can be disabled by config (no spam if a shop ever opts out).
    public function test_promo_hidden_when_disabled_in_config(): void
    {
        config(['platform.cross_promotion.enabled' => false]);

        [$erpOwner] = $this->createRetailerTenant();
        $this->actingAs($erpOwner)->get('https://jewelflows.com/dashboard')
            ->assertDontSee('Explore Dhiran');
    }
}
