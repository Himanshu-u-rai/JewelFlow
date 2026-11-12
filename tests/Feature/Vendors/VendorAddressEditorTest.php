<?php

namespace Tests\Feature\Vendors;

use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** UI audit F15: the vendor editor reuses the shared PIN-assisted <x-indian-address> group. */
class VendorAddressEditorTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_editor_renders_the_shared_address_component_with_labelled_fields(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        $vendor = $this->vendor($shop->id, 'Legacy Gold', 'Old Bazaar', 'Greater Somewhere');

        foreach ([route('vendors.create'), route('vendors.edit', $vendor)] as $url) {
            $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get($url, ['Turbo-Frame' => 'vendor-editor-frame']))->assertOk()->getContent();
            $xp = $this->xpath($html);

            $this->assertSame(1, $xp->query('//turbo-frame[@id="vendor-editor-frame"]//div[contains(@class,"jf-address-fields")][@x-data="indianAddress"]')->length);
            $this->assertSame(1, $xp->query('//*[@data-lookup-url]')->length);
            foreach (['pin' => 'pincode', 'city' => 'city', 'state' => 'state'] as $suffix => $name) {
                $this->assertSame(1, $xp->query("//input[@id=\"vendor-{$suffix}\"][@name=\"{$name}\"]")->length, "vendor-{$suffix} input");
                $this->assertGreaterThanOrEqual(1, $xp->query("//label[@for=\"vendor-{$suffix}\"]")->length, "label for vendor-{$suffix}");
            }
            // One input per name: no leftover plain city/state fields.
            $this->assertSame(1, $xp->query('//input[@name="city"]')->length);
            $this->assertSame(1, $xp->query('//input[@name="state"]')->length);
            $this->assertSame(1, $xp->query('//input[@id="vendor-state"][@role="combobox"]')->length);
        }
    }

    public function test_existing_free_text_city_and_state_are_prefilled_even_when_not_in_the_state_list(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        $vendor = $this->vendor($shop->id, 'Legacy Gold', 'Old Bazaar', 'Greater Somewhere');

        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('vendors.edit', $vendor), ['Turbo-Frame' => 'vendor-editor-frame']))->assertOk()->getContent();
        $xp = $this->xpath($html);

        $this->assertSame('Old Bazaar', $xp->evaluate('string(//input[@name="city"]/@value)'));
        $this->assertSame('Greater Somewhere', $xp->evaluate('string(//input[@name="state"]/@value)'));
        // The unknown state is not a listed option; the free-text input keeps it regardless.
        $this->assertSame(0, $xp->query('//datalist[@id="vendor-states"]/option[@value="Greater Somewhere"]')->length);
        $this->assertSame(1, $xp->query('//datalist[@id="vendor-states"]/option[@value="Gujarat"]')->length);
    }

    public function test_saving_with_city_state_and_pincode_stores_city_and_state_unchanged(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();

        $this->actingAs($owner)->post(route('vendors.store'), [
            '_vendor_editor' => '1', 'name' => 'New Supplier', 'city' => 'Surat', 'state' => 'Gujarat', 'pincode' => '395003',
        ])->assertRedirect(route('vendors.index'))->assertSessionHasNoErrors();

        $vendor = TenantContext::runFor($shop->id, fn () => Vendor::where('name', 'New Supplier')->firstOrFail());
        $this->assertSame('Surat', $vendor->city);
        $this->assertSame('Gujarat', $vendor->state);

        // Untouched free-text values round-trip through an edit save unchanged.
        $legacy = $this->vendor($shop->id, 'Legacy Gold', 'Old Bazaar', 'Greater Somewhere');
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->put(route('vendors.update', $legacy), [
            '_vendor_editor' => '1', 'name' => 'Legacy Gold', 'city' => 'Old Bazaar', 'state' => 'Greater Somewhere', 'pincode' => '',
        ]))->assertRedirect(route('vendors.index'))->assertSessionHasNoErrors();

        $legacy = TenantContext::runFor($shop->id, fn () => $legacy->fresh());
        $this->assertSame('Old Bazaar', $legacy->city);
        $this->assertSame('Greater Somewhere', $legacy->state);
    }

    private function vendor(int $shopId, string $name, string $city, string $state): Vendor
    {
        return TenantContext::runFor($shopId, fn () => Vendor::forceCreate([
            'shop_id' => $shopId, 'name' => $name, 'city' => $city, 'state' => $state, 'is_active' => true,
        ]));
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }
}
