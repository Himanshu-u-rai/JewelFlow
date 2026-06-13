<?php

namespace Tests\Feature;

use App\Models\GstCategory;
use App\Models\ShopBillingSettings;
use App\Services\GstRateResolver;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * GST & Tax settings: the updateGst form (GSTIN + default rate + HSN + IGST),
 * the per-metal GstCategory CRUD (single-default invariant, shop scoping), and
 * the authoritative resolver precedence (per-metal → default → flat fallback)
 * that drives invoice GST (Constitution §7).
 */
class GstTaxSettingsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private $user;
    private int $shopId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // can:settings.edit-gated; the harness owner role has no synced perms,
        // so bypass authorization (owners hold it in production).
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        [$user, $shop] = $this->createManufacturerTenant();
        $this->user = $user;
        $this->shopId = $shop->id;
    }

    private function cat(array $attrs): GstCategory
    {
        $c = new GstCategory();
        $c->forceFill(array_merge([
            'shop_id' => $this->shopId, 'name' => 'Cat', 'rate_pct' => 3.00,
            'metal_type' => null, 'is_default' => false,
        ], $attrs));
        $c->save();
        return $c;
    }

    private function resolve(?string $metal): float
    {
        return TenantContext::runFor(
            $this->shopId,
            fn () => app(GstRateResolver::class)->resolveForShop($this->shopId, $metal),
        );
    }

    // ── updateGst form ──────────────────────────────────────────────────────

    public function test_update_gst_saves_gstin_and_default_rate_on_the_shop(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.gst'), [
            'gst_number' => '08ABCDE1234F1Z5', 'gst_rate' => 5,
        ])->assertRedirect();

        $shop = \App\Models\Shop::withoutGlobalScopes()->find($this->shopId);
        $this->assertSame('08ABCDE1234F1Z5', $shop->gst_number);
        $this->assertEqualsWithDelta(5.0, (float) $shop->gst_rate, 0.001);
    }

    public function test_update_gst_saves_hsn_and_igst_on_billing_settings(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.gst'), [
            'gst_rate' => 3, 'hsn_gold' => '7113', 'hsn_silver' => '7113',
            'hsn_diamond' => '7102', 'igst_mode' => '1',
        ])->assertRedirect();

        $b = ShopBillingSettings::withoutGlobalScopes()->where('shop_id', $this->shopId)->first();
        $this->assertSame('7102', $b->hsn_diamond);
        $this->assertTrue((bool) $b->igst_mode);
    }

    public function test_blank_hsn_falls_back_to_sensible_defaults(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.gst'), [
            'gst_rate' => 3, 'hsn_gold' => '', 'hsn_silver' => '', 'hsn_diamond' => '',
        ])->assertRedirect();

        $b = ShopBillingSettings::withoutGlobalScopes()->where('shop_id', $this->shopId)->first();
        $this->assertSame('7113', $b->hsn_gold);
        $this->assertSame('7113', $b->hsn_silver);
        $this->assertSame('7114', $b->hsn_diamond);
    }

    public function test_update_gst_saves_platinum_and_copper_hsn(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.gst'), [
            'gst_rate' => 3, 'hsn_platinum' => '71131910', 'hsn_copper' => '74199930',
        ])->assertRedirect();

        $b = ShopBillingSettings::withoutGlobalScopes()->where('shop_id', $this->shopId)->first();
        $this->assertSame('71131910', $b->hsn_platinum);
        $this->assertSame('74199930', $b->hsn_copper);
    }

    public function test_blank_platinum_copper_hsn_default_to_7115_and_7403(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.gst'), [
            'gst_rate' => 3, 'hsn_platinum' => '', 'hsn_copper' => '',
        ])->assertRedirect();

        $b = ShopBillingSettings::withoutGlobalScopes()->where('shop_id', $this->shopId)->first();
        $this->assertSame('7115', $b->hsn_platinum);
        $this->assertSame('7403', $b->hsn_copper);
    }

    public function test_hsn_for_metal_resolves_each_metal_to_its_own_code(): void
    {
        $b = new ShopBillingSettings();
        $b->forceFill([
            'hsn_gold' => '7113', 'hsn_silver' => '7113', 'hsn_diamond' => '7114',
            'hsn_platinum' => '7115', 'hsn_copper' => '7403',
        ]);

        // metal_type-keyed (the reliable path)
        $this->assertSame('7113', $b->hsnForMetal('gold'));
        $this->assertSame('7113', $b->hsnForMetal('silver'));
        $this->assertSame('7115', $b->hsnForMetal('platinum'), 'platinum is NOT gold');
        $this->assertSame('7403', $b->hsnForMetal('copper'), 'copper is NOT gold');
        // category fallback for stone/diamond + legacy metal-less lines
        $this->assertSame('7114', $b->hsnForMetal(null, 'Diamond Ring'));
        $this->assertSame('7115', $b->hsnForMetal(null, 'Platinum Band'), 'legacy platinum via category');
        // unknown → gold default
        $this->assertSame('7113', $b->hsnForMetal(null, null));
    }

    public function test_gst_rate_is_required_and_bounded(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.gst'), [])
            ->assertSessionHasErrors('gst_rate');
        $this->actingAs($this->user)->patch(route('settings.update.gst'), ['gst_rate' => 150])
            ->assertSessionHasErrors('gst_rate');
        $this->actingAs($this->user)->patch(route('settings.update.gst'), ['gst_rate' => -1])
            ->assertSessionHasErrors('gst_rate');
    }

    // ── per-metal GstCategory CRUD ──────────────────────────────────────────

    /** Defaults for this shop, compared on the loaded boolean attribute (a raw
     *  ->where('is_default', true) binds PHP true as int 1 → boolean=integer on PG). */
    private function defaultCategories(): \Illuminate\Support\Collection
    {
        return GstCategory::withoutGlobalScopes()->where('shop_id', $this->shopId)
            ->get()->filter(fn ($c) => (bool) $c->is_default)->values();
    }

    public function test_create_category_clears_other_defaults_when_marked_default(): void
    {
        $old = $this->cat(['name' => 'Old default', 'is_default' => true]);

        $this->actingAs($this->user)->post(route('settings.gst-categories.store'), [
            'name' => 'New default', 'rate_pct' => 1.5, 'metal_type' => '', 'is_default' => '1',
        ])->assertRedirect();

        // Exactly one default remains, and it's the new one — the old one cleared.
        // (name may be normalised/title-cased by the app, so compare case-insensitively.)
        $defaults = $this->defaultCategories();
        $this->assertCount(1, $defaults, 'single-default invariant holds');
        $this->assertSame('new default', strtolower($defaults->first()->name));
        $this->assertFalse((bool) $old->fresh()->is_default);
    }

    public function test_category_rate_is_bounded(): void
    {
        $this->actingAs($this->user)->post(route('settings.gst-categories.store'), [
            'name' => 'X', 'rate_pct' => 150,
        ])->assertSessionHasErrors('rate_pct');
    }

    // NOTE: update/destroy go through {gstCategory} route-model binding, whose
    // tenant-scope timing is a known test-harness quirk (resolves before the
    // tenant context → 404 in tests). The controller methods are thin wrappers
    // over shop-scoped queries; we exercise that logic directly here instead of
    // through the binding, mirroring the JobOrder controller tests.

    public function test_update_category_logic_changes_rate_and_metal(): void
    {
        $cat = $this->cat(['name' => 'Gold rate', 'rate_pct' => 3, 'metal_type' => null]);

        $this->actingAs($this->user);
        TenantContext::runFor($this->shopId, function () use ($cat) {
            app(\App\Http\Controllers\GstCategoryController::class)->update(
                \Illuminate\Http\Request::create('', 'PATCH', [
                    'name' => 'Gold rate', 'rate_pct' => 1.5, 'metal_type' => 'gold',
                ])->setUserResolver(fn () => $this->user),
                $cat,
            );
        });

        $cat->refresh();
        $this->assertEqualsWithDelta(1.5, (float) $cat->rate_pct, 0.001);
        $this->assertSame('gold', $cat->metal_type);
    }

    public function test_update_marking_default_clears_siblings(): void
    {
        $a = $this->cat(['name' => 'A', 'is_default' => true]);
        $b = $this->cat(['name' => 'B', 'is_default' => false, 'metal_type' => 'gold']);

        $this->actingAs($this->user);
        TenantContext::runFor($this->shopId, function () use ($b) {
            app(\App\Http\Controllers\GstCategoryController::class)->update(
                \Illuminate\Http\Request::create('', 'PATCH', [
                    'name' => 'B', 'rate_pct' => 1.5, 'metal_type' => 'gold', 'is_default' => '1',
                ])->setUserResolver(fn () => $this->user),
                $b,
            );
        });

        $defaults = $this->defaultCategories();
        $this->assertCount(1, $defaults, 'single-default invariant holds after update');
        $this->assertSame('B', $defaults->first()->name);
        $this->assertFalse((bool) $a->fresh()->is_default);
    }

    public function test_update_rejects_another_shops_category(): void
    {
        [, $otherShop] = $this->createManufacturerTenant();
        $foreign = new GstCategory();
        $foreign->forceFill(['shop_id' => $otherShop->id, 'name' => 'Foreign', 'rate_pct' => 9, 'is_default' => false]);
        $foreign->save();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->actingAs($this->user);
        TenantContext::runFor($this->shopId, function () use ($foreign) {
            app(\App\Http\Controllers\GstCategoryController::class)->update(
                \Illuminate\Http\Request::create('', 'PATCH', ['name' => 'Hacked', 'rate_pct' => 0])
                    ->setUserResolver(fn () => $this->user),
                $foreign,
            );
        });
    }

    public function test_destroy_category_logic_removes_it(): void
    {
        $cat = $this->cat(['name' => 'Gold 1%', 'rate_pct' => 1, 'metal_type' => 'gold']);

        $this->actingAs($this->user);
        TenantContext::runFor($this->shopId, function () use ($cat) {
            app(\App\Http\Controllers\GstCategoryController::class)->destroy($cat);
        });

        $this->assertNull(GstCategory::withoutGlobalScopes()->find($cat->id));
    }

    // ── resolver precedence (the authoritative GST path, §7) ────────────────

    public function test_resolver_falls_back_to_shop_flat_rate_when_no_categories(): void
    {
        \App\Models\Shop::withoutGlobalScopes()->where('id', $this->shopId)->update(['gst_rate' => 3.00]);
        $this->assertEqualsWithDelta(3.0, $this->resolve('gold'), 0.001);
    }

    public function test_resolver_prefers_metal_specific_over_default(): void
    {
        $this->cat(['name' => 'All', 'rate_pct' => 3, 'is_default' => true]);
        $this->cat(['name' => 'Gold', 'rate_pct' => 1.5, 'metal_type' => 'gold']);

        $this->assertEqualsWithDelta(1.5, $this->resolve('gold'), 0.001, 'gold uses its override');
        $this->assertEqualsWithDelta(3.0, $this->resolve('silver'), 0.001, 'silver uses the default category');
    }

    public function test_settings_save_then_resolve_is_consistent_end_to_end(): void
    {
        // Save a 5% default rate via the form, then add a 1.5% gold override.
        $this->actingAs($this->user)->patch(route('settings.update.gst'), ['gst_rate' => 5]);
        $this->actingAs($this->user)->post(route('settings.gst-categories.store'), [
            'name' => 'Gold', 'rate_pct' => 1.5, 'metal_type' => 'gold',
        ]);

        $this->assertEqualsWithDelta(1.5, $this->resolve('gold'), 0.001);
        // No silver category and no is_default category → flat shop rate (5%).
        $this->assertEqualsWithDelta(5.0, $this->resolve('silver'), 0.001);
    }
}
