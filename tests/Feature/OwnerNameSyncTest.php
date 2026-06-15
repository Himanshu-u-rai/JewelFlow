<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Shop Info → Owner Details and Profile → Name are independent (registered shop
 * owner vs login identity). updateShop() seeds the owner user's display name
 * from Owner Details ONLY when that name was never personalised on the Profile
 * tab — a deliberately-set login name must never be silently overwritten.
 */
class OwnerNameSyncTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        // Owner write routes are can:settings.edit-gated; the harness owner role
        // has no synced permissions, so bypass authorization (owners hold it in
        // production), like the other settings-controller tests.
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Auth\Middleware\Authorize::class,
        ]);
    }

    /** The full required payload for updateShop, with overridable owner names. */
    private function shopPayload(array $overrides = []): array
    {
        return array_merge([
            'name'             => 'GoldLux',
            'phone'            => '9000000001',
            'address_line1'    => '53, Balaji Apt',
            'city'             => 'Jaipur',
            'state'            => 'Rajasthan',
            'pincode'          => '302029',
            'owner_first_name' => 'Sunny',
            'owner_last_name'  => 'Test',
            'owner_mobile'     => '9054732126',
            'wastage_recovery_percent' => 100,
        ], $overrides);
    }

    public function test_owner_details_save_updates_the_shop_owner_columns(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)
            ->patch(route('settings.update.shop'), $this->shopPayload([
                'owner_first_name' => 'Sunny', 'owner_last_name' => 'Test',
            ]));

        $shop->refresh();
        $this->assertSame('Sunny', $shop->owner_first_name);
        $this->assertSame('Test', $shop->owner_last_name);
    }

    public function test_owner_name_syncs_when_login_name_matches_old_owner_details(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        // Owner user's login name currently equals the shop's owner-details name
        // (the "never personalised" state) — so it SHOULD follow the update.
        $user->forceFill(['name' => 'Owner Test'])->save(); // = old owner_first+last

        $this->actingAs($user)
            ->patch(route('settings.update.shop'), $this->shopPayload([
                'owner_first_name' => 'Sunny', 'owner_last_name' => 'Test',
            ]));

        $this->assertSame('Sunny Test', $user->fresh()->name, 'unpersonalised name follows owner details');
    }

    public function test_personalised_login_name_is_not_clobbered_by_owner_details_save(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        // Owner deliberately set a DIFFERENT login name on the Profile tab.
        $user->forceFill(['name' => 'Himanshu Rai'])->save();

        $this->actingAs($user)
            ->patch(route('settings.update.shop'), $this->shopPayload([
                'owner_first_name' => 'Sunny', 'owner_last_name' => 'Test',
            ]));

        // Shop owner details update…
        $shop->refresh();
        $this->assertSame('Sunny', $shop->owner_first_name);
        // …but the personalised login name is preserved (the fix).
        $this->assertSame('Himanshu Rai', $user->fresh()->name, 'personalised login name is not overwritten');
    }

    public function test_blank_login_name_is_seeded_from_owner_details(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $user->forceFill(['name' => ''])->save();

        $this->actingAs($user)
            ->patch(route('settings.update.shop'), $this->shopPayload([
                'owner_first_name' => 'Sunny', 'owner_last_name' => 'Test',
            ]));

        $this->assertSame('Sunny Test', $user->fresh()->name, 'blank name is seeded');
    }
}
