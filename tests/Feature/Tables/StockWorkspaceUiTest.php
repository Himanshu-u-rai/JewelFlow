<?php

namespace Tests\Feature\Tables;

use App\Models\Category;
use App\Models\ReorderRule;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class StockWorkspaceUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public static function sections(): array
    {
        return [['categories', 'categories.index'], ['tags', 'tags.index'], ['reorder', 'reorder.index']];
    }

    #[DataProvider('sections')]
    public function test_stock_tools_share_header_and_responsive_navigation_without_replacing_their_handlers(string $section, string $route): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => ReorderRule::create(['category' => 'Synthetic shortage', 'min_stock_threshold' => 2]));
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get(route($route))->assertOk()->getContent());
        $this->assertSame('Stock / Items', trim($xp->evaluate('string(//*[contains(@class,"inventory-items-header")]//h1)')));
        foreach (['header', 'body'] as $placement) {
            $nav = $xp->query('//nav[contains(@class,"items-'.$placement.'-nav")]')->item(0);
            $this->assertNotNull($nav);
            $this->assertSame(6, $xp->query('.//a[@data-stock-section]', $nav)->length);
            $this->assertSame(0, $xp->query('ancestor::form', $nav)->length);
            $this->assertSame(1, $xp->query('.//a[@aria-current="page"]', $nav)->length);
            $this->assertSame($section, $xp->evaluate('string(.//a[@aria-current="page"]/@data-stock-section)', $nav));
            foreach (['items', 'aging', 'sellers'] as $view) {
                $url = route('inventory.items.index', $view === 'items' ? [] : ['view' => $view]);
                $this->assertSame($url, $xp->evaluate('string(.//a[@data-stock-section="'.$view.'"]/@href)', $nav));
            }
            foreach (self::sections() as [$key, $name]) {
                $this->assertSame(route($name), $xp->evaluate('string(.//a[@data-stock-section="'.$key.'"]/@href)', $nav));
            }
            $this->assertSame('1', trim($xp->evaluate('string(.//a[@data-stock-section="reorder"]//span[contains(@class,"items-page-link-count")])', $nav)));
        }
        if ($section === 'categories') {
            $this->assertSame(1, $xp->query('//form[@action="'.route('categories.store').'" and @method="POST"]')->length);
        } elseif ($section === 'tags') {
            $this->assertSame(1, $xp->query('//form[@action="'.route('tags.print').'"]')->length);
        } else {
            $this->assertSame(1, $xp->query('//a[@href="'.route('reorder.create').'"]')->length);
        }
    }

    public function test_manufacturer_categories_keep_only_supported_stock_destinations(): void
    {
        [$owner] = $this->createManufacturerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get(route('categories.index'))->assertOk()->getContent());
        foreach (['header', 'body'] as $placement) {
            $nav = $xp->query('//nav[contains(@class,"items-'.$placement.'-nav")]')->item(0);
            $this->assertNotNull($nav);
            $this->assertSame(2, $xp->query('.//a[@data-stock-section]', $nav)->length);
            $this->assertSame('categories', $xp->evaluate('string(.//a[@aria-current="page"]/@data-stock-section)', $nav));
        }
        $this->get(route('tags.index'))->assertForbidden();
        $this->get(route('reorder.index'))->assertForbidden();
    }

    public function test_restricted_staff_keep_read_access_without_gaining_mutation_permissions(): void
    {
        [$staff] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($staff, ['inventory.view']);
        $this->withoutVite();
        foreach (self::sections() as [, $route]) {
            $this->actingAs($staff)->get(route($route))->assertOk()->assertSee('Stock navigation');
        }
        $this->get(route('categories.index'))->assertDontSee('onclick="openAddCategoryModal()"', false);
        $this->post(route('categories.store'), ['name' => 'Forbidden synthetic'])->assertForbidden();
        $this->post(route('reorder.store'), ['min_stock_threshold' => 2])->assertForbidden();
    }

    public function test_category_search_and_pagination_keep_stock_context_and_current_query(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () {
            for ($i = 1; $i <= 22; $i++) {
                Category::create(['name' => sprintf('Synthetic category %02d', $i)]);
            }
        });
        $this->withoutVite();
        $response = $this->actingAs($owner)->get(route('categories.index', ['q' => 'Synthetic', 'page' => 2]))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame('Synthetic', $xp->evaluate('string(//input[@name="q"]/@value)'));
        $this->assertGreaterThan(0, $xp->query('//a[contains(@href,"q=Synthetic") and contains(@href,"page=1")]')->length);
        $this->assertSame(2, $xp->query('//a[@data-stock-section="categories" and @aria-current="page"]')->length);
        $response->assertSee('Synthetic category 21')->assertSee('Synthetic category 22');
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
