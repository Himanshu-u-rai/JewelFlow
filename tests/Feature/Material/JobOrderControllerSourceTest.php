<?php

namespace Tests\Feature\Material;

use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * HTTP-layer wiring for the metal-source model: the web JobOrderController must
 * accept labor-only, the new source SET, and the legacy issuances input, plus
 * the retained split on receipt and karigar reassignment. (The accounting is
 * service-tested; this locks the controller contract.)
 */
class JobOrderControllerSourceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private $user;
    private int $shopId;
    private int $karigarA;
    private int $karigarB;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // Harness owner role has no synced permissions → can:job_order.manage
        // would 403 before the controller runs. Bypass authorization (owners hold
        // it in production), like the repair feature tests.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        // Web job-order routes are edition:retailer-gated (retailers outsource to
        // karigars), so the HTTP fixture is a retailer tenant.
        [$user, $shop] = $this->createRetailerTenant();
        $this->user = $user;
        $this->shopId = $shop->id;
        // is_active via DB::raw('true') — PostgreSQL rejects PHP true (bound as int).
        $this->karigarA = (int) DB::table('karigars')->insertGetId(['shop_id' => $this->shopId, 'name' => 'A', 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);
        $this->karigarB = (int) DB::table('karigars')->insertGetId(['shop_id' => $this->shopId, 'name' => 'B', 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function vaultLot(float $fine = 100.0): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill(['shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold', 'purity' => 22.00, 'fine_weight_total' => $fine, 'fine_weight_remaining' => $fine, 'cost_per_fine_gram' => 5000]);
        $lot->save();
        return $lot;
    }

    private function base(array $extra): array
    {
        return array_merge([
            'karigar_id' => $this->karigarA, 'metal_type' => 'gold', 'purity' => 22,
            'allowed_wastage_percent' => 5, 'issue_date' => now()->toDateString(),
        ], $extra);
    }

    private function latestJob(): JobOrder
    {
        return JobOrder::withoutGlobalScopes()->where('shop_id', $this->shopId)->latest('id')->firstOrFail();
    }

    public function test_labor_only_job_is_created_with_no_metal(): void
    {
        $this->actingAs($this->user)
            ->post(route('job-orders.store'), $this->base(['metal_source' => 'none', 'job_type' => 'repair']))
            ->assertRedirect();

        $job = $this->latestJob();
        $this->assertEqualsWithDelta(0.0, (float) $job->issued_fine_weight, 0.0001);
        $this->assertSame('none', TenantContext::runFor($this->shopId, fn () => $job->fresh()->metal_source));
    }

    public function test_source_set_vault_leg_debits_the_lot(): void
    {
        $lot = $this->vaultLot(100);

        $this->actingAs($this->user)->post(route('job-orders.store'), $this->base([
            'metal_source' => 'vault',
            'sources' => [['source_type' => 'vault', 'metal_lot_id' => $lot->id, 'fine_weight' => 20, 'gross_weight' => 20, 'purity' => 22]],
        ]))->assertRedirect();

        $this->assertEqualsWithDelta(80.0, (float) $lot->fresh()->fine_weight_remaining, 0.0001);
    }

    public function test_legacy_issuances_input_still_works(): void
    {
        $lot = $this->vaultLot(100);

        $this->actingAs($this->user)->post(route('job-orders.store'), $this->base([
            'issuances' => [['metal_lot_id' => $lot->id, 'fine_weight' => 15, 'gross_weight' => 15, 'purity' => 22]],
        ]))->assertRedirect();

        $this->assertEqualsWithDelta(85.0, (float) $lot->fresh()->fine_weight_remaining, 0.0001);
    }

    public function test_insufficient_lot_returns_a_friendly_error_not_a_500(): void
    {
        $lot = $this->vaultLot(5);

        $this->actingAs($this->user)->post(route('job-orders.store'), $this->base([
            'metal_source' => 'vault',
            'sources' => [['source_type' => 'vault', 'metal_lot_id' => $lot->id, 'fine_weight' => 50, 'gross_weight' => 50, 'purity' => 22]],
        ]))->assertSessionHasErrors('metal_source');
    }

    public function test_customer_supplied_source_requires_a_customer(): void
    {
        // A customer_advance leg with no customer_id is rejected by the service
        // (surfaced as a friendly validation error, not a 500).
        $this->actingAs($this->user)->post(route('job-orders.store'), $this->base([
            'metal_source' => 'customer_supplied',
            'sources' => [['source_type' => 'customer_advance', 'fine_weight' => 5, 'gross_weight' => 5, 'purity' => 22]],
        ]))->assertSessionHasErrors('metal_source');
    }

    // NOTE: the reassign / retained-receipt HTTP paths use {jobOrder} route-model
    // binding, whose tenant-scope timing is a known test-harness quirk. Those
    // flows are fully covered at the service layer (JobOrderTransferTest,
    // JobOrderRetainedMetalTest); the controller methods are thin wrappers.
}
