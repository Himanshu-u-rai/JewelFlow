<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformProduct;
use App\Models\Platform\ShopSubscription;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Self-serve "Buy & activate now" UI + checkout callback on the Settings →
 * Business Editions tab.
 *
 * The buy-now-vs-request split is decided server-side in
 * SettingsController::buildServicePurchaseOptions():
 *   - product is active AND has an active plan with a real price  → buy-now card
 *   - otherwise (inactive product, or no priced plan)             → request-add form
 *
 * The add-callback path IS exercised here with the Razorpay-touching methods of
 * SubscriptionPaymentService mocked (signature / order / amount / capture), so a
 * verified, captured payment is simulated while the REAL createSubscription, the
 * M1/L1 eligibility guards, and the edition grant all run. Only the literal
 * browser↔Razorpay hop is left to a manual sandbox smoke test.
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

    // ── add-callback: server-side checkout completion ───────────────

    /**
     * Bind a SubscriptionPaymentService whose Razorpay-touching methods are
     * stubbed to simulate a verified, captured payment for $plan. createSubscription
     * is NOT stubbed — the real one runs (it reads Auth for shop_id and grants the
     * edition), so the genuine M1/L1 guards and edition wiring are exercised.
     */
    private function fakePaymentService(Plan $plan, string $orderId, string $paymentId, int $userId): void
    {
        $order = (object) [
            'id'     => $orderId,
            'amount' => (int) round((float) $plan->price_yearly * 100),
            'notes'  => ['plan_id' => $plan->id, 'billing_cycle' => 'yearly', 'user_id' => $userId],
        ];

        $mock = $this->createMock(SubscriptionPaymentService::class);
        $mock->method('verifyPaymentSignature');           // void, no throw = valid signature
        $mock->method('fetchAndValidateOrder')->willReturn([
            'order' => $order, 'plan' => $plan, 'billing_cycle' => 'yearly',
        ]);
        $mock->method('verifyAmount')->willReturn((int) $order->amount);
        $mock->method('verifyPaymentCaptured');            // void, no throw = captured
        $mock->method('findExistingSubscription')->willReturn(null);
        // Real createSubscription runs through the container's actual service,
        // so delegate it to a real instance.
        $real = new SubscriptionPaymentService();
        $mock->method('createSubscription')->willReturnCallback(
            fn (...$args) => $real->createSubscription(...$args)
        );

        $this->app->instance(SubscriptionPaymentService::class, $mock);
    }

    public function test_add_callback_creates_subscription_and_grants_edition(): void
    {
        [$user, $shop] = $this->createRetailerTenant();   // owns retailer, not dhiran
        $this->priceProduct('dhiran');
        $plan = Plan::where('platform_product_id', PlatformProduct::where('code', 'dhiran')->value('id'))
            ->whereNotNull('price_yearly')->first();

        $this->actingAs($user);
        $this->fakePaymentService($plan, 'order_TEST1', 'pay_TEST1', $user->id);
        // initiateAdd stores these; addCallback cross-checks the session plan id.
        session(['services_add_plan_id' => $plan->id]);

        $response = $this->post(route('settings.services.add-callback'), [
            'razorpay_payment_id' => 'pay_TEST1',
            'razorpay_order_id'   => 'order_TEST1',
            'razorpay_signature'  => 'sig_TEST1',
        ]);

        $response->assertRedirect(route('settings.edit', ['tab' => 'services']));
        $response->assertSessionHas('success');

        // A real dhiran subscription now exists and granted the dhiran edition.
        $this->assertDatabaseHas('shop_subscriptions', [
            'shop_id'             => $shop->id,
            'razorpay_payment_id' => 'pay_TEST1',
            'status'              => 'active',
        ]);
        $this->assertTrue($shop->fresh()->hasEdition('dhiran'));
        $this->assertDatabaseHas('shop_editions', [
            'shop_id' => $shop->id, 'edition' => 'dhiran', 'source' => 'subscription',
        ]);
    }

    public function test_add_callback_rejects_when_order_user_does_not_match_caller(): void
    {
        // L1: the server-issued order is bound to its initiating user via notes.user_id.
        [$user, $shop] = $this->createRetailerTenant();
        $this->priceProduct('dhiran');
        $plan = Plan::where('platform_product_id', PlatformProduct::where('code', 'dhiran')->value('id'))
            ->whereNotNull('price_yearly')->first();

        $this->actingAs($user);
        // Order's note user_id is a DIFFERENT user → must be refused.
        $this->fakePaymentService($plan, 'order_X', 'pay_X', $user->id + 9999);

        $response = $this->post(route('settings.services.add-callback'), [
            'razorpay_payment_id' => 'pay_X',
            'razorpay_order_id'   => 'order_X',
            'razorpay_signature'  => 'sig_X',
        ]);

        $response->assertSessionHas('error');
        $this->assertDatabaseMissing('shop_subscriptions', ['razorpay_payment_id' => 'pay_X']);
        $this->assertFalse($shop->fresh()->hasEdition('dhiran'));
    }

    public function test_add_callback_skips_when_shop_already_owns_the_service(): void
    {
        // M1: if the edition was acquired after initiate, don't create a duplicate.
        [$user, $shop] = $this->createRetailerTenant();
        $this->priceProduct('dhiran');
        $plan = Plan::where('platform_product_id', PlatformProduct::where('code', 'dhiran')->value('id'))
            ->whereNotNull('price_yearly')->first();

        // Grant dhiran by another path first (admin grant).
        \App\Support\ShopEdition::grantTo($shop, 'dhiran', null, \App\Models\ShopEditionAssignment::SOURCE_ADMIN_GRANT);

        $this->actingAs($user);
        $this->fakePaymentService($plan, 'order_DUP', 'pay_DUP', $user->id);

        $response = $this->post(route('settings.services.add-callback'), [
            'razorpay_payment_id' => 'pay_DUP',
            'razorpay_order_id'   => 'order_DUP',
            'razorpay_signature'  => 'sig_DUP',
        ]);

        $response->assertRedirect(route('settings.edit', ['tab' => 'services']));
        // No new subscription created (the edition was already owned).
        $this->assertDatabaseMissing('shop_subscriptions', ['razorpay_payment_id' => 'pay_DUP']);
    }
}
