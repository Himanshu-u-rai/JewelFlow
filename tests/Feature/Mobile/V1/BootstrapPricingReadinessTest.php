<?php

namespace Tests\Feature\Mobile\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * /bootstrap pricing-readiness contract.
 *
 * The mobile PricingGate for staff/cashier roles reads rate readiness from
 * /bootstrap (all-roles), NOT /dashboard (reports.view-gated). This pins that
 * contract: a user WITHOUT reports.view still learns whether today's rates are
 * set, while the payload exposes only readiness — never KPIs or report data.
 */
class BootstrapPricingReadinessTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /**
     * Strip the reports.view permission so the user mirrors a cashier — the
     * role that is denied /dashboard yet must still boot the app.
     */
    private function makeCashierLike(\App\Models\User $user): void
    {
        \App\Models\Role::withoutTenant()
            ->findOrFail($user->role_id)
            ->revokePermission('reports.view');
    }

    public function test_cashier_sees_rates_ready_when_owner_has_set_them(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user, [
            'gold_24k_rate_per_gram' => 7200,
            'silver_999_rate_per_gram' => 92,
        ]);
        $this->makeCashierLike($user);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/bootstrap');

        $response->assertOk();
        $response->assertJsonPath('pricing.rates_set_today', true);
        $response->assertJsonPath('pricing.pricing_ready', true);
        // Whole-number rates serialize as JSON integers (no float/int distinction).
        $response->assertJsonPath('pricing.gold_24k_rate_per_gram', 7200);
        $response->assertJsonPath('pricing.silver_999_rate_per_gram', 92);
        $response->assertJsonPath('pricing.business_date', now()->toDateString());

        // Readiness only — no KPIs, revenue, or report data leak through.
        $response->assertJsonMissingPath('pricing.revenue');
        $response->assertJsonMissingPath('pricing.profit');
        $response->assertJsonMissingPath('dashboard');
    }

    public function test_cashier_sees_rates_not_ready_when_owner_has_not_set_them(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeCashierLike($user);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/mobile/bootstrap');

        $response->assertOk();
        $response->assertJsonPath('pricing.rates_set_today', false);
        $response->assertJsonPath('pricing.pricing_ready', false);
        $response->assertJsonPath('pricing.gold_24k_rate_per_gram', null);
        $response->assertJsonPath('pricing.silver_999_rate_per_gram', null);
        $response->assertJsonPath('pricing.business_date', null);
    }
}
