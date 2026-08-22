<?php

namespace Tests\Feature;

use App\Jobs\RepriceRetailerInventoryJob;
use App\Models\ShopDailyMetalRate;
use App\Services\ShopPricingService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class DailyRateBusinessDateTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function businessDateCases(): array
    {
        return [
            'one minute before IST midnight' => ['2026-07-18 18:29:00 UTC', '2026-07-18'],
            'exactly IST midnight' => ['2026-07-18 18:30:00 UTC', '2026-07-19'],
            'incident time' => ['2026-07-18 18:47:00 UTC', '2026-07-19'],
            'after 05:30 IST' => ['2026-07-19 01:00:00 UTC', '2026-07-19'],
        ];
    }

    public static function acceptedRateInputs(): array
    {
        return [
            'plain decimals' => ['5500.1250', '92000.1000', 5500.125, 92.0001],
            'western comma grouping' => ['5,500.1250', '92,000.1000', 5500.125, 92.0001],
            'Indian comma grouping' => ['5,50,000.1250', '9,20,00,000.1000', 550000.125, 92000.0001],
        ];
    }

    public static function rejectedRateInputs(): array
    {
        return [
            'negative' => ['-5500', '92000', 'gold_24k_rate_per_gram'],
            'zero' => ['0', '92000', 'gold_24k_rate_per_gram'],
            'alphabetic' => ['5500', '92k', 'silver_999_rate_per_kg'],
            'currency symbol' => ['₹5,500', '92000', 'gold_24k_rate_per_gram'],
            'malformed comma grouping' => ['5,50,0', '92000', 'gold_24k_rate_per_gram'],
            'scientific notation' => ['5e3', '92000', 'gold_24k_rate_per_gram'],
        ];
    }

    #[DataProvider('businessDateCases')]
    public function test_business_date_uses_the_ist_calendar_boundary(string $utcNow, string $expectedDate): void
    {
        Carbon::setTestNow(Carbon::parse($utcNow));
        [, $shop] = $this->createRetailerTenant();

        $this->assertSame(
            $expectedDate,
            app(ShopPricingService::class)->businessDateString($shop)
        );
    }

    public function test_incident_time_modal_save_is_visible_to_every_immediate_read_path(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 18:47:00 UTC'));
        Bus::fake();

        [$user, $shop] = $this->createRetailerTenant();
        $dhiranShop = $this->createShop('dhiran');

        $previousRate = ShopDailyMetalRate::withoutTenant()->create([
            'shop_id' => $shop->id,
            'business_date' => '2026-07-18',
            'timezone' => 'Asia/Kolkata',
            'gold_24k_rate_per_gram' => 5400,
            'silver_999_rate_per_gram' => 90,
            'entered_by_user_id' => $user->id,
            'entered_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        Cache::put('unrelated-shop-date-state', 'preserve-me');
        $this->withSession(['unrelated-session-state' => 'preserve-me']);

        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => '5500',
                'silver_999_rate_per_kg' => '92000',
            ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('unrelated-session-state', 'preserve-me');

        $this->assertSame('preserve-me', Cache::get('unrelated-shop-date-state'));

        $savedRate = ShopDailyMetalRate::withoutTenant()
            ->where('shop_id', $shop->id)
            ->whereDate('business_date', '2026-07-19')
            ->firstOrFail();

        $this->assertSame(5500.0, (float) $savedRate->gold_24k_rate_per_gram);
        $this->assertSame(92.0, (float) $savedRate->silver_999_rate_per_gram);
        $this->assertSame(5400.0, (float) $previousRate->fresh()->gold_24k_rate_per_gram);
        $this->assertSame(90.0, (float) $previousRate->fresh()->silver_999_rate_per_gram);
        $this->assertDatabaseCount('shop_daily_metal_rates', 2);
        $this->assertDatabaseMissing('shop_daily_metal_rates', ['shop_id' => $dhiranShop->id]);

        foreach (range(1, 2) as $_) {
            $this->actingAs($user)
                ->get(route('dashboard'))
                ->assertOk()
                ->assertDontSee('Enter Today&#039;s Metal Rates', false);
        }

        $this->flushSession();
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Enter Today&#039;s Metal Rates', false);

        Sanctum::actingAs($user);
        $this->getJson('/api/mobile/bootstrap')
            ->assertOk()
            ->assertJsonPath('pricing.rates_set_today', true)
            ->assertJsonPath('pricing.business_date', '2026-07-19')
            ->assertJsonPath('pricing.gold_24k_rate_per_gram', 5500)
            ->assertJsonPath('pricing.silver_999_rate_per_gram', 92);

        $history = app(ShopPricingService::class)->resolvedRateHistory($shop, [
            'date_from' => '2026-07-19',
            'date_to' => '2026-07-19',
        ]);
        $this->assertNotEmpty($history->items());
        $this->assertSame(
            ['2026-07-19'],
            collect($history->items())->pluck('business_date')->map(fn ($date) => (string) $date)->unique()->values()->all()
        );

        Bus::assertDispatchedTimes(RepriceRetailerInventoryJob::class, 1);
        Bus::assertDispatched(
            RepriceRetailerInventoryJob::class,
            fn (RepriceRetailerInventoryJob $job) => $job->shopId === (int) $shop->id
                && $job->businessDate === '2026-07-19'
                && $job->afterCommit === true
        );
    }

    #[DataProvider('acceptedRateInputs')]
    public function test_only_supported_plain_and_comma_formatted_rates_are_normalized(
        string $gold,
        string $silver,
        float $expectedGold,
        float $expectedSilverPerGram
    ): void {
        Carbon::setTestNow(Carbon::parse('2026-07-19 01:00:00 UTC'));
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();

        $this->actingAs($user)->post(route('settings.pricing.save-rates'), [
            'context' => 'modal',
            'gold_24k_rate_per_gram' => $gold,
            'silver_999_rate_per_kg' => $silver,
        ])->assertRedirect(route('dashboard'))->assertSessionHasNoErrors();

        $rate = ShopDailyMetalRate::withoutTenant()
            ->where('shop_id', $shop->id)
            ->whereDate('business_date', '2026-07-19')
            ->firstOrFail();

        $this->assertSame($expectedGold, (float) $rate->gold_24k_rate_per_gram);
        $this->assertSame($expectedSilverPerGram, (float) $rate->silver_999_rate_per_gram);
        Bus::assertDispatchedTimes(RepriceRetailerInventoryJob::class, 1);
    }

    /**
     * Re-saving is never a silent no-op.
     *
     * An earlier draft compared the incoming rates against the stored ones and
     * skipped both the write and the reprice when they matched. That turned
     * "owner re-saves to force a refresh" into a success toast with nothing
     * behind it. Repricing is a background job over one shop's in-stock items —
     * cheap enough that always doing it beats explaining why it sometimes
     * didn't.
     *
     * So the app asks twice, on purpose. Collapsing the duplicate is the
     * queue's job, via ShouldBeUnique keyed on shop+business-date, which is
     * what the matching uniqueId assertion below pins down. Bus::fake() does
     * not apply that lock, which is exactly why the dispatch count here is 2.
     */
    public function test_immediate_duplicate_submits_create_one_row_and_reprice_under_one_unique_key(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 18:47:00 UTC'));
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();

        $payload = [
            'context' => 'modal',
            'gold_24k_rate_per_gram' => '5500',
            'silver_999_rate_per_kg' => '92000',
        ];

        $this->actingAs($user)->post(route('settings.pricing.save-rates'), $payload)->assertRedirect(route('dashboard'));
        $this->actingAs($user)->post(route('settings.pricing.save-rates'), $payload)->assertRedirect(route('dashboard'));

        $this->assertSame(1, ShopDailyMetalRate::withoutTenant()
            ->where('shop_id', $shop->id)
            ->whereDate('business_date', '2026-07-19')
            ->count());

        $dispatched = Bus::dispatched(RepriceRetailerInventoryJob::class);
        $this->assertCount(2, $dispatched, 'both saves should ask for a reprice');
        $this->assertSame(
            [$shop->id.':2026-07-19'],
            $dispatched->map(fn ($job) => $job->uniqueId())->unique()->values()->all(),
            'both reprices share one uniqueness key, so the queue runs one'
        );
    }

    public function test_changed_rates_update_the_same_row_and_reprice_under_one_unique_key(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 18:47:00 UTC'));
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();

        ShopDailyMetalRate::withoutTenant()->create([
            'shop_id' => $shop->id,
            'business_date' => '2026-07-19',
            'timezone' => 'Asia/Kolkata',
            'gold_24k_rate_per_gram' => '5500',
            'silver_999_rate_per_gram' => '92',
            'entered_by_user_id' => $user->id,
        ]);

        $changedPayload = [
            'context' => 'modal',
            'gold_24k_rate_per_gram' => '5600',
            'silver_999_rate_per_kg' => '93000',
        ];

        $this->actingAs($user)->post(route('settings.pricing.save-rates'), $changedPayload)->assertRedirect(route('dashboard'));
        $this->actingAs($user)->post(route('settings.pricing.save-rates'), $changedPayload)->assertRedirect(route('dashboard'));

        $rate = ShopDailyMetalRate::withoutTenant()
            ->where('shop_id', $shop->id)
            ->whereDate('business_date', '2026-07-19')
            ->firstOrFail();

        $this->assertSame(5600.0, (float) $rate->gold_24k_rate_per_gram);
        $this->assertSame(93.0, (float) $rate->silver_999_rate_per_gram);
        $this->assertDatabaseCount('shop_daily_metal_rates', 1);

        $dispatched = Bus::dispatched(RepriceRetailerInventoryJob::class);
        $this->assertCount(2, $dispatched);
        $this->assertSame(
            [$shop->id.':2026-07-19'],
            $dispatched->map(fn ($job) => $job->uniqueId())->unique()->values()->all()
        );
    }

    /**
     * Deploy back-compatibility for jobs already sitting on the queue.
     *
     * The previous release constructed this job with a shop id and nothing
     * else. If businessDate were a promoted constructor property it would have
     * no declared default, so unserialising one of those payloads would leave
     * it uninitialised and fatal here — losing every reprice in flight at the
     * moment of deploy, with nothing surfacing the failure to the shop.
     *
     * newInstanceWithoutConstructor is how the queue rebuilds a job, so it is
     * the honest way to reproduce that payload.
     */
    public function test_a_job_queued_before_this_release_still_has_a_usable_unique_key(): void
    {
        $job = (new \ReflectionClass(RepriceRetailerInventoryJob::class))
            ->newInstanceWithoutConstructor();

        // The old payload carried shopId and only shopId; the queue restores
        // exactly the properties it serialised.
        $job->shopId = 17;

        $this->assertNull($job->businessDate, 'declared default must survive an old payload');
        $this->assertSame('17:current', $job->uniqueId());
    }

    public function test_repricing_job_has_a_shop_and_business_date_uniqueness_key(): void
    {
        $job = new RepriceRetailerInventoryJob(17, '2026-07-19');

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('17:2026-07-19', $job->uniqueId());
    }

    public function test_delayed_repricing_job_uses_the_saved_business_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 18:47:00 UTC'));
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();
        $item = $this->createItem($shop->id, null, [
            'gross_weight' => 10,
            'stone_weight' => 1,
            'purity' => 22,
            'cost_price' => 1,
            'selling_price' => 1,
        ]);

        $this->actingAs($user)->post(route('settings.pricing.save-rates'), [
            'context' => 'modal',
            'gold_24k_rate_per_gram' => '5500',
            'silver_999_rate_per_kg' => '92000',
        ])->assertRedirect(route('dashboard'));

        Carbon::setTestNow(Carbon::parse('2026-07-19 18:47:00 UTC'));
        (new RepriceRetailerInventoryJob((int) $shop->id, '2026-07-19'))
            ->handle(app(ShopPricingService::class));

        $this->assertSame(45375.0, round((float) $item->fresh()->cost_price, 2));
    }

    #[DataProvider('rejectedRateInputs')]
    public function test_invalid_rate_formats_create_no_row_or_job(
        string $gold,
        string $silver,
        string $errorField
    ): void {
        Carbon::setTestNow(Carbon::parse('2026-07-19 01:00:00 UTC'));
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => $gold,
                'silver_999_rate_per_kg' => $silver,
            ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('pricingModal', [$errorField])
            ->assertSessionHas('_old_input.gold_24k_rate_per_gram', $gold)
            ->assertSessionHas('_old_input.silver_999_rate_per_kg', $silver);

        $this->assertDatabaseMissing('shop_daily_metal_rates', ['shop_id' => $shop->id]);
        Bus::assertNotDispatched(RepriceRetailerInventoryJob::class);
    }

    public function test_validation_failure_is_visible_in_the_modal_and_preserves_valid_input(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 01:00:00 UTC'));
        Bus::fake();
        [$user, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => 'not-a-rate',
                'silver_999_rate_per_kg' => '92000',
            ]);

        $response
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrorsIn('pricingModal', ['gold_24k_rate_per_gram'])
            ->assertSessionHas('_old_input.gold_24k_rate_per_gram', 'not-a-rate')
            ->assertSessionHas('_old_input.silver_999_rate_per_kg', '92000');

        $this->assertDatabaseMissing('shop_daily_metal_rates', ['shop_id' => $shop->id]);
        Bus::assertNotDispatched(RepriceRetailerInventoryJob::class);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Enter Today&#039;s Metal Rates', false)
            ->assertSee('Enter a valid gold rate using digits and an optional decimal point.')
            ->assertSee('name="silver_999_rate_per_kg"', false)
            ->assertSee('value="92000"', false)
            ->assertDontSee('Today&#039;s pricing rates were saved', false);
    }

    public function test_settings_form_keeps_its_pricing_tab_redirect(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 01:00:00 UTC'));
        Bus::fake();
        [$user] = $this->createRetailerTenant();

        $this->actingAs($user)->post(route('settings.pricing.save-rates'), [
            'gold_24k_rate_per_gram' => '5500',
            'silver_999_rate_per_kg' => '92000',
        ])->assertRedirect(route('settings.edit', ['tab' => 'pricing']));
    }

    public function test_turbo_modal_save_receives_a_valid_dashboard_redirect(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-19 01:00:00 UTC'));
        Bus::fake();
        [$user] = $this->createRetailerTenant();

        $this->actingAs($user)
            ->withHeaders([
                'Turbo-Frame' => '_top',
                'Accept' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
            ])
            ->post(route('settings.pricing.save-rates'), [
                'context' => 'modal',
                'gold_24k_rate_per_gram' => '5500',
                'silver_999_rate_per_kg' => '92000',
            ])
            ->assertRedirect(route('dashboard'));
    }
}
