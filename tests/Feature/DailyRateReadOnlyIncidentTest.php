<?php

namespace Tests\Feature;

use App\Jobs\RepriceRetailerInventoryJob;
use App\Models\Permission;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\ShopSubscription;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopDailyMetalRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * JF-0001 — the daily-rate prompt must never go silent.
 *
 * The incident: a retailer shop that could not write saw the "Enter Today's
 * Metal Rates" modal simply vanish, with nothing in its place. The guarantee
 * this class defends is therefore about VISIBILITY, not about any one lock.
 *
 * Post-P0 that guarantee splits along the two axes the old code conflated,
 * so each case below is classified by which lock it is actually modelling:
 *
 *   ADMINISTRATOR HOLD (`read_only` + a `suspended_by` stamp)
 *     A human platform admin deliberately froze a shop they still want to
 *     inspect. The shop stays browsable, writes are refused in place, and the
 *     owner is told why. These cases keep their original read-only GET /
 *     write-block expectations verbatim.
 *
 *   LAPSE (a legacy expiry-minted `read_only` row, NO `suspended_by`)
 *     Nobody acted; a bill went unpaid. There is no ERP to browse: the row is
 *     reconciled onto the entitlement axis (`suspended`) and the owner is
 *     routed to the plan picker. Silence is still a bug — it is now cured by
 *     actively sending the owner somewhere they can fix it, which is strictly
 *     stronger than the notice-on-a-dead-dashboard the incident asked for.
 *
 * Every assertion that is about daily-rate behaviour rather than about which
 * lock is in force (no `_old_input` leakage of passwords/tokens/cards, no rate
 * row written, no repricing job dispatched, JSON stays JSON) is preserved
 * unchanged in both classes.
 */
class DailyRateReadOnlyIncidentTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();
        config(['platform.enforce_subscriptions' => true]);
    }

    /**
     * ADMINISTRATOR HOLD. The dashboard-notice guarantee only has meaning while
     * the shop is still browsable, which post-P0 is exactly and only the case
     * under an admin hold — so this is the case that keeps it.
     */
    public function test_administrative_read_only_retailer_dashboard_does_not_offer_the_daily_rate_form(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeAdministrativeReadOnly($shop->id);

        // Regression guard for the JF-0001 incident: the modal must disappear
        // for a genuinely read-only shop, but silence is itself a bug — the
        // owner must be told *why* rates can't be entered, not left staring
        // at a dashboard where the rate prompt just vanished.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Enter Today&#039;s Metal Rates', false)
            ->assertDontSee('action="'.route('settings.pricing.save-rates').'"', false)
            ->assertSee("This shop's subscription is read_only. Extend or reactivate the subscription to enter today's Pricing rates.");

        // The read must not have reconciled the hold away: the subscription
        // underneath is healthy, so an unguarded reconciler would resolve this
        // shop to `active` and silently release a platform admin's hold.
        $fresh = $shop->fresh();
        $this->assertSame('read_only', $fresh->access_mode);
        $this->assertNotNull($fresh->suspended_by, 'The admin hold must survive a read.');
    }

    /**
     * LAPSE. Reproduces the exact production incident state: a Goldlux-style
     * shop whose subscription lapsed and was never cleanly re-synced, leaving
     * several superseded `read_only` rows all pointing at the same expired
     * term. None carries a `suspended_by` stamp, because no administrator ever
     * acted — this is an unpaid bill, not a hold.
     *
     * The incident's demand ("never a silently missing modal") is met more
     * strongly than before: instead of a notice on a dashboard the owner can do
     * nothing with, there is no ERP at all and the owner is routed to the plan
     * picker. Multiplicity of stale rows must not change that.
     */
    public function test_lapsed_stale_read_only_rows_route_the_owner_to_renewal(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $expiredStart = now()->subDays(37)->toDateString();
        $expiredEnd = now()->subDays(17)->toDateString();

        ShopSubscription::query()->where('shop_id', $shop->id)->delete();
        foreach (range(1, 3) as $_) {
            ShopSubscription::create([
                'shop_id' => $shop->id,
                'plan_id' => \App\Models\Platform\Plan::query()->first()->id,
                'status' => 'read_only',
                'starts_at' => $expiredStart,
                'ends_at' => $expiredEnd,
                'grace_ends_at' => $expiredEnd,
            ]);
        }
        $shop->forceFill([
            'access_mode' => 'read_only',
            'is_active' => false,
            'suspended_at' => now(),
            'suspension_reason' => 'Subscription read_only',
            'suspended_by' => null,
        ])->save();

        // No ERP: the dashboard is not rendered for a lapsed shop at all.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertRedirect(route('subscription.plans'))
            ->assertSessionHas('error', 'Your subscription has ended. Choose a plan to restore access to your shop.');

        // The stale rows are reconciled onto the entitlement axis...
        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode, 'a lapse belongs on the entitlement axis');
        $this->assertNull($fresh->suspended_by, 'A lapse must never look administrative.');

        // ...and the renewal the owner was sent to is actually reachable, which
        // is the whole point: the old contract stranded them in a browsable ERP
        // with no way out.
        $this->actingAs($user)->get(route('subscription.plans'))->assertOk();

        // Writes stay blocked throughout, and no rate row is written.
        $this->actingAs($user)
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => '5500',
                'silver_999_rate_per_kg' => '92000',
            ])
            ->assertRedirect(route('subscription.plans'));

        $this->assertDatabaseMissing('shop_daily_metal_rates', ['shop_id' => $shop->id]);
    }

    /**
     * ADMINISTRATOR HOLD. Guards the secret-scrubbing contract: a refused rate
     * submission must never flash passwords, OTPs, tokens, API keys or card
     * numbers into `_old_input`. That is a security assertion independent of
     * which lock refused the write, and is preserved verbatim.
     */
    public function test_administrative_read_only_stale_modal_submit_returns_a_visible_error_without_flashing_input(): void
    {
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeAdministrativeReadOnly($shop->id);

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

    /**
     * LAPSE, mid-reconciliation. The subscription row has already lapsed but the
     * shop row has not been told yet — a state only the old expiry fork could
     * produce, since an administrative writer always stamps the shop at the same
     * moment it freezes it. The very first write is what reconciles it.
     *
     * "Visible" now means routed, not merely flashed: the owner lands on the
     * plan picker instead of bouncing back to a dashboard that cannot help.
     */
    public function test_first_write_under_a_lapsed_read_only_row_reconciles_and_routes_to_renewal(): void
    {
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeLegacyExpiryReadOnly($shop->id, updateShop: false);

        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => '5500',
                'silver_999_rate_per_kg' => '92000',
            ]);

        $response
            ->assertRedirect(route('subscription.plans'))
            ->assertSessionHas('error', 'Your subscription has ended. Choose a plan to restore access to your shop.')
            ->assertSessionMissing('_old_input');

        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode, 'the lapsed row reconciles onto the entitlement axis');
        $this->assertNull($fresh->suspended_by, 'A lapse must never look administrative.');

        $this->assertDatabaseMissing('shop_daily_metal_rates', ['shop_id' => $shop->id]);
        Bus::assertNotDispatched(RepriceRetailerInventoryJob::class);
    }

    /** ADMINISTRATOR HOLD. The settled read-only JSON write contract, unchanged. */
    public function test_existing_administrative_read_only_json_request_keeps_the_existing_api_contract(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeAdministrativeReadOnly($shop->id);

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

    /**
     * LAPSE, mid-reconciliation, on the JSON surface.
     *
     * The old contract answered 423 "Writes are blocked by platform policy" —
     * EnsureAccountIsActive's dead end, which tells a mobile client nothing
     * about how to recover and is indistinguishable from an admin freeze. A
     * lapse IS recoverable, so EnsureSubscriptionIsActive now answers first with
     * the stable SUBSCRIPTION_REQUIRED code that sends the app to a renew
     * screen rather than a contact-support screen.
     */
    public function test_first_json_write_under_a_lapsed_read_only_row_returns_the_recoverable_api_code(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeLegacyExpiryReadOnly($shop->id, updateShop: false);

        $this->actingAs($user)
            ->postJson(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => 5500,
                'silver_999_rate_per_kg' => 92000,
            ])
            ->assertStatus(403)
            ->assertExactJson([
                'code' => 'SUBSCRIPTION_REQUIRED',
                'message' => 'Your subscription has ended. Renew a plan to restore access.',
            ]);

        $fresh = $shop->fresh();
        $this->assertSame('suspended', $fresh->access_mode);
        $this->assertNull($fresh->suspended_by, 'A lapse must never look administrative.');
    }

    /**
     * ADMINISTRATOR HOLD. Guards the transport contract — a mobile client must
     * get JSON, never an HTML login redirect. Independent of which lock fired.
     */
    public function test_administrative_read_only_mobile_request_remains_json_and_is_not_redirected(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeAdministrativeReadOnly($shop->id);
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

    /**
     * ADMINISTRATOR HOLD. A NON-pricing write must keep the default error bag
     * (not the pricing flash) and still scrub secrets from `_old_input`.
     */
    public function test_unrelated_administrative_read_only_web_write_keeps_the_original_error_bag_without_flashing_input(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeAdministrativeReadOnly($shop->id);

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

    public function test_sales_counter_redirect_gives_active_owner_the_generic_missing_rates_message(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $this->actingAs($user)
            ->get(route('pos.index'))
            ->assertRedirect(route('settings.edit', ['tab' => 'pricing']))
            ->assertSessionHas('error', 'Today\'s Pricing rates are missing. Please save today\'s rates to continue.');
    }

    /**
     * ADMINISTRATOR HOLD. The POS entry point must explain the lock rather than
     * showing the generic "rates are missing" message — the owner cannot fix
     * missing rates while frozen, so the message has to name the real cause.
     */
    public function test_sales_counter_redirect_gives_administrative_read_only_owner_a_subscription_specific_message(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->makeAdministrativeReadOnly($shop->id);

        $this->actingAs($user)
            ->get(route('pos.index'))
            ->assertRedirect(route('settings.edit', ['tab' => 'pricing']))
            ->assertSessionHas(
                'error',
                "Today's Pricing rates are missing and this shop's subscription is read_only. Extend or reactivate the subscription to resume selling."
            );
    }

    public function test_sales_counter_redirect_for_non_owner_staff_is_unchanged(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $staff = $this->makeStaffUser($shop);

        $this->actingAs($staff)
            ->get(route('pos.index'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'Today\'s retailer pricing is missing. Ask the owner to save today\'s Pricing rates first.');
    }

    private function makeStaffUser(Shop $shop): User
    {
        $role = new Role();
        $role->forceFill([
            'name' => 'staff',
            'display_name' => 'Staff',
            'shop_id' => $shop->id,
        ])->save();

        $role->permissions()->sync(Permission::query()->where('name', 'sales.pos')->pluck('id'));

        return User::factory()->create([
            'shop_id' => $shop->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    /**
     * A genuine JewelFlows ADMINISTRATOR hold.
     *
     * `suspended_by` is the proof-positive discriminator — every administrative
     * writer stamps it and nothing else in the application ever does. The
     * subscription underneath is deliberately left HEALTHY: that is what proves
     * the hold lives on its own axis, and that subscription reconciliation
     * (which resolves a healthy row to `active`) can never lift it.
     */
    private function makeAdministrativeReadOnly(int $shopId): void
    {
        $admin = PlatformAdmin::create([
            'first_name' => 'Platform',
            'last_name' => 'Admin',
            'name' => 'Platform Admin',
            'email' => 'platform'.fake()->unique()->numberBetween(100, 99999).'@example.com',
            'mobile_number' => '9'.fake()->unique()->numerify('#########'),
            'password' => Hash::make('password123'),
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        ShopSubscription::query()
            ->where('shop_id', $shopId)
            ->latest('id')
            ->firstOrFail()
            ->update([
                'status' => 'active',
                'ends_at' => now()->addMonth()->toDateString(),
            ]);

        Shop::query()->findOrFail($shopId)->forceFill([
            'access_mode' => 'read_only',
            'is_active' => true,
            'suspended_at' => now(),
            'suspension_reason' => 'Compliance review by platform admin',
            'suspended_by' => $admin->id,
        ])->save();
    }

    /**
     * A LEGACY expiry-minted `read_only` subscription row: the artefact the old
     * expiry fork produced when a term lapsed. It carries NO administrator
     * stamp, because no administrator ever acted — it is an unpaid bill wearing
     * an administrative costume.
     *
     * `$updateShop: false` reproduces the mid-incident state where the row had
     * already lapsed but no request had yet reconciled the shop. That is the
     * exact path the middleware's reconciler has to handle, so it is kept as a
     * distinct fixture rather than folded into the settled state.
     */
    private function makeLegacyExpiryReadOnly(int $shopId, bool $updateShop): void
    {
        ShopSubscription::query()
            ->where('shop_id', $shopId)
            ->latest('id')
            ->firstOrFail()
            ->update([
                'status' => 'read_only',
                'ends_at' => now()->subDays(17)->toDateString(),
            ]);

        if (! $updateShop) {
            return;
        }

        Shop::query()->findOrFail($shopId)->forceFill([
            'access_mode' => 'read_only',
            'is_active' => false,
            'suspended_at' => now(),
            'suspension_reason' => 'Subscription read_only',
            'suspended_by' => null,
        ])->save();
    }
}
