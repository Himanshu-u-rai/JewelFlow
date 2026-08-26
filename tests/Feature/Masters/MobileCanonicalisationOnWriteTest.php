<?php

namespace Tests\Feature\Masters;

use App\Models\Customer;
use App\Models\Karigar;
use App\Models\Platform\PlatformAdmin;
use App\Models\Shop;
use App\Models\User;
use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The write boundary, not the call sites.
 *
 * Canonicalisation used to live at the call sites, and the call sites are why
 * it kept failing: one fix canonicalised Quick Bill, CSV import and the
 * onboarding ADD form, and walked straight past the onboarding EDIT form eighty
 * lines below it. There is no way to keep a list of ~20 writers in sync.
 *
 * CanonicalisesMobileNumbers moves it into setAttribute, which every writer goes
 * through — create, fill, update, forceFill, direct assignment, seeders,
 * imports, and whatever gets added next. This test asserts the boundary holds
 * for each of those mechanisms and for every model wired to the trait, so a
 * future model that forgets `$mobileColumns` fails here rather than in a shop's
 * customer directory.
 */
class MobileCanonicalisationOnWriteTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const AS_TYPED  = '+91 98123 00099';
    private const CANONICAL = '9812300099';

    // ------------------------------------------------ every write mechanism

    public function test_create_canonicalises(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $customer = TenantContext::runFor($shop->id, fn () => Customer::create([
            'first_name' => 'Ramesh',
            'mobile'     => self::AS_TYPED,
        ]));

        $this->assertSame(self::CANONICAL, $customer->fresh()->mobile);
    }

    public function test_update_canonicalises(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id, ['mobile' => '9800000000']);

        TenantContext::runFor($shop->id, fn () => $customer->update(['mobile' => self::AS_TYPED]));

        $this->assertSame(self::CANONICAL, $customer->fresh()->mobile);
    }

    public function test_direct_assignment_canonicalises(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id, ['mobile' => '9800000000']);

        TenantContext::runFor($shop->id, function () use ($customer) {
            $customer->mobile = self::AS_TYPED;
            $customer->save();
        });

        $this->assertSame(self::CANONICAL, $customer->fresh()->mobile);
    }

    /**
     * forceFill is the mass-assignment escape hatch, used by the archive and
     * reactivate endpoints. Escaping the fillable list must not also escape
     * canonicalisation — they are unrelated guarantees.
     */
    public function test_force_fill_canonicalises(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id, ['mobile' => '9800000000']);

        TenantContext::runFor($shop->id, function () use ($customer) {
            $customer->forceFill(['mobile' => self::AS_TYPED])->save();
        });

        $this->assertSame(self::CANONICAL, $customer->fresh()->mobile);
    }

    // -------------------------------------------------------- what it leaves alone

    /**
     * Hydration must NOT pass through the mutator. Eloquent fills a loaded model
     * with setRawAttributes, so a row written before canonicalisation keeps the
     * spelling the shop typed — reading a record is not permission to rewrite it.
     */
    public function test_reading_a_legacy_row_does_not_rewrite_it(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id, ['mobile' => '9800000000']);
        DB::table('customers')->where('id', $customer->id)->update(['mobile' => self::AS_TYPED]);

        $loaded = TenantContext::runFor($shop->id, fn () => Customer::find($customer->id));
        $loaded->save();

        $this->assertSame(self::AS_TYPED, $customer->fresh()->mobile,
            'hydration ran through the mutator and quietly rewrote stored data');
    }

    /**
     * A value that is not a mobile number is stored exactly as given, not
     * nulled. Rejecting it is the validator's job; silently discarding input
     * here would turn a validation problem into a data-loss problem, and the
     * only paths that reach this without a validator (CSV import) skip the row
     * themselves.
     */
    public function test_a_non_mobile_value_is_left_as_given(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $customer = TenantContext::runFor($shop->id, fn () => Customer::create([
            'first_name' => 'Scrap',
            'mobile'     => '98123',
        ]));

        $this->assertSame('98123', $customer->fresh()->mobile);
    }

    /** Only the declared columns are touched — a name is not a phone number. */
    public function test_other_columns_are_untouched(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $customer = TenantContext::runFor($shop->id, fn () => Customer::create([
            'first_name' => '+91 98123 00099',
            'mobile'     => self::CANONICAL,
        ]));

        $this->assertSame('+91 98123 00099', $customer->fresh()->first_name);
    }

    // ------------------------------------------------------------ every model

    public function test_vendor_mobile_is_canonicalised(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $vendor = TenantContext::runFor($shop->id, fn () => Vendor::create([
            'name'   => 'Supplier A',
            'mobile' => self::AS_TYPED,
        ]));

        $this->assertSame(self::CANONICAL, $vendor->fresh()->mobile);
    }

    public function test_karigar_mobile_is_canonicalised(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $karigar = TenantContext::runFor($shop->id, fn () => Karigar::create([
            'name'   => 'Karigar A',
            'mobile' => self::AS_TYPED,
        ]));

        $this->assertSame(self::CANONICAL, $karigar->fresh()->mobile);
    }

    public function test_user_mobile_number_is_canonicalised(): void
    {
        [$user] = $this->createRetailerTenant();

        $user->forceFill(['mobile_number' => self::AS_TYPED])->save();

        $this->assertSame(self::CANONICAL, User::find($user->id)->mobile_number);
    }

    public function test_platform_admin_mobile_number_is_canonicalised(): void
    {
        $admin = $this->createPlatformAdmin();

        $admin->forceFill(['mobile_number' => self::AS_TYPED])->save();

        $this->assertSame(self::CANONICAL, PlatformAdmin::find($admin->id)->mobile_number);
    }

    /** Shop declares three of them, and all three feed tel:/WhatsApp links. */
    public function test_all_three_shop_columns_are_canonicalised(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $shop->forceFill([
            'phone'         => self::AS_TYPED,
            'owner_mobile'  => '098123-00099',
            'shop_whatsapp' => '919812300099',
        ])->save();

        $fresh = Shop::find($shop->id);
        $this->assertSame(self::CANONICAL, $fresh->phone);
        $this->assertSame(self::CANONICAL, $fresh->owner_mobile);
        $this->assertSame(self::CANONICAL, $fresh->shop_whatsapp);
    }

    // -------------------------------------------------- the other half: input

    /**
     * The mutator alone is not enough, and this is the wiring that says so.
     *
     * Validation runs before it and against the raw string, so `Rule::unique`
     * compares '+91 98123 00099' to a stored '9812300099', finds nothing, and
     * lets the insert reach the unique index — a 500 instead of a form error.
     * CanonicaliseMobileInput closes that by rewriting input before validation.
     *
     * Registration is asserted rather than the behaviour because the behaviour is
     * already covered per-endpoint; what cannot be seen from a controller is the
     * group wiring, and dropping either line here would silently reopen the hole
     * on that entire surface. The mobile API carries the same uniqueness rules as
     * the web forms, so it needs the same middleware.
     */
    public function test_the_input_canonicaliser_is_wired_into_both_route_groups(): void
    {
        $groups = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups();

        foreach (['web', 'api'] as $group) {
            $this->assertContains(\App\Http\Middleware\CanonicaliseMobileInput::class,
                $groups[$group], "the {$group} group no longer canonicalises mobile input");
        }
    }
}
