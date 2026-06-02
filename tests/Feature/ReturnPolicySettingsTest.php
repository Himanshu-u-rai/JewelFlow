<?php

namespace Tests\Feature;

use App\Models\ShopPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * P0 surface restoration: the Return Policy settings tab — the redirect target
 * that gates ReturnsController::create(). Without it, returns were permanently
 * blocked (PRODUCT_SURFACE_INTEGRITY_AUDIT.md §3.A).
 */
class ReturnPolicySettingsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grant(\App\Models\User $user, string ...$permissions): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($permissions as $p) {
            $role->givePermission($p);
        }
    }

    public function test_return_policy_tab_renders(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');

        $this->actingAs($user)->get(route('settings.edit', ['tab' => 'return-policy']))
            ->assertOk()
            ->assertSee('Return Policy', false)
            ->assertSee('Refund making charges', false)
            ->assertSee('Save Return Policy', false);
    }

    public function test_saving_persists_fields_and_stamps_configured_at(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');

        // Before: unconfigured → returns flow is gated.
        $this->assertFalse((new ShopPreferences())->hasConfiguredReturnPolicy());

        $this->actingAs($user)->patch(route('settings.update.return-policy'), [
            'refund_making_charges'   => '0',   // retain making
            'refund_stone_charges'    => '1',
            'refund_hallmark_charges' => '1',
            'refund_gst'              => '1',
            'wear_loss_pct'           => '5',
            'restocking_fee_pct'      => '10',
            'return_window_days'      => '30',
            'exchange_window_days'    => '0',
            'return_settlement_mode'  => 'cash_or_credit',
            'exchange_rate_basis_locked' => '0',
        ])->assertRedirect(route('settings.edit', ['tab' => 'return-policy']));

        $prefs = ShopPreferences::withoutTenant()->where('shop_id', $shop->id)->first();
        $this->assertFalse((bool) $prefs->refund_making_charges, 'making charges set to retain');
        $this->assertTrue((bool) $prefs->refund_stone_charges);
        $this->assertEqualsWithDelta(5.0, (float) $prefs->wear_loss_pct, 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $prefs->restocking_fee_pct, 0.001);
        $this->assertSame(30, (int) $prefs->return_window_days);
        $this->assertSame('cash_or_credit', $prefs->return_settlement_mode);

        // After: configured → the returns/exchange gate is unblocked.
        $this->assertTrue($prefs->hasConfiguredReturnPolicy());
        $this->assertNotNull($prefs->return_policy_configured_at);
    }

    public function test_saving_requires_settings_edit_permission(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view'); // view but not edit

        $this->actingAs($user)->patch(route('settings.update.return-policy'), [
            'wear_loss_pct' => '5',
        ])->assertForbidden();
    }

    public function test_validation_rejects_out_of_range_deductions(): void
    {
        [$user] = $this->createRetailerTenant();
        $this->grant($user, 'settings.view', 'settings.edit');

        $this->actingAs($user)->patch(route('settings.update.return-policy'), [
            'wear_loss_pct'     => '99',   // > 25 max
            'restocking_fee_pct' => '5',
        ])->assertSessionHasErrors('wear_loss_pct');
    }
}
