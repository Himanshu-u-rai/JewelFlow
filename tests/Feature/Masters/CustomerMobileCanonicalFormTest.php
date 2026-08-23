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

    // ------------------------------------------------- Onboarding: manual EDIT

    /**
     * The fourth free-text path, and the one the original fix walked past.
     *
     * `mobile` is `max:20` here exactly as it is on the add form eighty lines
     * up, and `mobile` IS fillable — so `$customer->update($data)` writes
     * whatever was typed. Canonicalising only the add form means the edit form
     * can put a spelling straight back into the column the add form had just
     * cleaned, and every claim the rest of this file makes stops being true the
     * first time someone corrects a typo.
     */
    public function test_onboarding_edit_stores_the_canonical_mobile(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $batch    = $this->openBatch($shop->id);
        $customer = $this->createCustomer($shop->id, ['mobile' => '9800000000']);

        TenantContext::set($shop->id);
        $this->put(route('onboarding.customers.update', [$batch, $customer]), [
            'first_name' => 'Ramesh',
            'mobile'     => '+91 98123 00099',
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::CANONICAL, $customer->fresh()->mobile);
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

    // ------------------------------------------------- Legacy rows (no backfill)

    /**
     * A row written before canonicalisation, stored the way it was typed. This
     * is what every existing tenant's table is full of, and no migration is
     * going to rewrite it — so the lookup has to cope.
     */
    private function legacyRow(int $shopId, string $asTyped): Customer
    {
        // Created inside the tenant, not via withoutTenant()->create([...]):
        // `shop_id` is not fillable, so mass assignment drops it and the row
        // reaches the customers_business_identifier_assign trigger with a null
        // shop — which is a not-null violation, not a test.
        //
        // Nothing normalises on insert, so `mobile` lands exactly as typed.
        // That is the point: this is a row from before canonicalisation.
        return TenantContext::runFor($shopId, fn () => Customer::create([
            'first_name' => 'Legacy',
            'mobile'     => $asTyped,
        ]));
    }

    public function test_a_legacy_spelling_is_found_without_a_backfill(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $legacy = $this->legacyRow($shop->id, '+91 98123 00099');

        $found = TenantContext::runFor($shop->id,
            fn () => Customer::resolveByMobile(self::CANONICAL));

        $this->assertNotNull($found, 'canonical lookup could not see the pre-canonicalisation row');
        $this->assertSame($legacy->id, $found->id, 'matched some other customer entirely');
    }

    /**
     * Matching a legacy row must not "helpfully" tidy it. We do not rewrite
     * customer data — not in a migration, and not lazily on read either, which
     * is the same rewrite wearing a smaller hat.
     */
    public function test_finding_a_legacy_row_never_rewrites_it(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $legacy = $this->legacyRow($shop->id, '98123-00099');
        $before = $legacy->fresh()->only(['mobile', 'updated_at']);

        TenantContext::runFor($shop->id, fn () => Customer::resolveByMobile(self::CANONICAL));

        $this->assertSame('98123-00099', $legacy->fresh()->mobile,
            'the lookup rewrote a stored mobile; reads must not mutate customer data');
        $this->assertEquals($before, $legacy->fresh()->only(['mobile', 'updated_at']),
            'the row was touched even if the value looks unchanged');
        $this->assertSame(1, $this->rows($shop->id)->count());
    }

    /** Repeat lookups must keep working — the scan is permanent, so it must be stable. */
    public function test_a_legacy_row_is_still_found_on_the_second_lookup(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $legacy = $this->legacyRow($shop->id, '+91 98123 00099');

        [$first, $second] = TenantContext::runFor($shop->id, fn () => [
            Customer::resolveByMobile(self::CANONICAL),
            Customer::resolveByMobile(self::CANONICAL),
        ]);

        $this->assertSame($legacy->id, $first?->id);
        $this->assertSame($legacy->id, $second?->id, 'the fallback only worked once');
    }

    public function test_billing_a_legacy_customer_reuses_the_row_instead_of_duplicating(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->actingAs($user);
        $this->seedRetailerPricing($shop, $user);
        $legacy = $this->legacyRow($shop->id, '+91-98123-00099');

        TenantContext::runFor($shop->id, fn () => $this->post(
            route('quick-bills.store'), $this->quickBill(self::CANONICAL)
        ))->assertSessionHasNoErrors();

        $this->assertSame(1, $this->rows($shop->id)->count(),
            'the legacy row was invisible to Quick Bill, so the same human now has two records');
        $this->assertSame($legacy->id, $this->rows($shop->id)->first()->id);
    }

    /**
     * Canonicalising the value we are about to INSERT while looking it up
     * EXACTLY is the worst of both: the needle is clean, the haystack is not,
     * so a legacy row can never be found and the import silently adds a second
     * record for a customer the shop already has.
     */
    public function test_onboarding_csv_import_does_not_duplicate_a_legacy_row(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $batch  = $this->openBatch($shop->id);
        $legacy = $this->legacyRow($shop->id, '+91 98123 00099');

        $csv = "first_name,last_name,mobile,email,address\nRamesh,Kumar," . self::CANONICAL . ",,\n";

        TenantContext::set($shop->id);
        $this->post(route('onboarding.customers.import', $batch), [
            'file' => UploadedFile::fake()->createWithContent('customers.csv', $csv),
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->rows($shop->id)->count(),
            'the import could not see the pre-canonicalisation row and duplicated the customer');
        $this->assertSame('+91 98123 00099', $legacy->fresh()->mobile, 'the import rewrote a stored mobile');
    }

    /** Same blind spot, reached through the manual add form instead of a file. */
    public function test_onboarding_manual_add_rejects_a_legacy_duplicate(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $batch = $this->openBatch($shop->id);
        $this->legacyRow($shop->id, '098123-00099');

        TenantContext::set($shop->id);
        $this->post(route('onboarding.customers.store', $batch), [
            'first_name' => 'Ramesh',
            'mobile'     => self::CANONICAL,
        ])->assertSessionHasErrors('mobile');

        $this->assertSame(1, $this->rows($shop->id)->count());
    }

    /** The clash check on edit must see legacy rows too — but not the row being edited. */
    public function test_onboarding_edit_sees_a_legacy_clash_but_not_itself(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $this->actingAs($user);
        $batch  = $this->openBatch($shop->id);
        $legacy = $this->legacyRow($shop->id, '+91 98123 00099');
        $other  = $this->createCustomer($shop->id, ['mobile' => '9800000000']);

        TenantContext::set($shop->id);
        $this->put(route('onboarding.customers.update', [$batch, $other]), [
            'first_name' => 'Ramesh',
            'mobile'     => self::CANONICAL,
        ])->assertSessionHasErrors('mobile');

        // Re-canonicalising a legacy row's own number must not make it collide
        // with itself, or the row can never be edited again.
        TenantContext::set($shop->id);
        $this->put(route('onboarding.customers.update', [$batch, $legacy]), [
            'first_name' => 'Ramesh',
            'mobile'     => self::CANONICAL,
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::CANONICAL, $legacy->fresh()->mobile);
    }

    /** The fallback must not match loosely across two different people. */
    public function test_the_legacy_fallback_does_not_match_a_different_number(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->legacyRow($shop->id, '+91 98123 00098');

        $found = TenantContext::runFor($shop->id,
            fn () => Customer::resolveByMobile(self::CANONICAL));

        $this->assertNull($found, 'the scan matched a customer who is not this customer');
    }

    /**
     * An unparseable value has no canonical form, so there is nothing to scan
     * for — it must stay an exact-match lookup rather than fuzzily grabbing
     * whichever short string happens to sit nearby.
     */
    public function test_a_value_too_short_to_normalise_only_matches_itself(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $short = $this->legacyRow($shop->id, '98123');

        [$exact, $other] = TenantContext::runFor($shop->id, fn () => [
            Customer::resolveByMobile('98123'),
            Customer::resolveByMobile('98124'),
        ]);

        $this->assertSame($short->id, $exact?->id);
        $this->assertNull($other);
        $this->assertSame('98123', $short->fresh()->mobile, 'an unparseable value must not be rewritten');
    }
}
