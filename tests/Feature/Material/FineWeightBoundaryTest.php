<?php

namespace Tests\Feature\Material;

use App\Models\MetalLot;
use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P2 — Fine-weight semantic boundary.
 *
 * Purity becomes a fine-weight multiplier ONLY for accounting-truth metals
 * (gold/silver). A platinum/copper purity value can never silently become a
 * fine weight.
 */
class FineWeightBoundaryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grant(\App\Models\User $user, string ...$permissions): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($permissions as $permission) {
            $role->givePermission($permission);
        }
    }

    public function test_multiplier_scale_is_keyed_on_metal(): void
    {
        $this->assertSame(22.0 / 24.0, MetalRegistry::fineWeightMultiplier('gold', 22));
        $this->assertSame(925.0 / 1000.0, MetalRegistry::fineWeightMultiplier('silver', 925));
    }

    public function test_multiplier_is_null_for_non_accounting_metals(): void
    {
        $this->assertNull(MetalRegistry::fineWeightMultiplier('platinum', 95));
        $this->assertNull(MetalRegistry::fineWeightMultiplier('copper', 99));
    }

    public function test_fine_weight_computation(): void
    {
        // 10g 22K gold = 9.166667g fine
        $this->assertSame(9.166667, MetalRegistry::fineWeight('gold', 10, 22));
        // 50g 925 silver = 46.25g fine
        $this->assertSame(46.25, MetalRegistry::fineWeight('silver', 50, 925));
        // platinum/copper: no purity-derived fine weight, ever
        $this->assertNull(MetalRegistry::fineWeight('platinum', 6, 95));
        $this->assertNull(MetalRegistry::fineWeight('copper', 100, 99));
    }

    public function test_accounting_truth_metals_are_exactly_gold_and_silver(): void
    {
        $metals = MetalRegistry::accountingTruthMetals();
        sort($metals);
        $this->assertSame(['gold', 'silver'], $metals);
    }

    public function test_vault_lot_creation_works_for_gold(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'vault.view', 'vault.manage');

        $response = $this->actingAs($user)->post(route('vault.lots.store'), [
            'metal_type' => 'gold',
            'source' => 'purchase',
            'gross_weight' => 10,
            'purity' => 22,
            'cost_per_gram' => 6000,
        ]);

        $response->assertRedirect();

        $lot = MetalLot::withoutTenant()->where('shop_id', $shop->id)->where('metal_type', 'gold')->firstOrFail();
        $this->assertSame(9.166667, round((float) $lot->fine_weight_total, 6));
    }

    public function test_vault_lot_creation_rejects_platinum(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'vault.view', 'vault.manage');
        // Even if platinum were enabled for the shop, the vault is accounting-truth only.

        $response = $this->actingAs($user)->from(route('vault.lots.create'))->post(route('vault.lots.store'), [
            'metal_type' => 'platinum',
            'source' => 'purchase',
            'gross_weight' => 6,
            'purity' => 95,
            'cost_per_gram' => 3000,
        ]);

        $response->assertSessionHasErrors('metal_type');
        $this->assertDatabaseMissing('metal_lots', ['shop_id' => $shop->id, 'metal_type' => 'platinum']);
    }
}
