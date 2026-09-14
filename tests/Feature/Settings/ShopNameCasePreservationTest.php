<?php

namespace Tests\Feature\Settings;

use App\Models\Shop;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The end-to-end proof for the shop name, over real HTTP.
 *
 * NormalizeHumanTextInputTest pins the middleware in isolation; this pins the
 * whole round trip the operator actually performs — PATCH the Shop Details
 * form, then reopen Settings and read the field back. A name has to survive
 * three layers to pass: the input middleware, `updateShop()`'s validation and
 * write, and the Blade that renders the value back into the input.
 *
 * Both halves of the defect are covered. `MB_CASE_TITLE` damaged the mixed-case
 * spellings ("JewelFlows" -> "Jewelflows"); the first attempt at a fix kept
 * title-casing anything with no uppercase letter, so "abc jewellers" was still
 * rewritten. Neither may happen now: the stored value is the typed value.
 */
class ShopNameCasePreservationTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, array{string}> */
    public static function shopNames(): array
    {
        return [
            'internal caps'      => ['JewelFlows'],
            'leading acronym'    => ['RK Jewellers'],
            'acronym alone'      => ['ABC'],
            'all lowercase'      => ['abc jewellers'],
            'lowercase brand'    => ['iGold Retail'],
        ];
    }

    #[DataProvider('shopNames')]
    public function test_a_shop_name_survives_saving_and_reading_back(string $name): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($owner, $shop, $name): void {
            $this->actingAs($owner)
                ->patch(route('settings.update.shop'), $this->shopDetailsPayload($name))
                ->assertSessionHasNoErrors();

            // Stored verbatim.
            $this->assertSame($name, Shop::withoutGlobalScopes()->find($shop->id)->name,
                "the shop was renamed to something the operator did not type");

            // And rendered back verbatim on the form they would reopen.
            $this->actingAs($owner)
                ->get(route('settings.edit'))
                ->assertOk()
                ->assertSee($name, escape: true);
        });
    }

    /**
     * Whitespace is the one thing that is still tidied — it is a typo, not a
     * spelling choice. The case inside the name is untouched while it happens.
     */
    public function test_surrounding_and_doubled_whitespace_is_still_cleaned_up(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($owner, $shop): void {
            $this->actingAs($owner)
                ->patch(route('settings.update.shop'), $this->shopDetailsPayload('  RK   Jewellers  '))
                ->assertSessionHasNoErrors();

            $this->assertSame('RK Jewellers', Shop::withoutGlobalScopes()->find($shop->id)->name);
        });
    }

    /** @return array<string, mixed> */
    private function shopDetailsPayload(string $name): array
    {
        return [
            'name'             => $name,
            'phone'            => '9824400111',
            'address_line1'    => '12 Zaveri Bazaar',
            'city'             => 'Mumbai',
            'state'            => 'Maharashtra',
            'pincode'          => '400003',
            'owner_first_name' => 'Owner',
            'owner_last_name'  => 'Test',
            'owner_mobile'     => '9824400112',
        ];
    }
}
