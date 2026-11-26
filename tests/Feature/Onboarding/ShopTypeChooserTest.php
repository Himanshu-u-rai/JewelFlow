<?php

namespace Tests\Feature\Onboarding;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformSetting;
use App\Models\User;
use App\Services\OnboardingResumeService;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The ERP shop-type chooser — /shops/choose-type.
 *
 * The screen's skip-condition and the screen's contents must agree on ONE list.
 * They did not: the controller counted every platform-enabled edition, while the
 * view renders Retailer and Manufacturer only.
 *
 * With manufacturer_enabled=false only ONE card can ever be drawn — asking the
 * user a question with a single possible answer must be skipped.
 */
class ShopTypeChooserTest extends TestCase
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

    /** A fresh ERP user with no shop — the only state that reaches this screen. */
    private function chooser(string $mobile = '9390100001'): User
    {
        return User::create([
            'mobile_number' => $mobile,
            'password'      => bcrypt('password'),
            'realm'         => 'erp',
            'is_active'     => true,
        ]);
    }

    private function setTypes(bool $retailer, bool $manufacturer): void
    {
        PlatformSetting::set('retailer_enabled', $retailer ? '1' : '0');
        PlatformSetting::set('manufacturer_enabled', $manufacturer ? '1' : '0');
    }

    /**
     * The plan page redirects BACK to the chooser when no plan matches the shop
     * type, and RefreshDatabase starts with an empty plans table. Without this,
     * a test reaching that page would pass or fail for the wrong reason.
     */
    private function retailerPlan(): void
    {
        Plan::firstOrCreate(
            ['code' => 'retailer_yearly'],
            ['name' => 'Retailer Yearly', 'price_monthly' => 0, 'price_yearly' => 19999, 'grace_days' => 5, 'is_active' => true],
        );
    }

    /**
     * THE BUG. Manufacturer off.
     * Only Retailer can be drawn, so there is no question to ask — skip it.
     */
    public function test_screen_is_skipped_when_only_one_type_can_actually_be_rendered(): void
    {
        $this->setTypes(retailer: true, manufacturer: false);
        $user = $this->chooser();

        $this->actingAs($user)
            ->get(self::ERP.'/shops/choose-type')
            ->assertRedirect(route('subscription.plans'));

        $this->assertSame(ShopEdition::RETAILER, session('onboarding_shop_type'));
    }

    /** A genuine choice must still be offered. */
    public function test_screen_renders_when_two_types_can_be_rendered(): void
    {
        $this->setTypes(retailer: true, manufacturer: true);

        $response = $this->actingAs($this->chooser('9390100004'))
            ->get(self::ERP.'/shops/choose-type');

        $response->assertOk();
        $response->assertSee('value="retailer"', false);
        $response->assertSee('value="manufacturer"', false);
        // The retired gold-loan option is never offered.
        $response->assertDontSee('value="dhiran"', false);
    }

    /** The POST must refuse what the GET never offered. */
    public function test_retired_gold_loan_edition_cannot_be_chosen(): void
    {
        $this->setTypes(retailer: true, manufacturer: true);
        $user = $this->chooser('9390100005');

        $this->actingAs($user)
            ->post(self::ERP.'/shops/choose-type', ['edition' => 'dhiran'])
            ->assertSessionHasErrors('edition');

        $this->assertNotSame('dhiran', session('onboarding_shop_type'));
    }

    /** A disabled edition still cannot be posted. */
    public function test_disabled_type_cannot_be_chosen(): void
    {
        $this->setTypes(retailer: true, manufacturer: false);
        $user = $this->chooser('9390100006');

        $this->actingAs($user)
            ->post(self::ERP.'/shops/choose-type', ['edition' => 'manufacturer'])
            ->assertSessionHas('error');

        $this->assertNotSame('manufacturer', session('onboarding_shop_type'));
    }

    /**
     * Nothing this screen can offer → it must close, not silently onboard.
     */
    public function test_chooser_closes_when_no_type_is_enabled(): void
    {
        $this->setTypes(retailer: false, manufacturer: false);

        $this->actingAs($this->chooser('9390100007'))
            ->get(self::ERP.'/shops/choose-type')
            ->assertStatus(503);
    }

    /**
     * The skip must hand the NEXT steps exactly what picking the card by hand
     * used to hand them. This screen feeds the plan page and shop creation, so a
     * partially-populated session here surfaces as a broken signup later.
     */
    public function test_skip_leaves_the_same_state_a_manual_pick_would(): void
    {
        $this->setTypes(retailer: true, manufacturer: false);
        $user = $this->chooser('9390100009');

        $this->actingAs($user)->get(self::ERP.'/shops/choose-type');

        // What shops.store reads when it creates the shop.
        $this->assertSame([ShopEdition::RETAILER], session('onboarding_editions'));
        // What the plan page reads to scope which plans to show.
        $this->assertSame(ShopEdition::RETAILER, session('onboarding_shop_type'));
        // The resume marker, so an interrupted signup comes back to the right step.
        $this->assertSame(
            OnboardingResumeService::STEP_SELECT_PLAN,
            $user->fresh()->onboarding_step
        );
        $this->assertSame(ShopEdition::RETAILER, $user->fresh()->onboarding_shop_type);
    }

    /**
     * The dead-loop guard. The chooser sends users to the plan page, and the plan
     * page sends users lacking a valid type BACK to the chooser. If the two
     * disagreed about which types are valid, that is an infinite redirect.
     */
    public function test_plan_page_accepts_the_auto_picked_type_without_bouncing_back(): void
    {
        $this->setTypes(retailer: true, manufacturer: false);
        $this->retailerPlan();
        $user = $this->chooser('9390100010');

        $this->actingAs($user)
            ->get(self::ERP.'/shops/choose-type')
            ->assertRedirect(route('subscription.plans'));

        $response = $this->actingAs($user)->get(self::ERP.'/subscription/plans');

        $this->assertNotSame(
            route('shops.choose-type'),
            $response->headers->get('Location'),
            'The plan page bounced back to the chooser — the auto-picked type was rejected downstream.'
        );
        $response->assertOk();
    }

    /**
     * A link to a screen that skips itself is a control that visibly does
     * nothing. The plan page's "Change business type" link must answer the same
     * question the chooser answers, or clicking it returns you to the page you
     * clicked it on. Found on staging, not in review.
     */
    public function test_change_business_type_link_is_hidden_when_there_is_no_choice(): void
    {
        $this->setTypes(retailer: true, manufacturer: false);
        $this->retailerPlan();
        $user = $this->chooser('9390100011');

        $this->actingAs($user)->get(self::ERP.'/shops/choose-type');
        $this->actingAs($user)
            ->get(self::ERP.'/subscription/plans')
            ->assertOk()
            ->assertDontSee('Change business type');
    }

    /** …and it must still be there when changing type is actually possible. */
    public function test_change_business_type_link_is_shown_when_a_choice_exists(): void
    {
        $this->setTypes(retailer: true, manufacturer: true);
        $this->retailerPlan();
        $user = $this->chooser('9390100012');

        $this->actingAs($user)->post(self::ERP.'/shops/choose-type', ['edition' => 'retailer']);
        $this->actingAs($user)
            ->get(self::ERP.'/subscription/plans')
            ->assertOk()
            ->assertSee('Change business type');
    }

    /** A user who already has a shop never sees this screen. */
    public function test_user_with_shop_is_redirected_away(): void
    {
        $this->setTypes(retailer: true, manufacturer: true);
        // A real tenant — users.shop_id is a foreign key, so a made-up id cannot
        // stand in for "this user already has a shop".
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)
            ->get(self::ERP.'/shops/choose-type')
            ->assertRedirect(route('dashboard'));
    }
}
