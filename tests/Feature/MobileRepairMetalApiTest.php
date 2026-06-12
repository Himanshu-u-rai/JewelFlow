<?php

namespace Tests\Feature;

use App\Models\Repair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Mobile-facing contract for the metal-aware Repair API: purity_label on every
 * serialized repair, the consolidated GET /repairs/options shape, and the
 * nullable-purity-for-"other" store rule. The mobile app types against these.
 */
class MobileRepairMetalApiTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // Harness owner role has no synced permissions, so can:repairs.* would
        // 403 before the controller runs. Bypass authorization to exercise the
        // API contract (owners hold these permissions in production).
        $this->withoutMiddleware(\Illuminate\Auth\Middleware\Authorize::class);
    }

    public function test_store_response_and_show_include_metal_type_and_purity_label(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $store = $this->postJson('/api/mobile/repairs', [
            'customer_id' => $customer->id,
            'item_description' => 'Silver anklet',
            'metal_type' => 'silver',
            'gross_weight' => 12.000,
            'purity' => 925,
            'estimated_cost' => 200,
        ]);

        $store->assertCreated();
        // 201 echoes the full serialized repair (consistency with index/show).
        $store->assertJsonPath('repair.metal_type', 'silver');
        $store->assertJsonPath('repair.purity_label', '925');

        $id = $store->json('id');
        $show = $this->getJson("/api/mobile/repairs/{$id}");
        $show->assertOk();
        $show->assertJsonPath('metal_type', 'silver');
        $show->assertJsonPath('purity_label', '925');
    }

    public function test_legacy_gold_repair_returns_karat_label(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        // A row created via the model (metal_type defaults to gold on the API).
        $store = $this->postJson('/api/mobile/repairs', [
            'customer_id' => $customer->id,
            'item_description' => 'Gold ring',
            'gross_weight' => 5.000,
            'purity' => 22,
            'estimated_cost' => 400,
        ]);

        $store->assertCreated();
        $store->assertJsonPath('repair.metal_type', 'gold');
        $store->assertJsonPath('repair.purity_label', '22K');
    }

    public function test_other_metal_allows_null_purity_and_null_label(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $store = $this->postJson('/api/mobile/repairs', [
            'customer_id' => $customer->id,
            'item_description' => 'Imitation bangle',
            'metal_type' => 'other',
            'gross_weight' => 9.000,
            'estimated_cost' => 100,
            // no purity
        ]);

        $store->assertCreated();
        $store->assertJsonPath('repair.metal_type', 'other');
        $store->assertJsonPath('repair.purity', null);
        $store->assertJsonPath('repair.purity_label', null);
    }

    public function test_gold_purity_above_karat_cap_is_rejected(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        Sanctum::actingAs($user);

        $this->postJson('/api/mobile/repairs', [
            'customer_id' => $customer->id,
            'item_description' => 'Gold ring',
            'metal_type' => 'gold',
            'gross_weight' => 5.000,
            'purity' => 925,
            'estimated_cost' => 400,
        ])->assertStatus(422)->assertJsonValidationErrors('purity');
    }

    public function test_options_endpoint_returns_consolidated_metals_shape(): void
    {
        [$user] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $res = $this->getJson('/api/mobile/repairs/options');
        $res->assertOk();
        $res->assertJsonPath('default_metal', 'gold');

        $metals = collect($res->json('metals'))->keyBy('value');
        $this->assertEqualsCanonicalizing(['gold', 'silver', 'platinum', 'other'], $metals->keys()->all());

        // Per-metal contract: max_purity == maxPurityFor(), correct units, string presets.
        $this->assertSame(24, $metals['gold']['max_purity']);
        $this->assertSame('karat', $metals['gold']['purity_unit']);
        $this->assertSame(999, $metals['silver']['max_purity']);
        $this->assertSame('millesimal', $metals['silver']['purity_unit']);
        $this->assertSame(999, $metals['platinum']['max_purity']);
        $this->assertSame('millesimal', $metals['platinum']['purity_unit']);
        $this->assertSame(999, $metals['other']['max_purity']);
        $this->assertNull($metals['other']['purity_unit']);
        $this->assertSame([], $metals['other']['purities']);

        // Purity preset values are strings (app parseFloats on submit).
        $this->assertSame('925', collect($metals['silver']['purities'])->firstWhere('value', '925')['value']);

        // The advertised cap must equal the server's actual validation cap.
        foreach ($metals as $value => $m) {
            $this->assertSame((float) $m['max_purity'], Repair::maxPurityFor($value));
        }
    }
}
