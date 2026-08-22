<?php

namespace Tests\Feature\Masters;

use App\Models\Customer;
use App\Models\OnboardingBatch;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * `customers.mobile` has exactly one legal spelling: the last 10 digits.
 *
 * The (shop_id, mobile) unique index makes the STORED STRING unique, not the
 * human. So any write path that stores a number as typed silently defeats it:
 * '+91 98123 00099' and '9812300099' are two rows for one buyer, their history
 * splits across both, and historical customer matching — which normalises on
 * read — can never find the un-normalised copy.
 *
 * Most write paths validate `digits:10` and are safe by construction. Three do
 * not, and each is exercised here through its REAL route rather than by calling
 * the helper directly, because the bug is never in the helper — it is in a call
 * site that forgot to use it:
 *
 *   - Quick Bill              (`customer_mobile` is `max:20` free text)
 *   - Onboarding CSV import   (mobile column is whatever the shop's old system wrote)
 *   - Onboarding manual add   (`mobile` is `max:20` free text)
 */
class CustomerMobileCanonicalFormTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    /** The same human, spelled the way each surface's users actually type it. */
    private const CANONICAL = '9812300099';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Customer> */
    private function rows(int $shopId)
    {
        return Customer::withoutTenant()->where('shop_id', $shopId);
    }

    /** A minimal but VALID quick bill; only the mobile spelling varies per test. */
    private function quickBill(string $mobile): array
    {
        return [
            'customer_name'   => 'Ramesh Kumar',
            'customer_mobile' => $mobile,
            'bill_date'       => '2026-08-01',
            'pricing_mode'    => 'no_gst',
            'gst_rate'        => 0,
            'items'           => [['description' => 'Gold chain', 'quantity' => 1, 'rate' => 1000]],
        ];
    }

    private function openBatch(int $shopId): OnboardingBatch
    {
        TenantContext::set($shopId);
        $this->post(route('onboarding.store'), ['start_date' => '2026-08-01'])->assertRedirect();

        return OnboardingBatch::withoutTenant()->where('shop_id', $shopId)->firstOrFail();
    }

    // ------------------------------------------------------------- Quick Bill

    public function test_quick_bill_walk_in_stores_the_canonical_mobile(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $this->seedRetailerPricing($shop, $user);

        TenantContext::runFor($shop->id, fn () => $this->post(
            route('quick-bills.store'), $this->quickBill('+91 98123 00099')
        ))->assertSessionHasNoErrors();

        $this->assertSame([self::CANONICAL], $this->rows($shop->id)->pluck('mobile')->all());
    }

    // -------------------------------------------------------- Onboarding: CSV

    public function test_onboarding_csv_import_stores_the_canonical_mobile(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $batch = $this->openBatch($shop->id);

        $csv = "first_name,last_name,mobile,email,address\nRamesh,Kumar,+91 98123 00099,,\n";

        TenantContext::set($shop->id);
        $this->post(route('onboarding.customers.import', $batch), [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', $csv),
        ])->assertSessionHasNoErrors();

        $this->assertSame([self::CANONICAL], $this->rows($shop->id)->pluck('mobile')->all());
    }

    /**
     * The import's own dedupe reads `where('mobile', $raw)`. If that lookup is
     * not normalised too, a second spelling misses the existing row, gets
     * inserted, and the unique index turns a silent duplicate into a 500 —
     * so the lookup and the insert have to be normalised together.
     */
    public function test_onboarding_csv_import_dedupes_across_spellings(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $batch = $this->openBatch($shop->id);

        $csv = "first_name,last_name,mobile,email,address\n"
             . "Ramesh,Kumar,9812300099,,\n"
             . "Ramesh,Kumar,+91 98123 00099,,\n"
             . "Ramesh,Kumar,098123-00099,,\n";

        TenantContext::set($shop->id);
        $this->post(route('onboarding.customers.import', $batch), [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', $csv),
        ])->assertSessionHasNoErrors();

        $this->assertSame([self::CANONICAL], $this->rows($shop->id)->pluck('mobile')->all(),
            'three spellings of one number must import as one customer');
    }

    // ----------------------------------------------------- Onboarding: manual

    public function test_onboarding_manual_add_stores_the_canonical_mobile(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $batch = $this->openBatch($shop->id);

        TenantContext::set($shop->id);
        $this->post(route('onboarding.customers.store', $batch), [
            'first_name' => 'Ramesh',
            'mobile'     => '+91 98123 00099',
        ])->assertSessionHasNoErrors();

        $this->assertSame([self::CANONICAL], $this->rows($shop->id)->pluck('mobile')->all());
    }

    public function test_onboarding_manual_add_rejects_a_duplicate_in_another_spelling(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $batch = $this->openBatch($shop->id);
        $this->createCustomer($shop->id, ['mobile' => self::CANONICAL]);

        TenantContext::set($shop->id);
        $this->post(route('onboarding.customers.store', $batch), [
            'first_name' => 'Ramesh',
            'mobile'     => '+91 98123 00099',
        ])->assertSessionHasErrors('mobile');

        $this->assertSame(1, $this->rows($shop->id)->count());
    }

    // ------------------------------------------------------------- edge cases

    /**
     * Anything shorter than 10 digits cannot be an Indian mobile, so there is
     * no canonical form to convert it to. It is stored as typed rather than
     * dropped: losing the only contact detail on the bill would be worse than
     * storing a number the matcher will not recognise.
     */
    public function test_a_value_too_short_to_be_a_mobile_is_kept_as_typed(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $this->seedRetailerPricing($shop, $user);

        TenantContext::runFor($shop->id, fn () => $this->post(
            route('quick-bills.store'), $this->quickBill('  98123  ')
        ))->assertSessionHasNoErrors();

        $this->assertSame(['98123'], $this->rows($shop->id)->pluck('mobile')->all());
    }

    /** Normalising must not turn a 13-digit landline-ish blob into a match for a real mobile. */
    public function test_two_genuinely_different_numbers_stay_two_customers(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () {
            Customer::findOrCreateByMobile('A', '9812300099');
            Customer::findOrCreateByMobile('B', '9812300098');
        });

        $this->assertSame(2, $this->rows($shop->id)->count());
    }
}
