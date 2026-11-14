<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use App\Models\User;
use App\Services\SubscriptionPaymentService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit N22 follow-up: one rule, no write path bypasses it. A shop with a pending GDPR deletion never goes back to
 * access_mode 'active' - not through a payment, the expiry/repair jobs, the middleware time-box auto-clear or
 * restore, nor a raw query-builder update (database trigger).
 */
class GdprPendingDeletionReactivationPathsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const KEY_SECRET = 'test_secret_for_hmac';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config([
            'services.razorpay.key_id'     => 'rzp_test_key',
            'services.razorpay.key_secret' => self::KEY_SECRET,
        ]);

        Route::middleware(['web', 'auth', 'tenant', 'subscription.active', 'account.active', 'shop.exists'])
            ->get('/_gp/erp-read', fn () => response('ok'));
        Route::middleware(['web', 'auth', 'tenant', 'account.active'])
            ->get('/_gp/acct-read', fn () => response('ok'));
    }

    /** @return array{0: User, 1: Shop, 2: Plan} */
    private function tenant(): array
    {
        $this->createPlatformAdmin();
        $plan  = $this->createPlan('retailer');
        $shop  = $this->createShop('retailer');
        $owner = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));
        $this->createBillingSettings($shop->id);
        $this->markShopOpeningSetupComplete($shop->id);

        return [$owner, $shop->refresh(), $plan];
    }

    private function sub(Shop $shop, Plan $plan, array $attrs = []): ShopSubscription
    {
        return ShopSubscription::create(array_merge([
            'shop_id'   => $shop->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => now()->subMonths(2)->toDateString(),
            'ends_at'   => now()->addMonth()->toDateString(),
        ], $attrs));
    }

    private function mark(Shop $shop): void
    {
        DB::table('platform_settings')->insert([
            'key'   => 'gdpr_pending_delete_shop_' . $shop->id,
            'value' => json_encode(['reason' => 'test']),
        ]);
    }

    /** The shop as scheduleDelete leaves it, except the attribution is configurable. */
    private function suspendedPending(Shop $shop, ?int $by, array $extra = []): void
    {
        $shop->forceFill(array_merge([
            'access_mode'       => 'suspended',
            'is_active'         => false,
            'suspended_at'      => now()->subDay(),
            'suspension_reason' => $by ? 'GDPR deletion scheduled: erasure' : 'Subscription expired',
            'suspended_by'      => $by,
        ], $extra))->save();
        $this->mark($shop);
    }

    private function assertStillSuspended(Shop $shop): void
    {
        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode);
        $this->assertFalse((bool) $fresh->is_active);
    }

    // ── Middleware paths ────────────────────────────────────────────────────

    public function test_expired_time_box_does_not_clear_a_gdpr_pending_suspension(): void
    {
        [$owner, $shop] = $this->tenant();
        $this->sub($shop, $this->createPlan('retailer'));
        $admin = $this->createPlatformAdmin();
        // a time-boxed hold whose box has lapsed, THEN the deletion is scheduled through the real route
        $shop->forceFill([
            'access_mode' => 'suspended', 'is_active' => false, 'suspended_at' => now()->subDays(9),
            'suspended_by' => $admin->id, 'suspended_until' => now()->subDay(), 'suspension_reason' => 'hold',
        ])->save();
        $this->actingAs($admin, 'platform_admin')->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->post(route('admin.shops.gdpr-delete', $shop), ['reason' => 'Owner asked for erasure'])->assertRedirect();
        $this->app['auth']->guard('web')->logout();

        $this->actingAs($owner)->get('/_gp/acct-read')->assertRedirect('/login');

        $this->assertStillSuspended($shop);
    }

    public function test_unattributed_pending_shop_is_not_restored_by_the_subscription_middleware(): void
    {
        config(['platform.enforce_subscriptions' => false]);
        [$owner, $shop, $plan] = $this->tenant();
        $this->sub($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subDays(40)->toDateString()]);
        $this->suspendedPending($shop, null);

        $response = $this->actingAs($owner)->get('/_gp/erp-read');

        $this->assertNotSame(200, $response->getStatusCode());
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertStillSuspended($shop);
    }

    // ── Classifier ──────────────────────────────────────────────────────────

    public function test_pending_deletion_counts_as_administrative_even_without_a_stamp(): void
    {
        [, $shop] = $this->tenant();
        $this->suspendedPending($shop, null);

        $this->assertTrue($shop->fresh()->suspensionIsAdministrative());
        $this->assertFalse($shop->fresh()->suspensionIsSubscriptionManaged());
    }

    // ── Jobs ────────────────────────────────────────────────────────────────

    public function test_repair_command_leaves_a_pending_shop_suspended_and_still_repairs_the_term(): void
    {
        [, $shop, $plan] = $this->tenant();
        $startsAt = now()->subDays(3);
        $sub = $this->sub($shop, $plan, [
            'status' => 'expired', 'billing_cycle' => 'yearly',
            'starts_at' => $startsAt->toDateString(), 'ends_at' => $startsAt->copy()->addDays(7)->toDateString(),
            'grace_ends_at' => $startsAt->copy()->addDays(12)->toDateString(), 'price_paid' => 9999,
            'razorpay_payment_id' => 'pay_' . fake()->unique()->numerify('##########'),
        ]);
        $this->suspendedPending($shop, null);

        $this->artisan('subscription:repair-trial-terms', ['--commit' => true])->assertSuccessful();

        $this->assertSame('active', $sub->fresh()->status);
        $this->assertStillSuspended($shop);
    }

    // ── Payment ─────────────────────────────────────────────────────────────

    /** @dataProvider stamps */
    public function test_captured_renewal_is_recorded_but_does_not_reopen_a_pending_shop(bool $stamped): void
    {
        config(['platform.enforce_subscriptions' => true]);
        [$owner, $shop, $plan] = $this->tenant();
        $this->sub($shop, $plan, ['status' => 'expired', 'ends_at' => now()->subMonth()->toDateString(), 'user_id' => $owner->id]);
        $this->suspendedPending($shop, $stamped ? $this->createPlatformAdmin()->id : null);

        $order = (object) [
            'id' => 'order_GP', 'amount' => (int) round($plan->price_monthly * 100), 'currency' => 'INR',
            'notes' => ['user_id' => $owner->id, 'plan_id' => $plan->id, 'billing_cycle' => 'monthly'],
        ];
        $svc = Mockery::mock(SubscriptionPaymentService::class)->makePartial();
        $svc->shouldReceive('fetchAndValidateOrder')->andReturn(['order' => $order, 'plan' => $plan, 'billing_cycle' => 'monthly']);
        $svc->shouldReceive('verifyPaymentCaptured')->andReturnNull();
        $this->app->instance(SubscriptionPaymentService::class, $svc);

        $this->actingAs($owner)->post(route('subscription.payment.callback'), [
            'razorpay_order_id'   => 'order_GP',
            'razorpay_payment_id' => 'pay_GP',
            'razorpay_signature'  => hash_hmac('sha256', 'order_GP|pay_GP', self::KEY_SECRET),
        ]);

        $this->assertSame(1, ShopSubscription::where('razorpay_payment_id', 'pay_GP')->count(), 'money is recorded');
        $this->assertStillSuspended($shop);
    }

    public static function stamps(): array
    {
        return ['stamped by admin' => [true], 'unstamped' => [false]];
    }

    // ── Database guard ──────────────────────────────────────────────────────

    public function test_raw_query_builder_update_cannot_reactivate_a_pending_shop(): void
    {
        [, $shop] = $this->tenant();
        $this->suspendedPending($shop, $this->createPlatformAdmin()->id);

        try {
            // savepoint, so the aborted statement does not poison the test transaction
            DB::transaction(fn () => DB::table('shops')->where('id', $shop->id)->update(['access_mode' => 'active', 'is_active' => DB::raw('true')]));
            $this->fail('expected the database to refuse the reactivation');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('GDPR deletion pending', $e->getMessage());
        }
        $this->assertStillSuspended($shop);
    }

    public function test_database_guard_lets_an_ordinary_shop_and_a_withdrawn_deletion_reactivate(): void
    {
        [, $plain] = $this->tenant();
        $plain->forceFill(['access_mode' => 'suspended', 'is_active' => false])->save();
        DB::table('shops')->where('id', $plain->id)->update(['access_mode' => 'active', 'is_active' => DB::raw('true')]);
        $this->assertSame('active', $plain->fresh()->access_mode);

        [, $withdrawn] = $this->tenant();
        $this->suspendedPending($withdrawn, null);
        DB::table('platform_settings')->where('key', 'gdpr_pending_delete_shop_' . $withdrawn->id)->delete();
        DB::table('shops')->where('id', $withdrawn->id)->update(['access_mode' => 'active', 'is_active' => DB::raw('true')]);
        $this->assertSame('active', $withdrawn->fresh()->access_mode);
    }
}
