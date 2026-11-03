<?php

namespace Tests\Feature\Tables;

use App\Models\Category;
use App\Models\ReorderRule;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class StockRegisterUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public static function editions(): array
    {
        return [['retailer', 8], ['manufacturer', 9]];
    }

    #[DataProvider('editions')]
    public function test_stock_filters_are_inside_header_with_full_search_and_edition_specific_purity(string $edition, int $columns): void
    {
        [$owner, $shop] = $edition === 'retailer' ? $this->createRetailerTenant() : $this->createManufacturerTenant();
        $category = TenantContext::runFor($shop->id, fn () => Category::create(['name' => 'Synthetic Rings']));
        $this->withoutVite();
        $params = ['status' => 'sold', 'category' => $category->id, 'sub_category' => 'Synthetic bands', 'purity' => '22', 'search' => 'Synthetic', 'page' => 2];
        $response = $this->actingAs($owner)->get('/inventory/items?'.http_build_query($params))->assertOk();
        $response->assertDontSee('Inventory Lens')->assertDontSee('Showing sold inventory')
            ->assertDontSee('Manage jewellery items ready for sale');
        $xp = $this->xpath($response->getContent());
        $header = $xp->query('//*[contains(concat(" ",@class," ")," items-register-header ")]')->item(0);
        $this->assertNotNull($header);
        $form = $xp->query('.//form[@data-stock-filters]', $header)->item(0);
        $this->assertNotNull($form);
        $this->assertSame('GET', $form->getAttribute('method'));
        $this->assertSame(route('inventory.items.index'), $form->getAttribute('action'));
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//form[@data-stock-filters]')->length);
        foreach (['status' => 'sold', 'sub_category' => 'Synthetic bands', 'search' => 'Synthetic'] as $name => $value) {
            $this->assertSame($value, $xp->evaluate('string(.//input[@name="'.$name.'"]/@value)', $form));
        }
        $this->assertSame((string) $category->id, $xp->evaluate('string(.//select[@name="category"]/option[@selected]/@value)', $form));
        $this->assertSame(1, $xp->query('.//button[@type="submit" and @x-show="dirty || !hasFilters"]', $form)->length, 'Editing an applied filter must still allow submission.');
        $this->assertStringContainsString('hasFilters: true', $form->getAttribute('x-data'));
        $this->assertSame(route('inventory.items.index'), $xp->evaluate('string(.//a[@data-stock-filter-clear]/@href)', $form));
        $search = $xp->query('.//input[@name="search"]', $form)->item(0);
        $this->assertSame('items', $search->getAttribute('data-suggest'));
        $this->assertSame(1, $xp->query('.//label[@for="'.$search->getAttribute('id').'"]', $form)->length);
        $this->assertSame(0, $xp->query('ancestor::*[@x-show and not(@data-list-panel)]', $search)->length, 'Search is not a collapsed disclosure.');
        if ($edition === 'retailer') {
            $this->assertSame('22', $xp->evaluate('string(.//input[@name="purity"]/@value)', $form));
        } else {
            $this->assertSame('22', $xp->evaluate('string(.//select[@name="purity"]/option[@selected]/@value)', $form));
        }
        $toggle = $xp->query('.//nav[@aria-label="Stock status"]', $header)->item(0);
        $this->assertNotNull($toggle);
        $this->assertSame(1, $xp->query('.//a', $toggle)->length);
        foreach ($xp->query('.//a', $toggle) as $link) {
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            foreach (['category', 'sub_category', 'purity', 'search'] as $name) {
                $this->assertSame((string) $params[$name], $query[$name]);
            }
            $this->assertArrayNotHasKey('page', $query);
        }
        $this->assertSame(2, $xp->query('.//a/span', $toggle)->length);
        $this->assertSame('Sold', trim($xp->evaluate('string(.//span[contains(@class,"is-active")])', $toggle)));
        $this->assertSame('Stock status: Sold. Switch to In Stock', $xp->evaluate('string(.//a/@aria-label)', $toggle));
        $this->assertSame($columns, $xp->query('//*[@data-list-scroll]//table/thead/tr/th')->length);
        $this->assertSame((string) $columns, $xp->evaluate('string(//*[@data-list-scroll]//td[@colspan]/@colspan)'));
    }

    public function test_clicking_anywhere_in_stock_toggle_navigates_to_opposite_status_and_back(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['design' => 'Synthetic available toggle item', 'status' => 'in_stock']);
        $this->createItem($shop->id, null, ['design' => 'Synthetic sold toggle item', 'status' => 'sold']);
        $this->withoutVite();
        $url = route('inventory.items.index', ['search' => 'Synthetic', 'status' => 'in_stock']);
        foreach (['in_stock' => 'sold', 'sold' => 'in_stock'] as $current => $next) {
            $response = $this->actingAs($owner)->get($url)->assertOk();
            $xp = $this->xpath($response->getContent());
            $link = $xp->query('//nav[@aria-label="Stock status"]/a')->item(0);
            $this->assertNotNull($link);
            $this->assertSame(0, $xp->query('.//a | .//button | .//input', $link)->length, 'No separate label intercepts a click on the whole link.');
            $this->assertSame(2, $xp->query('./span[@aria-hidden="true"]', $link)->length);
            $this->assertSame($current === 'sold' ? 'Sold' : 'In Stock', trim($xp->evaluate('string(.//span[contains(@class,"is-active")])', $link)));
            parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame($next, $query['status']);
            $this->assertSame('Synthetic', $query['search']);
            $url = $link->getAttribute('href');
            $rows = $xp->evaluate('string(//*[@data-list-scroll]//tbody)');
            $this->assertStringContainsString($current === 'sold' ? 'Synthetic sold toggle item' : 'Synthetic available toggle item', $rows);
            $this->assertStringNotContainsString($current === 'sold' ? 'Synthetic available toggle item' : 'Synthetic sold toggle item', $rows);
        }
        $this->actingAs($owner)->get($url)->assertOk()->assertSee('Synthetic available toggle item')->assertDontSee('Synthetic sold toggle item');
    }

    public function test_default_stock_status_and_empty_fields_do_not_count_as_applied_filters(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        foreach ([[], ['status' => 'in_stock'], ['status' => 'in_stock', 'category' => '', 'purity' => '', 'search' => '']] as $params) {
            $html = $this->actingAs($owner)->get(route('inventory.items.index', $params))->assertOk()->getContent();
            $xp = $this->xpath($html);
            $this->assertStringContainsString('hasFilters: false', $xp->evaluate('string(//form[@data-stock-filters]/@x-data)'));
            $this->assertSame(0, $xp->query('//form[@data-stock-filters]//noscript')->length);
        }
    }

    public function test_populated_page_two_has_continuous_numbers_external_pagination_and_restricted_actions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $category = TenantContext::runFor($shop->id, fn () => Category::create(['name' => 'Synthetic Rings']));
        for ($i = 1; $i <= 22; $i++) {
            $this->createItem($shop->id, null, ['category' => $category->name, 'design' => 'Synthetic stock '.$i, 'sub_category' => 'Synthetic bands']);
        }
        $this->createItem($shop->id, null, ['category' => $category->name, 'design' => 'Synthetic sold', 'sub_category' => 'Synthetic bands', 'status' => 'sold']);
        $this->grantOnlyPermissions($owner, ['inventory.view']);
        $this->withoutVite();
        $url = '/inventory/items?'.http_build_query(['category' => $category->id, 'sub_category' => 'Synthetic bands', 'purity' => '22', 'search' => 'Synthetic', 'status' => 'in_stock', 'page' => 2]);
        $xp = $this->xpath($this->actingAs($owner)->get($url)->assertOk()->getContent());
        $numbers = [];
        foreach ($xp->query('//*[@data-list-scroll]//tbody/tr/td[@class="jf-table-index"]') as $cell) {
            $numbers[] = (int) trim($cell->textContent);
        }
        $this->assertSame([21, 22], $numbers);
        $this->assertSame(2, $xp->query('//*[@aria-label="Stock items mobile list"]//span[@class="jf-record-index"]')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//nav')->length);
        $this->assertGreaterThan(0, $xp->query('//*[@data-list-frame]//nav//a[contains(@href,"page=1") and contains(@href,"search=Synthetic")]')->length);
        $this->assertSame(0, $xp->query('//a[contains(@href,"/inventory/items/create") or contains(@href,"/edit")]')->length);
    }

    public function test_desktop_header_and_mobile_body_navigation_share_view_state_and_reorder_count(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => ReorderRule::create(['category' => 'Synthetic low-stock', 'min_stock_threshold' => 5]));
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/inventory/items')->assertOk()->getContent());
        $desktop = $xp->query('//*[contains(@class,"inventory-items-header")]//nav[contains(@class,"items-header-nav")]')->item(0);
        $mobile = $xp->query('//*[@data-list-workspace]//nav[contains(@class,"items-body-nav")]')->item(0);
        $this->assertNotNull($desktop);
        $this->assertNotNull($mobile);
        foreach ([$desktop, $mobile] as $nav) {
            $this->assertSame(6, $xp->query('.//a[@data-stock-section]', $nav)->length);
            $this->assertSame(0, $xp->query('.//button', $nav)->length);
            $this->assertSame('items', $xp->evaluate('string(.//a[@aria-current="page"]/@data-stock-section)', $nav));
            foreach (['categories.index', 'tags.index', 'reorder.index'] as $route) {
                $this->assertSame(1, $xp->query('.//a[@href="'.route($route).'"]', $nav)->length);
            }
            $this->assertSame('1', trim($xp->evaluate('string(.//a[@href="'.route('reorder.index').'"]//span[contains(@class,"items-page-link-count")])', $nav)));
        }
        $this->assertSame(1, $xp->query('//*[@data-list-workspace]')->length);
    }

    public function test_manufacturer_navigation_keeps_categories_but_does_not_gain_retailer_routes_or_tabs(): void
    {
        [$owner] = $this->createManufacturerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/inventory/items')->assertOk()->getContent());
        foreach (['items-header-nav', 'items-body-nav'] as $class) {
            $nav = $xp->query('//nav[contains(@class,"'.$class.'")]')->item(0);
            $this->assertNotNull($nav);
            $this->assertSame(0, $xp->query('.//button | .//a[@href="'.route('tags.index').'" or @href="'.route('reorder.index').'"]', $nav)->length);
            $this->assertSame(1, $xp->query('.//a[@href="'.route('categories.index').'"]', $nav)->length);
        }
    }

    #[DataProvider('editions')]
    public function test_four_compact_metrics_live_in_header_and_preserve_edition_specific_holdings(string $edition, int $columns): void
    {
        [$owner, $shop] = $edition === 'retailer' ? $this->createRetailerTenant() : $this->createManufacturerTenant();
        $this->createItem($shop->id, null, ['metal_type' => 'gold', 'net_metal_weight' => 1.25]);
        $this->createItem($shop->id, null, ['metal_type' => 'silver', 'net_metal_weight' => 2.5]);
        $this->createItem($shop->id, null, ['metal_type' => 'gold', 'net_metal_weight' => 99.9, 'status' => 'sold']);
        $this->withoutVite();
        $html = $this->actingAs($owner)->get('/inventory/items?status=sold')->assertOk()->getContent();
        $xp = $this->xpath($html);
        $metrics = $xp->query('//*[@data-list-frame]/*[contains(@class,"items-register-header")]/*[@data-stock-metrics]')->item(0);
        $this->assertNotNull($metrics);
        $this->assertSame(1, $xp->query('//*[@data-stock-metrics]')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//*[@data-stock-metrics]')->length);
        $this->assertSame(0, $xp->query('//*[contains(concat(" ",@class," ")," items-kpi-grid ")]')->length);
        $this->assertSame(4, $xp->query('./*', $metrics)->length);
        foreach (['Total Items' => '3', 'In Stock' => '2', 'Sold' => '1'] as $label => $value) {
            $this->assertSame($value, trim($xp->evaluate('string(./dl[dt="'.$label.'"]/dd)', $metrics)));
        }
        $holdings = $xp->query('./section[@data-stock-holdings]', $metrics)->item(0);
        $this->assertNotNull($holdings);
        $this->assertStringContainsString('show: false', $holdings->getAttribute('x-data'));
        $this->assertStringContainsString('3000', $holdings->getAttribute('x-data'));
        $this->assertSame(1, $xp->query('.//button[@type="button"]', $holdings)->length);
        $this->assertStringContainsString('class="items-register-eye" @click="toggle()"', $html);
        $this->assertGreaterThan(0, $xp->query('.//span[@x-show="!show" and @x-cloak]', $holdings)->length);
        if ($edition === 'retailer') {
            $this->assertSame('Metal holdings KPI', $holdings->getAttribute('aria-label'));
            $this->assertStringContainsString('1.250 g', $holdings->textContent);
            $this->assertStringContainsString('2.500 g', $holdings->textContent);
            $this->assertStringNotContainsString('99.900 g', $holdings->textContent);
        } else {
            $this->assertSame('Total fine gold KPI', $holdings->getAttribute('aria-label'));
            $this->assertStringContainsString('1.146 g', $holdings->textContent);
            $this->assertStringNotContainsString('Metal Holdings', $holdings->textContent);
        }
        $this->assertSame($columns, $xp->query('//*[@data-list-scroll]//table/thead/tr/th')->length);
    }

    public function test_empty_stock_metrics_keep_zero_holdings_message(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/inventory/items')->assertOk()->getContent());
        $holdings = $xp->query('//*[@data-stock-metrics]/*[@data-stock-holdings]')->item(0);
        $this->assertNotNull($holdings);
        $this->assertStringContainsString('0.000 g', $holdings->textContent);
        $this->assertStringContainsString('No in-stock items', $holdings->textContent);
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
