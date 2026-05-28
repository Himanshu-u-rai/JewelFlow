<?php

namespace Tests\Feature\Material;

use App\Models\ShopMetalReferencePrice;
use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * R3 — Reference price UI on Settings → Materials.
 *
 * Card appears only for opted-in Tier-2 metals. A gold/silver-only shop (the
 * pilot) sees no reference UI at all. The daily-rate UI is never touched.
 */
class ReferencePriceSettingsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grant(\App\Models\User $user, string ...$perms): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    private function enable(int $shopId, string $metal): void
    {
        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shopId, 'metal_type' => $metal],
            ['enabled' => DB::raw('TRUE'), 'updated_at' => now(), 'created_at' => now()]
        );
        MetalRegistry::clearShopCache($shopId);
    }

    public function test_pilot_shop_sees_no_reference_card(): void
    {
        // Gold/silver-only shop — the pilot baseline. Reference cards must not appear.
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'materials']));
        $response->assertOk();
        $response->assertDontSee('Reference prices', false);
        $response->assertDontSee('reference price', false);
    }

    public function test_card_appears_for_enabled_platinum(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');
        $this->enable($shop->id, 'platinum');

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'materials']));
        $response->assertOk();
        $response->assertSee('Reference prices', false);
        $response->assertSee('platinum reference price', false); // capitalized via CSS
        $response->assertSee('No reference noted yet', false);
    }

    public function test_card_absent_when_metal_not_enabled(): void
    {
        // Copper not enabled — no copper card even though platinum may be.
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view');
        $this->enable($shop->id, 'platinum');

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'materials']));
        $response->assertOk();
        $response->assertSee('platinum reference price', false);
        $response->assertDontSee('copper reference price', false);
    }

    public function test_save_persists_new_row_and_redirects(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');
        $this->enable($shop->id, 'platinum');

        $response = $this->actingAs($user)->patch(route('settings.update.material-reference'), [
            'metal_type'      => 'platinum',
            'reference_price' => 3250.00,
            'note'            => 'supplier ABC',
        ]);

        $response->assertRedirect(route('settings.edit', ['tab' => 'materials']));

        $row = ShopMetalReferencePrice::withoutTenant()
            ->where('shop_id', $shop->id)
            ->where('metal_type', 'platinum')
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(3250.0, (float) $row->reference_price);
        $this->assertSame('supplier ABC', $row->note);
        $this->assertSame($user->id, $row->noted_by_user_id);
    }

    public function test_save_rejects_class_a_metals_at_validation(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');

        $response = $this->actingAs($user)
            ->from(route('settings.edit', ['tab' => 'materials']))
            ->patch(route('settings.update.material-reference'), [
                'metal_type'      => 'gold',
                'reference_price' => 6000,
            ]);

        $response->assertSessionHasErrors('metal_type');
        $this->assertSame(0, ShopMetalReferencePrice::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    public function test_save_rejects_when_metal_not_enabled_for_shop(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');
        // Platinum NOT enabled.

        $response = $this->actingAs($user)
            ->from(route('settings.edit', ['tab' => 'materials']))
            ->patch(route('settings.update.material-reference'), [
                'metal_type'      => 'platinum',
                'reference_price' => 3200,
            ]);

        $response->assertSessionHasErrors('metal_type');
        $this->assertSame(0, ShopMetalReferencePrice::withoutTenant()->where('shop_id', $shop->id)->count());
    }

    public function test_card_renders_latest_reference_when_present(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');
        $this->enable($shop->id, 'platinum');

        // Record via the route, then fetch the page and confirm the card surfaces it.
        $this->actingAs($user)->patch(route('settings.update.material-reference'), [
            'metal_type'      => 'platinum',
            'reference_price' => 3275.50,
            'note'            => 'this week',
        ])->assertRedirect();

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'materials']));
        $response->assertOk();
        $response->assertSee('3,275.50', false);
        $response->assertSee('this week', false);
        $response->assertDontSee('No reference noted yet', false);
    }

    public function test_daily_rates_ui_is_not_touched(): void
    {
        // The Reference price card must not leak into the pricing/daily-rates tab.
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'pricing.update');
        $this->enable($shop->id, 'platinum');

        $response = $this->actingAs($user)->get(route('settings.edit', ['tab' => 'pricing']));
        $response->assertOk();
        $response->assertDontSee('Reference prices', false);
        $response->assertDontSee('reference price', false);
    }
}
