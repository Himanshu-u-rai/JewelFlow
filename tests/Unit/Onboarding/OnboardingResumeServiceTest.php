<?php

namespace Tests\Unit\Onboarding;

use App\Http\Controllers\ShopController;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\OnboardingResumeService as Resume;
use App\Support\ShopEdition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ERP Onboarding — unit level (Module 2). The resume-step decision logic and the
 * edition-mapping helper, in isolation. No HTTP.
 */
class OnboardingResumeServiceTest extends TestCase
{
    use RefreshDatabase;

    /** A persisted ERP user. Onboarding columns are forceFilled (server-set, not mass-assignable). */
    private function user(array $base = [], array $onboarding = []): User
    {
        $u = User::create(array_merge([
            'mobile_number' => '93' . random_int(10000000, 99999999),
            'password' => bcrypt('x'),
            'realm' => 'erp',
            'is_active' => true,
        ], $base));

        if ($onboarding !== []) {
            $u->forceFill($onboarding)->save();
        }

        return $u->fresh();
    }

    private function plan(): Plan
    {
        return Plan::firstOrCreate(
            ['code' => 'retailer_yearly'],
            ['name' => 'Retailer Yearly', 'price_monthly' => 0, 'price_yearly' => 19999, 'grace_days' => 5, 'is_active' => true],
        );
    }

    private function pendingSub(User $u): ShopSubscription
    {
        return ShopSubscription::create([
            'shop_id' => null, 'plan_id' => $this->plan()->id, 'user_id' => $u->id,
            'status' => 'active', 'billing_cycle' => 'yearly', 'price_paid' => 19999,
            'starts_at' => now(), 'ends_at' => now()->addYear(),
        ]);
    }

    // ── resolveStep decision matrix ────────────────────────────────────────

    public function test_user_with_shop_is_complete(): void
    {
        $shop = Shop::create([
            'name' => 'S', 'shop_type' => 'retailer', 'phone' => '9000000111',
            'owner_first_name' => 'A', 'owner_last_name' => 'B', 'owner_mobile' => '9000000112',
            'is_active' => true, 'access_mode' => 'active',
        ]);
        $u = $this->user(['shop_id' => $shop->id]);
        $this->assertNull(Resume::resolveStep($u), 'has shop → onboarding complete');
    }

    public function test_fresh_user_starts_at_choose_type(): void
    {
        $u = $this->user();
        $this->assertSame(Resume::STEP_CHOOSE_TYPE, Resume::resolveStep($u));
    }

    public function test_user_with_chosen_type_goes_to_plan(): void
    {
        $u = $this->user([], ['onboarding_shop_type' => 'retailer']);
        $this->assertSame(Resume::STEP_SELECT_PLAN, Resume::resolveStep($u));
    }

    public function test_user_at_payment_step_resumes_payment(): void
    {
        $u = $this->user([], ['onboarding_shop_type' => 'retailer', 'onboarding_step' => Resume::STEP_PAYMENT]);
        $this->assertSame(Resume::STEP_PAYMENT, Resume::resolveStep($u));
    }

    public function test_user_with_pending_paid_subscription_goes_to_create_shop(): void
    {
        $u = $this->user();
        $this->pendingSub($u);

        $this->assertSame(Resume::STEP_CREATE_SHOP, Resume::resolveStep($u->fresh()));
    }

    // ── findPendingSubscription ────────────────────────────────────────────

    public function test_pending_subscription_is_scoped_to_the_user(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->pendingSub($a);

        $this->assertNotNull(Resume::findPendingSubscription($a->fresh()));
        $this->assertNull(Resume::findPendingSubscription($b->fresh()), 'must not see another user\'s pending subscription');
    }

    // ── primaryEdition mapping (Retailer > Manufacturer > Dhiran) ──────────

    public function test_primary_edition_prefers_retailer(): void
    {
        $this->assertSame(ShopEdition::RETAILER, ShopController::primaryEdition([ShopEdition::MANUFACTURER, ShopEdition::RETAILER]));
    }

    public function test_primary_edition_manufacturer_over_dhiran(): void
    {
        $this->assertSame(ShopEdition::MANUFACTURER, ShopController::primaryEdition([ShopEdition::DHIRAN, ShopEdition::MANUFACTURER]));
    }

    public function test_primary_edition_single_value(): void
    {
        $this->assertSame(ShopEdition::MANUFACTURER, ShopController::primaryEdition([ShopEdition::MANUFACTURER]));
    }

    public function test_primary_edition_defaults_to_retailer_when_empty(): void
    {
        $this->assertSame(ShopEdition::RETAILER, ShopController::primaryEdition([]));
    }
}
