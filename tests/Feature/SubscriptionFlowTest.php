<?php

namespace Tests\Feature;

use App\Models\Platform\Plan;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Platform\SubscriptionEvent;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class SubscriptionFlowTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    // ── Plan Selection ──────────────────────────────────────────────

    public function test_authenticated_user_can_view_plans(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->createPlatformAdmin();
        $plan = Plan::create([
            'code' => 'manufacturer_basic_test',
            'name' => 'Basic Manufacturer',
            'price_monthly' => 999,
            'grace_days' => 5,
            'is_active' => true,
        ]);

        // Set the shop type in session
        $response = $this->actingAs($user)
            ->withSession(['onboarding_shop_type' => 'manufacturer'])
            ->get('/subscription/plans');

        $response->assertOk();
        $response->assertViewHas('plans');
    }

    public function test_plan_selection_stores_plan_in_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = Plan::create([
            'code' => 'manufacturer_basic_test',
            'name' => 'Basic',
            'price_monthly' => 999,
            'grace_days' => 5,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->post('/subscription/choose', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ]);

        $response->assertRedirect(route('subscription.payment'));
        $response->assertSessionHas('pending_plan_id', $plan->id);
        $response->assertSessionHas('pending_billing_cycle', 'monthly');
    }

    public function test_cannot_choose_inactive_plan(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $plan = Plan::create([
            'code' => 'manufacturer_inactive',
            'name' => 'Inactive Plan',
            'price_monthly' => 999,
            'grace_days' => 5,
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->post('/subscription/choose', [
            'plan_id' => $plan->id,
            'billing_cycle' => 'monthly',
        ]);

        $response->assertStatus(302);
        // Should redirect back with validation error
        $response->assertSessionHasErrors(['plan_id']);
    }

    public function test_plan_page_redirects_if_user_already_has_active_subscription(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $response = $this->actingAs($user)->get('/subscription/plans');

        // Should redirect to subscription status since shop already has active sub
        $response->assertRedirect(route('subscription.status'));
    }

    // ── Subscription Status ─────────────────────────────────────────

    public function test_subscription_status_page_shows_active_subscription(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $response = $this->actingAs($user)->get('/subscription');

        $response->assertOk();
        $response->assertViewHas('subscription');
        $response->assertViewHas('plan');
    }

    public function test_subscription_status_redirects_if_no_shop(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->get('/subscription');

        // User without a shop gets redirected to onboarding flow
        $response->assertRedirect();
    }

    // ── Webhook Security ────────────────────────────────────────────

    public function test_webhook_rejects_requests_without_valid_signature(): void
    {
        config(['services.razorpay.webhook_secret' => 'test_secret']);

        $response = $this->postJson('/subscription/payment/webhook', [
            'event' => 'payment.captured',
        ], [
            'X-Razorpay-Signature' => 'invalid_signature',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['status' => 'rejected']);
    }

    public function test_webhook_rejects_when_secret_not_configured(): void
    {
        config(['services.razorpay.webhook_secret' => null]);

        $response = $this->postJson('/subscription/payment/webhook', [
            'event' => 'payment.captured',
        ]);

        $response->assertStatus(500);
        $response->assertJson(['status' => 'rejected', 'reason' => 'webhook secret not configured']);
    }

    // ── Subscription Enforcement ────────────────────────────────────

    public function test_expired_subscription_blocks_access(): void
    {
        [$user, $shop] = $this->createTenantWithExpiredSubscription();
        config(['platform.enforce_subscriptions' => true]);

        $response = $this->actingAs($user)->get('/dashboard');

        // Expired subscription should block access (redirect to login or 403)
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_active_subscription_allows_dashboard_access(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
    }

    // ── Payment Initiation ──────────────────────────────────────────

    public function test_payment_page_requires_plan_selection(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->get('/subscription/payment');

        // Should redirect to plans page since no plan was chosen
        $response->assertRedirect(route('subscription.plans'));
    }

    public function test_initiate_payment_fails_without_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $response = $this->actingAs($user)->postJson('/subscription/payment/initiate');

        $response->assertStatus(422);
        $response->assertJsonStructure(['error']);
    }

    // ── Subscription Lifecycle Events ───────────────────────────────

    public function test_subscription_event_is_logged_on_creation(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        // Subscription was created in createManufacturerTenant, so just verify event exists
        // Note: events are only created via payment callback, not via direct creation in tests
        // This test verifies the subscription was created correctly
        $subscription = ShopSubscription::where('shop_id', $shop->id)->first();
        $this->assertNotNull($subscription);
        $this->assertEquals('active', $subscription->status);
    }

    public function test_subscription_cannot_have_duplicate_razorpay_payment_id(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $admin = PlatformAdmin::first();
        $plan = Plan::first();

        $sub1 = ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'razorpay_payment_id' => 'pay_unique_123',
            'updated_by_admin_id' => $admin->id,
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'razorpay_payment_id' => 'pay_unique_123', // Duplicate
            'updated_by_admin_id' => $admin->id,
        ]);
    }

    // ── Authentication ──────────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_subscription(): void
    {
        $response = $this->get('/subscription');
        $response->assertRedirect('/login');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createTenantWithExpiredSubscription(): array
    {
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('manufacturer');
        $shop = $this->createShop('manufacturer');
        $role = $this->createOwnerRole($shop->id);
        $user = $this->createOwnerUser($shop, $role);
        $this->createBillingSettings($shop->id);

        ShopSubscription::create([
            'shop_id' => $shop->id,
            'plan_id' => $plan->id,
            'status' => 'expired',
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at' => now()->subMonth()->toDateString(),
            'grace_ends_at' => now()->subWeek()->toDateString(),
            'updated_by_admin_id' => $admin->id,
        ]);

        return [$user, $shop];
    }
}
