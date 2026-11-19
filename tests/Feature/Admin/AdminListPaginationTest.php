<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAnnouncement;
use App\Models\Platform\PlatformFraudFlag;
use App\Models\Platform\ShopSubscription;
use App\Models\ShopEditionRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Platform-admin lists sorted by a time that can tie (bulk imports, seeders, one
 * busy second) must show every row on exactly one page, newest id first.
 */
class AdminListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    private const TIED = '2026-11-01 10:00:00';

    private function walk(string $table, string $url, string $var, int $perPage, array $ids, string $col = 'created_at', ?string $idCol = null): void
    {
        $this->skipIfNotPostgres();
        $this->withoutVite();
        $this->tieAndScramble($table, $ids, [$col => self::TIED]);
        rsort($ids);

        $admin = PlatformAdmin::query()->first() ?? $this->createPlatformAdmin();
        $sep = str_contains($url, '?') ? '&' : '?';
        $page = fn (int $p) => $this->actingAs($admin, 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true])
            ->get($url . $sep . 'page=' . $p)->assertOk()->viewData($var)->pluck($idCol ?? 'id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, $perPage, $url);
        $this->assertSame(array_slice($ids, 0, $perPage), $page(1), "{$url}: page 1 is the newest ids");
    }

    public function test_users(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createPlatformAdmin();
        User::factory()->count(55)->create(['shop_id' => $shop->id, 'role_id' => $owner->role_id, 'is_active' => true]);

        $this->walk('users', '/admin/users', 'users', 25, DB::table('users')->pluck('id')->all());
    }

    public function test_platform_admins(): void
    {
        $this->skipIfNotPostgres();
        foreach (range(1, 55) as $n) {
            $this->createPlatformAdmin();
        }

        $this->walk('platform_admins', '/admin/platform-admins', 'platformAdmins', 25, DB::table('platform_admins')->pluck('id')->all());
    }

    public function test_shops(): void
    {
        $this->skipIfNotPostgres();
        $this->createPlatformAdmin();
        foreach (range(1, 45) as $n) {
            $this->createShop('retailer');
        }

        $this->walk('shops', '/admin/shops', 'shops', 20, DB::table('shops')->pluck('id')->all());
    }

    public function test_edition_requests(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();
        $ids = [];
        foreach (range(1, 55) as $n) {
            $ids[] = ShopEditionRequest::withoutGlobalScopes()->create([
                'shop_id' => $shop->id, 'user_id' => $owner->id, 'action' => ShopEditionRequest::ACTION_ADD,
                'edition' => 'dhiran', 'reason' => 'r' . $n, 'status' => ShopEditionRequest::STATUS_DENIED,
            ])->id;
        }

        $this->walk('shop_edition_requests', '/admin/edition-requests?status=denied', 'requests', 25, $ids);
    }

    public function test_fraud_flags(): void
    {
        $this->skipIfNotPostgres();
        [, $shop] = $this->createRetailerTenant();
        $ids = [];
        foreach (range(1, 55) as $n) {
            $ids[] = PlatformFraudFlag::create(['shop_id' => $shop->id, 'flag_type' => PlatformFraudFlag::TYPE_INVOICE_SPIKE, 'flag_data' => ['n' => $n]])->id;
        }

        $this->walk('platform_fraud_flags', '/admin/fraud-flags', 'flags', 25, $ids);
    }

    public function test_announcements(): void
    {
        $this->skipIfNotPostgres();
        $ids = [];
        foreach (range(1, 45) as $n) {
            $ids[] = DB::table('platform_announcements')->insertGetId([
                'title' => 'Notice ' . $n, 'body' => 'Body', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->walk('platform_announcements', '/admin/announcements', 'announcements', 20, $ids);
    }

    public function test_subscriptions(): void
    {
        $this->skipIfNotPostgres();
        $admin = $this->createPlatformAdmin();
        $plan = $this->createPlan('retailer');
        $ids = [];
        foreach (range(1, 55) as $n) {
            $shop = $this->createShop('retailer');
            $ids[] = $this->createSubscription($shop->id, $admin, $plan)->id;
        }

        $this->walk('shop_subscriptions', '/admin/subscriptions', 'subscriptions', 25, $ids);
    }

    public function test_compliance_alerts(): void
    {
        $this->skipIfNotPostgres();
        [, $shop] = $this->createRetailerTenant();
        $ids = [];
        foreach (range(1, 55) as $n) {
            $ids[] = DB::table('compliance_alerts')->insertGetId([
                'shop_id' => $shop->id, 'alert_type' => 'pan_required', 'alert_data' => json_encode(['n' => $n]),
                'resolved' => DB::raw('false'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->walk('compliance_alerts', '/admin/compliance-alerts', 'alerts', 25, $ids);
    }

    public function test_unresolved_payments(): void
    {
        $this->skipIfNotPostgres();
        [, $shop] = $this->createRetailerTenant();
        $ids = [];
        foreach (range(1, 55) as $n) {
            $ids[] = DB::table('subscription_events')->insertGetId([
                'shop_id' => $shop->id, 'event_type' => 'payment.unresolved',
                'after' => json_encode(['payment_id' => 'pay_' . $n]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->walk('subscription_events', '/admin/unresolved-payments', 'events', 25, $ids);
    }

    public function test_platform_invoices(): void
    {
        $this->skipIfNotPostgres();
        [, $shop] = $this->createRetailerTenant();
        $plan = $this->createPlan('retailer');
        $ids = [];
        foreach (range(1, 55) as $n) {
            $ids[] = DB::table('platform_invoices')->insertGetId([
                'shop_id' => $shop->id, 'plan_id' => $plan->id, 'invoice_number' => 'PI-' . $n, 'invoice_sequence' => $n,
                'billing_cycle' => 'monthly', 'billing_period_start' => '2026-11-01', 'billing_period_end' => '2026-11-30',
                'amount_before_tax' => 100, 'gst_rate' => 18, 'gst_amount' => 18, 'total_amount' => 118,
                'status' => 'issued', 'issued_at' => self::TIED, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->walk('platform_invoices', '/admin/invoices', 'invoices', 25, $ids, 'issued_at');
    }
}
