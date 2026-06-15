<?php

namespace Tests\Feature\Material;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Stage 6 — Materials settings tab.
 *
 * One page shows gold/silver as always-on, platinum/copper as opt-in toggles,
 * and a short note about stones. Toggling persists to shop_enabled_metals and
 * immediately changes what the item picker offers (cross-stage integration).
 */
class MaterialsSettingsUiTest extends TestCase
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

    public function test_materials_tab_renders_metal_sections(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'materials']));
        $response->assertOk();
        $response->assertSee('Main metals', false);
        $response->assertSee('Other metals', false);
        $response->assertSee('platinum', false);
        $response->assertSee('copper', false);
        $response->assertSee('Stones', false);
    }

    public function test_enabling_platinum_persists_and_makes_it_pickable(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');

        // Baseline: platinum not pickable.
        $this->assertFalse(MetalRegistry::uxItemPickerVisible('platinum', $shop->id));

        $response = $this->actingAs($user)->patch(route('settings.update.materials'), [
            'metals' => ['platinum' => '1', 'copper' => '0'],
        ]);
        $response->assertRedirect(route('settings.edit', ['tab' => 'materials']));

        $this->assertTrue((bool) DB::table('shop_enabled_metals')
            ->where('shop_id', $shop->id)->where('metal_type', 'platinum')->value('enabled'));

        MetalRegistry::clearShopCache($shop->id);
        $this->assertTrue(MetalRegistry::uxItemPickerVisible('platinum', $shop->id));
        $this->assertFalse(MetalRegistry::uxItemPickerVisible('copper', $shop->id));
    }

    public function test_disabling_platinum_persists(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');

        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shop->id, 'metal_type' => 'platinum'],
            ['enabled' => DB::raw('TRUE'), 'updated_at' => now(), 'created_at' => now()]
        );
        MetalRegistry::clearShopCache($shop->id);
        $this->assertTrue(MetalRegistry::uxItemPickerVisible('platinum', $shop->id));

        $this->actingAs($user)->patch(route('settings.update.materials'), [
            'metals' => ['platinum' => '0', 'copper' => '0'],
        ])->assertRedirect();

        $this->assertFalse((bool) DB::table('shop_enabled_metals')
            ->where('shop_id', $shop->id)->where('metal_type', 'platinum')->value('enabled'));

        MetalRegistry::clearShopCache($shop->id);
        $this->assertFalse(MetalRegistry::uxItemPickerVisible('platinum', $shop->id));
    }

    /**
     * Turning a metal off while live stock of it remains would lock the owner out
     * of editing those items (ItemController validates metal_type against the
     * enabled list). The guard refuses with a plain-English message and the metal
     * stays on.
     */
    public function test_disabling_a_metal_with_live_stock_is_blocked(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');

        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shop->id, 'metal_type' => 'platinum'],
            ['enabled' => DB::raw('TRUE'), 'updated_at' => now(), 'created_at' => now()]
        );
        MetalRegistry::clearShopCache($shop->id);

        // A platinum item still in stock.
        $this->createItem($shop->id, null, ['metal_type' => 'platinum', 'status' => 'in_stock']);

        $response = $this->actingAs($user)->patch(route('settings.update.materials'), [
            'metals' => ['platinum' => '0', 'copper' => '0'],
        ]);
        $response->assertRedirect(route('settings.edit', ['tab' => 'materials']));
        $response->assertSessionHasErrors('metals');

        // Still enabled — the disable was refused.
        $this->assertTrue((bool) DB::table('shop_enabled_metals')
            ->where('shop_id', $shop->id)->where('metal_type', 'platinum')->value('enabled'));

        MetalRegistry::clearShopCache($shop->id);
        $this->assertTrue(MetalRegistry::uxItemPickerVisible('platinum', $shop->id));
    }

    /**
     * Once every platinum item has physically left stock (melted / reversed /
     * written off), the metal can be turned off normally.
     */
    public function test_disabling_a_metal_with_only_disposed_stock_succeeds(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');

        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shop->id, 'metal_type' => 'platinum'],
            ['enabled' => DB::raw('TRUE'), 'updated_at' => now(), 'created_at' => now()]
        );
        MetalRegistry::clearShopCache($shop->id);

        // A platinum item that has been melted away — no longer live stock.
        $this->createItem($shop->id, null, ['metal_type' => 'platinum', 'status' => 'melted']);

        $response = $this->actingAs($user)->patch(route('settings.update.materials'), [
            'metals' => ['platinum' => '0', 'copper' => '0'],
        ]);
        $response->assertRedirect(route('settings.edit', ['tab' => 'materials']));
        $response->assertSessionHasNoErrors();

        $this->assertFalse((bool) DB::table('shop_enabled_metals')
            ->where('shop_id', $shop->id)->where('metal_type', 'platinum')->value('enabled'));

        MetalRegistry::clearShopCache($shop->id);
        $this->assertFalse(MetalRegistry::uxItemPickerVisible('platinum', $shop->id));
    }
}
