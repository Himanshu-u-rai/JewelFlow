<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Customer;
use App\Models\IdempotencyKey;
use App\Models\Platform\PlatformSetting;
use App\Models\QuickBill;
use App\Models\QuickBillPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Platform maintenance (Settings → Maintenance Mode, PlatformSetting
 * maintenance_mode) — not Laravel's `artisan down`.
 *
 * While it is ON: platform admins can still sign in, pass MFA, reach the
 * dashboard and Settings and switch it OFF; every tenant shop operation, on the
 * web and on both mobile API versions (already-issued tokens included), is
 * refused before it can book anything or claim an idempotency key. After it is
 * OFF, the retry the app queued runs exactly once.
 */
class PlatformMaintenanceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const MESSAGE = 'Synthetic maintenance window.';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function maintenance(bool $on): void
    {
        PlatformSetting::set('maintenance_mode', $on ? 'true' : 'false');
        PlatformSetting::set('maintenance_message', self::MESSAGE);
    }

    private function asAdmin($admin = null)
    {
        return $this->actingAs($admin ?? $this->createPlatformAdmin(), 'platform_admin')
            ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
    }

    // ── platform admins keep working ────────────────────────────────────────

    public function test_admin_login_mfa_and_bare_paths_are_reachable_during_maintenance(): void
    {
        $this->maintenance(true);

        $this->get('/admin/login')->assertOk()->assertDontSee(self::MESSAGE);
        $this->get('/super-admin/login')->assertRedirect('/admin/login');
        // Signed out: the bare paths lead to the login page, not the maintenance page.
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/super-admin')->assertRedirect();
        $this->get('/super-admin')->assertDontSee(self::MESSAGE);

        $admin = $this->createPlatformAdmin();
        $this->post('/admin/login', ['mobile_number' => $admin->mobile_number, 'password' => 'password'])
            ->assertRedirect();
        $this->assertAuthenticatedAs($admin, 'platform_admin');
        // Password alone is not enough: the MFA gate still applies.
        $this->get('/admin')->assertRedirect(route('admin.mfa.show'));
        $this->get(route('admin.mfa.show'))->assertOk()->assertDontSee(self::MESSAGE);
    }

    public function test_an_authenticated_admin_reaches_the_dashboard_and_settings_and_can_end_maintenance(): void
    {
        $this->maintenance(true);
        $admin = $this->createPlatformAdmin();

        $this->asAdmin($admin)->get('/admin')->assertOk()->assertDontSee(self::MESSAGE);
        $this->asAdmin($admin)->get('/super-admin')->assertRedirect(route('admin.dashboard'));
        $this->asAdmin($admin)->get(route('admin.settings.index'))->assertOk()->assertSee('Maintenance Mode');

        $this->asAdmin($admin)->patch(route('admin.settings.update'), [
            'retailer_enabled' => '1', 'manufacturer_enabled' => '1', 'dhiran_enabled' => '1', 'maintenance_mode' => '0',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertFalse(PlatformSetting::bool('maintenance_mode', false));
    }

    public function test_admin_role_checks_still_apply_during_maintenance(): void
    {
        $this->maintenance(true);
        $this->createPlatformAdmin(); // the remaining super admin
        $operator = $this->createPlatformAdmin();
        $operator->forceFill(['role' => 'platform_operator'])->save();

        $this->asAdmin($operator)->get(route('admin.settings.index'))->assertForbidden();
        $this->asAdmin($operator)->patch(route('admin.settings.update'), ['maintenance_mode' => '0'])->assertForbidden();
        $this->assertTrue(PlatformSetting::bool('maintenance_mode', false));
    }

    public function test_an_expired_admin_session_is_sent_to_sign_in_not_let_through(): void
    {
        $this->maintenance(true);

        $this->get(route('admin.settings.index'))->assertRedirect(route('admin.login'));
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->patch(route('admin.settings.update'), ['maintenance_mode' => '0'])->assertRedirect(route('admin.login'));
        $this->assertTrue(PlatformSetting::bool('maintenance_mode', false));
    }

    // ── tenants are refused, web ───────────────────────────────────────────

    public function test_tenant_web_pages_and_mutations_show_the_maintenance_page_and_change_nothing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->maintenance(true);

        $this->actingAs($owner)->get('/dashboard')->assertStatus(503)->assertSee(self::MESSAGE)
            ->assertHeader('Retry-After');
        $this->actingAs($owner)->post('/customers', ['first_name' => 'Synthetic', 'last_name' => 'Example', 'mobile' => '9000097001'])
            ->assertStatus(503);
        $this->actingAs($owner)->postJson('/customers', ['first_name' => 'Synthetic', 'last_name' => 'Example'])
            ->assertStatus(503)->assertJsonPath('maintenance', true)->assertJsonPath('message', self::MESSAGE);
        $this->assertSame(0, Customer::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    public function test_a_platform_admin_session_is_not_a_way_around_tenant_maintenance(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->maintenance(true);

        $this->actingAs($this->createPlatformAdmin(), 'platform_admin')->actingAs($owner)
            ->get('/dashboard')->assertStatus(503);
    }

    // ── tenants are refused, mobile API (both versions, live tokens) ───────

    public function test_both_mobile_api_versions_refuse_with_a_maintenance_response_for_a_live_token(): void
    {
        [$owner] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $this->maintenance(true);

        $this->getJson('/api/mobile/dashboard')->assertStatus(503)
            ->assertJsonPath('maintenance', true)->assertJsonPath('message', self::MESSAGE)
            ->assertJsonPath('errors.0.code', 'maintenance');
        $this->getJson('/api/mobile/v1/sessions/me')->assertStatus(503)
            ->assertJsonPath('errors.0.code', 'maintenance')->assertJsonPath('errors.0.message', self::MESSAGE)
            ->assertJsonPath('data', null)->assertJsonStructure(['meta' => ['request_id']]);
        // No new tokens either (the app signs in through the legacy route).
        $this->postJson('/api/mobile/auth/login', ['mobile_number' => $owner->mobile_number, 'password' => 'password'])
            ->assertStatus(503);
    }

    public function test_a_refused_bill_books_nothing_claims_no_key_and_runs_once_after_maintenance(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $key = 'maint-qb-0001-aaaa-bbbb';
        $this->maintenance(true);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/mobile/quick-bills', $this->quickBill(), ['X-Idempotency-Key' => $key])->assertStatus(503);
        }
        $this->assertBooked($shop->id, 0);
        $this->assertSame(0, IdempotencyKey::query()->where('key', $key)->count(), 'a refused request must not claim its key');

        $this->maintenance(false);
        $first = $this->postJson('/api/mobile/quick-bills', $this->quickBill(), ['X-Idempotency-Key' => $key])->assertCreated();
        $replay = $this->postJson('/api/mobile/quick-bills', $this->quickBill(), ['X-Idempotency-Key' => $key])->assertCreated();
        $replay->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($first->json('quick_bill.id'), $replay->json('quick_bill.id'));
        $this->assertBooked($shop->id, 1);
    }

    public function test_v1_idempotent_mutations_claim_no_key_during_maintenance(): void
    {
        [$owner] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        $this->maintenance(true);

        $this->postJson('/api/mobile/v1/cashbook', ['type' => 'in', 'amount' => 100], ['X-Idempotency-Key' => 'maint-v1-0001-aaaa'])
            ->assertStatus(503)->assertJsonPath('errors.0.code', 'maintenance');
        $this->assertSame(0, IdempotencyKey::query()->count());
    }

    // ── what stays reachable, explicitly ───────────────────────────────────

    public function test_health_checks_and_the_signed_payment_webhook_are_not_blocked(): void
    {
        $this->maintenance(true);

        $this->get('/health')->assertOk();
        $this->get('/ping')->assertOk();
        $this->get('/up')->assertOk();
        // Razorpay's server-to-server notice for money already taken: it must be
        // able to land. Its own signature check still decides (this unsigned one fails it).
        $webhook = $this->postJson('/subscription/payment/webhook', ['event' => 'synthetic']);
        $this->assertNotSame(503, $webhook->status());
        $this->assertNull($webhook->json('maintenance'));
    }

    public function test_switching_off_restores_normal_tenant_operation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->maintenance(true);
        $this->actingAs($owner)->get('/dashboard')->assertStatus(503);

        $this->maintenance(false);
        $this->actingAs($owner)->get('/customers')->assertOk();
        $this->actingAs($owner)->post('/customers', ['first_name' => 'Synthetic', 'last_name' => 'Example', 'mobile' => '9000097002']);
        $this->assertSame(1, Customer::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    private function assertBooked(int $shopId, int $bills): void
    {
        $ids = QuickBill::withoutGlobalScopes()->where('shop_id', $shopId)->pluck('id');
        $this->assertCount($bills, $ids, 'quick bills');
        $this->assertSame($bills, QuickBillPayment::withoutGlobalScopes()->whereIn('quick_bill_id', $ids)->count(), 'payments');
    }

    private function quickBill(): array
    {
        return [
            'bill_date' => now()->toDateString(), 'pricing_mode' => 'no_gst', 'gst_rate' => 0, 'round_off' => 0,
            'save_action' => 'issue', 'customer_name' => 'Synthetic Walk-in',
            'items' => [[
                'description' => 'Synthetic quick bill item', 'pcs' => 1, 'gross_weight' => 1, 'stone_weight' => 0,
                'net_weight' => 1, 'rate' => 1000, 'making_charge' => 0, 'stone_charge' => 0, 'wastage_percent' => 0, 'line_discount' => 0,
            ]],
            'payments' => [['payment_mode' => 'cash', 'amount' => 1000]],
        ];
    }
}
