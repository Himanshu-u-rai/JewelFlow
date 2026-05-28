<?php

namespace Tests\Feature\Material;

use App\Models\Item;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Stage 5 — stone UX simplification.
 *
 * Pilot shops enter stones as a single rupee "stone charges" amount. The
 * advanced Phase 2B component infrastructure (per-stone certificates, carat,
 * revaluation) stays in the codebase but is NOT exposed via routes/UI, so it
 * cannot clutter the everyday stone-entry experience.
 */
class StoneUxSimplificationTest extends TestCase
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

    public function test_stone_amount_persists_as_simple_rupee_value(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $this->grant($user, 'inventory.create');

        $response = $this->actingAs($user)->post(route('inventory.items.store'), [
            'barcode' => 'STONE-001',
            'design' => 'Gold Ring with Stone',
            'category' => 'Gold Jewellery',
            'metal_type' => 'gold',
            'gross_weight' => 8,
            'stone_weight' => 0.5,
            'purity' => 22,
            'making_charges' => 400,
            'stone_charges' => 3500,
        ]);

        $response->assertRedirect(route('inventory.items.index'));

        $item = Item::withoutTenant()->where('shop_id', $shop->id)->where('barcode', 'STONE-001')->firstOrFail();
        // Stone is recorded as a plain rupee charge — no component breakdown required.
        $this->assertSame(3500.0, round((float) $item->stone_charges, 2));
    }

    public function test_advanced_stone_component_ui_is_not_exposed(): void
    {
        // The Phase 2B component/revaluation UI is intentionally unrouted for
        // pilot. If a future batch wires it up behind an opt-in, this test
        // should be updated as a conscious decision.
        $this->assertFalse(Route::has('inventory.items.stones.index'));
        $this->assertFalse(Route::has('inventory.items.stones.store'));
        $this->assertFalse(Route::has('inventory.items.stones.revaluations'));
    }
}
