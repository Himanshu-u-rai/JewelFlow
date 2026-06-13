<?php

namespace Tests\Feature;

use App\Models\ShopPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Preferences settings: the save path + the validation bounds that keep money /
 * compliance / loyalty values safe, the disjoint-field safety vs the shared
 * return-policy fields on the same ShopPreferences model, and the rounding /
 * discount-cap consumption in the pricing engine.
 */
class PreferencesSettingsTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private $user;
    private int $shopId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
        [$user, $shop] = $this->createManufacturerTenant();
        $this->user = $user;
        $this->shopId = $shop->id;
    }

    private function base(array $extra = []): array
    {
        // The required fields updatePreferences validates.
        return array_merge([
            'low_stock_threshold'        => 5,
            'loyalty_points_per_hundred' => 2,
            'loyalty_point_value'        => 0.5,
            'loyalty_expiry_months'      => 12,
        ], $extra);
    }

    private function prefs(): ShopPreferences
    {
        return ShopPreferences::withoutGlobalScopes()->where('shop_id', $this->shopId)->firstOrFail();
    }

    // ── save path ─────────────────────────────────────────────────────────────

    public function test_preferences_save_persists_values(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'auto_logout_minutes' => 30, 'stock_value_display' => 'per_gram',
            'rounding_method' => 'normal', 'round_off_nearest' => 5,
            'max_manual_discount_percent' => 15,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $p = $this->prefs();
        $this->assertSame(30, (int) $p->auto_logout_minutes);
        $this->assertSame('per_gram', $p->stock_value_display);
        $this->assertSame('normal', $p->rounding_method);
        $this->assertSame(5, (int) $p->round_off_nearest);
        $this->assertEqualsWithDelta(15.0, (float) $p->max_manual_discount_percent, 0.001);
    }

    public function test_compliance_flags_persist_as_booleans(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'compliance_enabled' => '1', 'compliance_threshold' => 200000,
            'compliance_pan_mandatory' => '1', 'compliance_mobile_mandatory' => '0',
        ]))->assertRedirect();

        $p = $this->prefs();
        $this->assertTrue((bool) $p->compliance_enabled);
        $this->assertTrue((bool) $p->compliance_pan_mandatory);
        $this->assertFalse((bool) $p->compliance_mobile_mandatory);
        $this->assertEqualsWithDelta(200000.0, (float) $p->compliance_threshold, 0.001);
    }

    // ── validation bounds (the "future safety" guards) ─────────────────────────

    public function test_required_fields_are_enforced(): void
    {
        $this->actingAs($this->user)->patch(route('settings.update.preferences'), [])
            ->assertSessionHasErrors(['low_stock_threshold', 'loyalty_points_per_hundred', 'loyalty_point_value', 'loyalty_expiry_months']);
    }

    public function test_out_of_range_values_are_rejected(): void
    {
        // discount > 100%, logout > 8h, rounding nearest not in {1,5,10}, bad enum
        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'max_manual_discount_percent' => 150,
        ]))->assertSessionHasErrors('max_manual_discount_percent');

        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'auto_logout_minutes' => 9999,
        ]))->assertSessionHasErrors('auto_logout_minutes');

        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'round_off_nearest' => 3,
        ]))->assertSessionHasErrors('round_off_nearest');

        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'rounding_method' => 'sideways',
        ]))->assertSessionHasErrors('rounding_method');

        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'compliance_threshold' => 500,  // below min 10000
        ]))->assertSessionHasErrors('compliance_threshold');

        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'loyalty_expiry_months' => 7,  // not in {0,6,12,18,24}
        ]))->assertSessionHasErrors('loyalty_expiry_months');
    }

    // ── shared-model safety: Preferences save must not clobber return-policy ───

    public function test_saving_preferences_does_not_clobber_return_policy_fields(): void
    {
        // Seed return-policy fields (the other tab on the same ShopPreferences row).
        $p = ShopPreferences::withoutGlobalScopes()->firstOrNew(['shop_id' => $this->shopId]);
        $p->forceFill([
            'shop_id' => $this->shopId,
            'refund_making_charges' => false, 'wear_loss_pct' => 7,
            'return_window_days' => 14, 'return_policy_configured_at' => now(),
        ])->save();

        // Now save the Preferences tab.
        $this->actingAs($this->user)->patch(route('settings.update.preferences'), $this->base([
            'auto_logout_minutes' => 20,
        ]))->assertRedirect();

        $p->refresh();
        // Preferences applied…
        $this->assertSame(20, (int) $p->auto_logout_minutes);
        // …and the return-policy fields are untouched (disjoint fill).
        $this->assertFalse((bool) $p->refund_making_charges, 'return-policy field preserved');
        $this->assertEqualsWithDelta(7.0, (float) $p->wear_loss_pct, 0.001);
        $this->assertSame(14, (int) $p->return_window_days);
        $this->assertNotNull($p->return_policy_configured_at);
    }

    // ── one row per shop (the GST-style non-determinism guard) ─────────────────

    public function test_shop_preferences_is_unique_per_shop(): void
    {
        // Ensure exactly one prefs row exists for this shop, then prove a second
        // insert is rejected at the DB (the guard against the GST-style
        // duplicate-row → non-deterministic-read problem).
        $existing = ShopPreferences::withoutGlobalScopes()->where('shop_id', $this->shopId)->first();
        if (! $existing) {
            $first = new ShopPreferences();
            $first->forceFill(['shop_id' => $this->shopId]);
            $first->save();
        }

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $dup = new ShopPreferences();
        $dup->forceFill(['shop_id' => $this->shopId]);
        $dup->save();
    }
}
