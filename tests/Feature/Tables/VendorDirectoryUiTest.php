<?php

namespace Tests\Feature\Tables;

use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class VendorDirectoryUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_header_metrics_and_single_filter_form_are_outside_scrolling_rows(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->vendor($shop->id, 'Active supplier');
        $this->vendor($shop->id, 'Archived supplier', false, '24ABCDE1234F1Z5');
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/vendors?search=Active&status=active')->assertOk()->getContent());
        $this->assertSame(1, $xp->query('//*[@data-vendor-register-head]//dl[@aria-label="Vendor summary"]')->length);
        $this->assertSame('2', $xp->evaluate('string(//dl[@aria-label="Vendor summary"]/div[dt="Total Vendors"]/dd)'));
        $this->assertSame('1', $xp->evaluate('string(//dl[@aria-label="Vendor summary"]/div[dt="Active"]/dd)'));
        $this->assertSame('1', $xp->evaluate('string(//dl[@aria-label="Vendor summary"]/div[dt="GST Registered"]/dd)'));
        $this->assertSame(1, $xp->query('//dialog[@id="vendors-filter-panel"]//form[@method="GET"]')->length);
        $this->assertSame(1, $xp->query('//form//input[@name="search"]')->length);
        $this->assertSame('Active', $xp->evaluate('string(//input[@name="search"]/@value)'));
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//dl | //*[@data-list-scroll]//form[@method="GET"] | //*[@data-list-scroll]//nav')->length);
        $this->assertSame(6, $xp->query('//table/thead/tr/th[@scope="col"]')->length);
        $this->assertSame(0, $xp->query('//*[contains(@class,"vendors-stats-grid")]')->length);
    }

    public function test_filtered_pagination_keeps_fifteen_rows_and_continuous_mobile_and_desktop_numbers(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        for ($i = 1; $i <= 31; $i++) {
            $this->vendor($shop->id, sprintf('Review supplier %02d', $i));
        }
        $this->vendor($shop->id, 'Review archived', false);
        [, $otherShop] = $this->createRetailerTenant();
        $this->vendor($otherShop->id, 'Review private supplier');
        $this->withoutVite();
        $response = $this->actingAs($owner)->get('/vendors?search=Review&status=active&page=2')->assertOk();
        $response->assertDontSee('Review archived')->assertDontSee('Review private supplier');
        $xp = $this->xpath($response->getContent());
        $numbers = fn ($query) => array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query($query)));
        $this->assertSame(array_map('strval', range(16, 30)), $numbers('//table//td[@class="vendors-index-cell"]'));
        $this->assertSame(array_map(fn ($i) => '#'.$i, range(16, 30)), $numbers('//span[@class="vendor-mobile-index"]'));
        $this->assertSame(15, $xp->query('//table//a[@data-tip="View"]')->length);
        $this->assertGreaterThan(0, $xp->query('//footer//a[contains(@href,"search=Review") and contains(@href,"status=active") and contains(@href,"page=3")]')->length);
        $this->assertSame('0', $xp->evaluate('string(//*[@data-list-scroll]/@tabindex)'));
    }

    public function test_filtered_empty_state_keeps_filters_and_does_not_claim_the_directory_is_empty(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->vendor($shop->id, 'Existing supplier');
        $this->withoutVite();
        $response = $this->actingAs($owner)->get('/vendors?search=Nobody')->assertOk();
        $response->assertSee('No vendors match these filters.')->assertDontSee('Add your first vendor');
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//dialog[@id="vendors-filter-panel"]')->length);
        $this->assertSame(1, $xp->query('//footer[contains(@class,"vendors-pagination")]')->length);
    }

    public function test_view_only_role_retains_view_without_edit_archive_or_add_actions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->vendor($shop->id, 'Read-only supplier');
        $this->grantOnlyPermissions($owner, ['vendors.view']);
        $owner->unsetRelation('role');
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/vendors')->assertOk()->getContent());
        $this->assertSame(2, $xp->query('//a[@data-tip="View"]')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-workspace]//form[@method="POST"]')->length);
        $this->assertSame(0, $xp->query('//a[contains(@href,"/vendors/create") or contains(@href,"/edit")]')->length);
    }

    private function vendor(int $shopId, string $name, bool $active = true, ?string $gst = null): Vendor
    {
        return TenantContext::runFor($shopId, fn () => Vendor::forceCreate([
            'shop_id' => $shopId, 'name' => $name, 'is_active' => $active, 'gst_number' => $gst,
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
