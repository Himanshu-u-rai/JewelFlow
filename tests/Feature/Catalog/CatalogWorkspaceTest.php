<?php

namespace Tests\Feature\Catalog;

use App\Models\PublicCatalogCollection;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class CatalogWorkspaceTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_catalog_keeps_filtered_pagination_and_all_matching_ids_without_foreign_or_sold_stock(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $ids = [];
        for ($i = 0; $i < 23; $i++) {
            $ids[] = $this->createItem($shop->id, null, ['category' => 'Ring', 'design' => 'Review ring '.$i, 'share_token' => 'review-'.$i])->id;
        }
        $this->createItem($shop->id, null, ['category' => 'Ring', 'design' => 'Sold private design', 'status' => 'sold']);
        [, $otherShop] = $this->createRetailerTenant();
        $this->createItem($otherShop->id, null, ['category' => 'Ring', 'design' => 'Foreign private design']);
        $this->withoutVite();
        $response = $this->actingAs($owner)->get('/catalog?category=Ring&search=Review&page=2')->assertOk();
        $response->assertDontSee('Sold private design')->assertDontSee('Foreign private design');
        $xp = $this->xpath($response->getContent());
        $this->assertSame(3, $xp->query('//article[@data-item-id]')->length);
        $this->assertCount(23, json_decode($xp->evaluate('string(//*[@id="wa-catalog-root"]/@data-all-matching-ids)'), true));
        $this->assertSame(1, $xp->query('//dialog[@id="catalog-filter-panel"]//form[@method="GET"]')->length);
        $this->assertSame(1, $xp->query('//input[@name="search"]')->length);
        $this->assertSame('Review', $xp->evaluate('string(//input[@name="search"]/@value)'));
        $this->assertGreaterThan(0, $xp->query('//footer//a[contains(@href,"category=Ring") and contains(@href,"search=Review")]')->length);
        $this->postJson(route('catalog.collections.store'), ['item_ids' => $ids, 'title' => 'All matching review items'])
            ->assertOk()->assertJsonPath('count', 23);
        $collection = TenantContext::runFor($shop->id, fn () => PublicCatalogCollection::firstOrFail());
        $this->assertCount(23, $collection->item_ids);
    }

    public function test_empty_state_and_silver_purity_use_existing_stock_truth_and_permission_gate(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['metal_type' => 'silver', 'purity' => 925, 'design' => 'Silver review']);
        $this->withoutVite();
        $this->actingAs($owner)->get('/catalog')->assertOk()->assertDontSee('925K');
        $this->get('/catalog?search=NothingMatches')->assertOk()->assertSee('No in-stock items found.')->assertSee('catalog-filter-panel');
        $this->grantOnlyPermissions($owner, ['inventory.view']);
        $owner->unsetRelation('role');
        $this->get('/catalog')->assertForbidden();
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
