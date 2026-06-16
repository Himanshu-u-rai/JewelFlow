<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Self-serve "Buy & activate now" UI on the Settings → Business Editions tab.
 *
 * The split is decided server-side in SettingsController::buildServicePurchaseOptions():
 *   - product is active AND has an active plan with a real price  → buy-now card
 *   - otherwise (inactive product, or no priced plan)             → request-add form
 *
 * The payment endpoints (initiate-add / add-callback) are NOT exercised here;
 * they are frozen and covered by MultiProductSubscriptionTest. This test only
 * asserts the UI affordance the controller + Blade produce.
 */
class ServicesBuyNowTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        ]);
    }

    /** Create an active monthly + yearly plan pair for a product code. */
    private function priceProduct(string $productCode): void
    {
        $productId = PlatformProduct::where('code', $productCode)->value('id');

        Plan::create([
            'code'                => $productCode . '_monthly_' . fake()->unique()->numberBetween(1000, 999999),
            'name'                => ucfirst($productCode) . ' Monthly',
            'platform_product_id' => $productId,
            'price_monthly'       => 1499,
            'price_yearly'        => null,
            'trial_days'          => 0,
            'grace_days'          => 14,
            'downgrade_to_read_only_on_due' => true,
            'is_active'           => true,
            'features'            => ['staff_limit' => 5],
        ]);

        Plan::create([
            'code'                => $productCode . '_yearly_' . fake()->unique()->numberBetween(1000, 999999),
            'name'                => ucfirst($productCode) . ' Yearly',
            'platform_product_id' => $productId,
            'price_monthly'       => 1249,
            'price_yearly'        => 14999,
            'trial_days'          => 0,
            'grace_days'          => 14,
            'downgrade_to_read_only_on_due' => true,
            'is_active'           => true,
            'features'            => ['staff_limit' => 5],
        ]);
    }

    public function test_purchasable_service_renders_buy_now_button(): void
    {
        // Retailer tenant already owns the retailer edition (not dhiran).
        [$user, $shop] = $this->createRetailerTenant();

        // Make dhiran self-serve: dhiran product is seeded active; give it plans.
        $this->priceProduct('dhiran');

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'services']));

        $response->assertOk();
        // The buy-now affordance for the priced, active product.
        $response->assertSee('Buy &amp; activate now', false);
        $response->assertSee('data-product="dhiran"', false);
        // Price the owner sees (yearly 14,999 / monthly 1,499).
        $response->assertSee('1,499');
        $response->assertSee('14,999');
        // The hidden callback form must escape the turbo-frame on redirect.
        $response->assertSee('action="' . route('settings.services.add-callback') . '"', false);
    }

    public function test_non_purchasable_service_keeps_request_form(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        // Manufacturing product has NO priced plan in this test → not self-serve.
        // (Do not price it.) It must fall back to the admin-review request form.
        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'services']));

        $response->assertOk();
        $response->assertSee('Ask to turn this on', false);
        $response->assertSee('Send request', false);
    }

    public function test_inactive_product_is_not_purchasable_even_with_a_plan(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        // Price dhiran but flip the product inactive: must NOT be buyable.
        $this->priceProduct('dhiran');
        DB::table('platform_products')->where('code', 'dhiran')->update(['is_active' => DB::raw('false')]);

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'services']));

        $response->assertOk();
        $response->assertDontSee('data-product="dhiran"', false);
    }
}
