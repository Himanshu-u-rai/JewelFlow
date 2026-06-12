<?php

namespace Tests\Feature;

use App\Models\Repair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Repair module closure: repairs are metal-aware (gold/silver/platinum/other)
 * and purity is validated per the metal's scale, not a gold-only max of 24.
 * These tests lock in that behaviour and the backward-compat default to gold.
 */
class RepairMetalTypeTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        // The test harness creates an owner role without synced permissions, so
        // the `can:repairs.create` gate would 403 before validation runs. Bypass
        // the authorization layer to exercise the repair store/validation logic
        // (in production owners hold repairs.create). This mirrors the known
        // RBAC-harness gap affecting other repair feature tests.
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\Authorize::class);
    }

    private function postRepair(array $overrides = []): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $payload = array_merge([
            'customer_id' => $customer->id,
            'item_description' => 'Test piece',
            'gross_weight' => 5.000,
            'estimated_cost' => 300,
        ], $overrides);

        $response = $this->actingAs($user)->post(route('repairs.store'), $payload);

        return [$response, $shop];
    }

    public function test_existing_gold_flow_still_works_without_metal_type(): void
    {
        // Backward compatibility: no metal_type sent -> defaults to gold, karat purity.
        [$response, $shop] = $this->postRepair(['purity' => 22]);

        $response->assertRedirect(route('repairs.index'));
        $repair = Repair::withoutTenant()->where('shop_id', $shop->id)->latest('id')->firstOrFail();

        $this->assertSame('gold', $repair->metal_type);
        $this->assertEqualsWithDelta(22.0, (float) $repair->purity, 0.001);
        $this->assertSame('22K', $repair->purityLabel());
    }

    public function test_gold_repair_with_explicit_metal_type(): void
    {
        [$response, $shop] = $this->postRepair(['metal_type' => 'gold', 'purity' => 18]);

        $response->assertRedirect(route('repairs.index'));
        $repair = Repair::withoutTenant()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->assertSame('gold', $repair->metal_type);
        $this->assertSame('18K', $repair->purityLabel());
    }

    public function test_silver_repair_accepts_fineness_above_gold_cap(): void
    {
        // 925 would be rejected by the old gold-only max:24 — now valid for silver.
        [$response, $shop] = $this->postRepair(['metal_type' => 'silver', 'purity' => 925]);

        $response->assertRedirect(route('repairs.index'));
        $repair = Repair::withoutTenant()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->assertSame('silver', $repair->metal_type);
        $this->assertEqualsWithDelta(925.0, (float) $repair->purity, 0.001);
        // No karat suffix for non-gold.
        $this->assertSame('925', $repair->purityLabel());
    }

    public function test_platinum_repair_accepts_fineness(): void
    {
        [$response, $shop] = $this->postRepair(['metal_type' => 'platinum', 'purity' => 950]);

        $response->assertRedirect(route('repairs.index'));
        $repair = Repair::withoutTenant()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->assertSame('platinum', $repair->metal_type);
        $this->assertSame('950', $repair->purityLabel());
    }

    public function test_other_repair_allows_null_purity(): void
    {
        [$response, $shop] = $this->postRepair(['metal_type' => 'other']); // no purity

        $response->assertRedirect(route('repairs.index'));
        $repair = Repair::withoutTenant()->where('shop_id', $shop->id)->latest('id')->firstOrFail();
        $this->assertSame('other', $repair->metal_type);
        $this->assertNull($repair->purity);
        $this->assertNull($repair->purityLabel());
    }

    public function test_invalid_metal_type_is_rejected(): void
    {
        [$response] = $this->postRepair(['metal_type' => 'wood', 'purity' => 22]);
        $response->assertSessionHasErrors('metal_type');
    }

    public function test_gold_still_rejects_purity_above_karat_scale(): void
    {
        // A gold repair must not accept 925 — the cap is metal-aware, not removed.
        [$response] = $this->postRepair(['metal_type' => 'gold', 'purity' => 925]);
        $response->assertSessionHasErrors('purity');
    }
}
