<?php

namespace Tests\Feature;

use App\Jobs\RepriceRetailerInventoryJob;
use App\Models\Platform\ShopSubscription;
use App\Models\ShopDailyMetalRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class DailyRateReadOnlyIncidentTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();
        config(['platform.enforce_subscriptions' => true]);
    }

    public function test_read_only_retailer_dashboard_does_not_offer_the_daily_rate_form(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeReadOnly($shop->id, updateShop: true);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Enter Today&#039;s Metal Rates', false)
            ->assertDontSee('action="'.route('settings.pricing.save-rates').'"', false);
    }

    public function test_stale_modal_submit_in_read_only_mode_returns_a_visible_error_without_flashing_input(): void
    {
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeReadOnly($shop->id, updateShop: true);

        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => '5500',
                'silver_999_rate_per_kg' => '92000',
                'password' => 'not-for-the-session',
                'password_confirmation' => 'not-for-the-session',
                'current_password' => 'not-for-the-session',
                'otp' => '123456',
                'code' => '654321',
                'token' => 'secret-token',
                'api_key' => 'secret-api-key',
                'payment_card' => '4111111111111111',
                'document' => UploadedFile::fake()->create('private.pdf', 10),
            ]);

        $message = 'Today’s rates were not saved because this shop is in read-only mode. Extend or reactivate the subscription first.';
        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', $message)
            ->assertSessionMissing('_old_input');

        $this->assertDatabaseMissing('shop_daily_metal_rates', ['shop_id' => $shop->id]);
        Bus::assertNotDispatched(RepriceRetailerInventoryJob::class);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('meta name="flash-error"', false)
            ->assertSee($message)
            ->assertDontSee('Enter Today&#039;s Metal Rates', false);
    }

    public function test_first_write_that_transitions_the_shop_to_read_only_is_also_visible(): void
    {
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeReadOnly($shop->id, updateShop: false);

        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => '5500',
                'silver_999_rate_per_kg' => '92000',
            ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'Today’s rates were not saved because this shop is in read-only mode. Extend or reactivate the subscription first.')
            ->assertSessionMissing('_old_input');

        $this->assertSame('read_only', $shop->fresh()->access_mode);
        $this->assertDatabaseMissing('shop_daily_metal_rates', ['shop_id' => $shop->id]);
        Bus::assertNotDispatched(RepriceRetailerInventoryJob::class);
    }

    public function test_existing_read_only_json_request_keeps_the_existing_api_contract(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeReadOnly($shop->id, updateShop: true);

        $this->actingAs($user)
            ->postJson(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => 5500,
                'silver_999_rate_per_kg' => 92000,
            ])
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'Shop is in read-only mode. Write operations are not allowed.',
            ]);
    }

    public function test_first_json_write_that_transitions_to_read_only_keeps_the_account_api_contract(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeReadOnly($shop->id, updateShop: false);

        $this->actingAs($user)
            ->postJson(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => 5500,
                'silver_999_rate_per_kg' => 92000,
            ])
            ->assertStatus(423)
            ->assertExactJson([
                'message' => 'Shop is in read-only mode. Writes are blocked by platform policy.',
            ]);
    }

    public function test_read_only_mobile_request_remains_json_and_is_not_redirected(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeReadOnly($shop->id, updateShop: true);
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/mobile/pricing/today', [
            'gold_24k_rate_per_gram' => 5500,
            'silver_999_rate_per_kg' => 92000,
        ])
            ->assertStatus(403)
            ->assertExactJson([
                'message' => 'Shop is in read-only mode. Write operations are not allowed.',
            ]);
    }

    public function test_unrelated_read_only_web_write_keeps_the_original_error_bag_without_flashing_input(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeReadOnly($shop->id, updateShop: true);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->patch(route('settings.update.shop'), [
                'password' => 'not-for-the-session',
                'token' => 'secret-token',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['message'])
            ->assertSessionMissing('error')
            ->assertSessionMissing('_old_input');
    }

    public function test_unauthenticated_pricing_submission_still_redirects_to_login_without_flashing_input(): void
    {
        $this->post(route('settings.pricing.save-rates'), [
            'password' => 'not-for-the-session',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionMissing('_old_input');
    }

    public function test_suspended_shop_pricing_submission_still_logs_out_without_flashing_input(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $shop->forceFill(['access_mode' => 'suspended', 'is_active' => false])->save();

        $this->actingAs($user)
            ->post(route('settings.pricing.save-rates'), ['password' => 'not-for-the-session'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['mobile_number'])
            ->assertSessionMissing('_old_input');

        $this->assertGuest();
    }

    public function test_inactive_user_pricing_submission_still_logs_out_without_flashing_input(): void
    {
        [$user] = $this->createRetailerTenant();
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())
            ->post(route('settings.pricing.save-rates'), ['password' => 'not-for-the-session'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['mobile_number'])
            ->assertSessionMissing('_old_input');

        $this->assertGuest();
    }

    public function test_active_modal_validation_still_uses_its_named_bag_and_old_input(): void
    {
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => 'invalid',
                'silver_999_rate_per_kg' => '92000',
            ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('pricingModal', ['gold_24k_rate_per_gram'])
            ->assertSessionHas('_old_input.silver_999_rate_per_kg', '92000');

        $message = session('errors')->getBag('pricingModal')->first('gold_24k_rate_per_gram');
        $this->assertNotSame('', $message);
        $this->assertDatabaseMissing('shop_daily_metal_rates', ['shop_id' => $shop->id]);
        Bus::assertNotDispatched(RepriceRetailerInventoryJob::class);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($message)
            ->assertSee('value="92000"', false);
    }

    public function test_active_retailer_dashboard_still_offers_the_daily_rate_form_when_rates_are_missing(): void
    {
        [$user] = $this->createRetailerTenant();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Enter Today&#039;s Metal Rates', false)
            ->assertSee('action="'.route('settings.pricing.save-rates').'"', false);
    }

    public function test_active_plain_numeric_save_keeps_the_original_success_redirect_and_units(): void
    {
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();

        $this->actingAs($user)
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => '5500',
                'silver_999_rate_per_kg' => '92000',
            ])
            ->assertRedirect(route('settings.edit', ['tab' => 'pricing']))
            ->assertSessionHasNoErrors();

        $row = ShopDailyMetalRate::withoutTenant()
            ->where('shop_id', $shop->id)
            ->firstOrFail();

        $this->assertSame(5500.0, (float) $row->gold_24k_rate_per_gram);
        $this->assertSame(92.0, (float) $row->silver_999_rate_per_gram);
        Bus::assertDispatchedTimes(RepriceRetailerInventoryJob::class, 1);
    }

    public function test_active_dhiran_dashboard_behavior_is_unchanged(): void
    {
        [$user] = $this->createRetailerTenant();
        $user->forceFill([
            'realm' => 'dhiran',
            'email_verified_at' => now(),
        ])->save();

        $this->actingAs(User::findOrFail($user->id))
            ->get('https://dhiran.jewelflows.com/dhiran')
            ->assertOk()
            ->assertDontSee('Enter Today&#039;s Metal Rates', false);
    }

    private function makeReadOnly(int $shopId, bool $updateShop): void
    {
        ShopSubscription::query()
            ->where('shop_id', $shopId)
            ->latest('id')
            ->firstOrFail()
            ->update(['status' => 'read_only']);

        if (! $updateShop) {
            return;
        }

        $shop = \App\Models\Shop::query()->findOrFail($shopId);
        $shop->forceFill([
            'access_mode' => 'read_only',
            'is_active' => false,
        ])->save();
    }
}
