<?php

namespace Tests\Feature\Tables;

use App\Models\Category;
use App\Models\ReorderRule;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class StockToolsThemeTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_category_directory_integrates_totals_search_and_continuous_row_numbers(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () {
            for ($i = 1; $i <= 22; $i++) {
                Category::create(['name' => sprintf('Synthetic group %02d', $i)]);
            }
        });
        $this->withoutVite();
        $response = $this->actingAs($owner)->get(route('categories.index', ['q' => 'Synthetic', 'page' => 2]))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//*[contains(@class,"stock-tool-header")]//form[@action="'.route('categories.index').'"]')->length);
        $this->assertSame(3, $xp->query('//*[contains(@class,"stock-tool-header")]//p[@class="categories-kpi-value"]')->length);
        $this->assertSame(['21', '22'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($xp->query('//*[@id="categories-list"]//*[@data-category-index]'))));
        $this->assertSame(0, $xp->query('//*[@id="categories-list"]//nav')->length);
        $this->assertSame(1, $xp->query('//*[@id="addCategoryName"]')->length);
    }

    public function test_tag_workspace_keeps_cross_page_selection_and_print_form_with_accessible_row_selection(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        for ($i = 1; $i <= 22; $i++) {
            $this->createItem($shop->id, null, ['category' => 'Synthetic Rings', 'design' => 'Synthetic tag '.$i]);
        }
        $this->withoutVite();
        $response = $this->actingAs($owner)->get(route('tags.index', ['search' => 'Synthetic', 'category' => 'Synthetic Rings', 'page' => 2]))->assertOk();
        $response->assertViewHas('allMatchingIds', fn ($ids) => count($ids) === 22);
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//*[contains(@class,"stock-tool-header")]//form[@action="'.route('tags.index').'"]//button[@type="submit"]')->length);
        $this->assertSame(1, $xp->query('//form[@id="print-form" and @method="GET" and @target="_blank"]')->length);
        $this->assertSame(0, $xp->query('//form//form')->length);
        $this->assertSame(['21', '22'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($xp->query('//*[@data-list-scroll]//td[@class="jf-table-index"]'))));
        $this->assertSame(2, $xp->query('//*[@data-list-scroll]//input[@type="checkbox" and @aria-label and not(@tabindex="-1")]')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//nav')->length);
        $this->assertSame(route('tags.print'), $xp->evaluate('string(//form[@id="print-form"]/@action)'));
        $this->assertSame('viewport', $xp->evaluate('string(//form[@id="print-form"]/@data-enhance-selects-placement)'));
    }

    public function test_reorder_keeps_one_indexed_rules_table_with_permission_scoped_mutation_controls(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () {
            for ($i = 1; $i <= 3; $i++) {
                ReorderRule::create(['category' => 'Synthetic '.$i, 'min_stock_threshold' => 5]);
            }
        });
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get(route('reorder.index'))->assertOk()->getContent());
        $this->assertSame(['1', '2', '3'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($xp->query('//*[@data-list-scroll]//td[@class="jf-table-index"]'))));
        $this->assertSame(3, $xp->query('//form[@data-ajax-delete]')->length, 'Each rule has one mutation form, not duplicate desktop/mobile forms.');
        $this->grantOnlyPermissions($owner, ['inventory.view']);
        $response = $this->actingAs($owner)->get(route('reorder.index'))->assertOk();
        $response->assertDontSee('data-ajax-delete', false)->assertDontSee('href="'.route('reorder.create').'"', false);
        $this->assertSame(0, $this->xpath($response->getContent())->query('//a[contains(@href,"/reorder/") and contains(@href,"/edit")]')->length);
    }

    public function test_reorder_prioritizes_real_shortages_without_including_paused_or_stocked_rules(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            ReorderRule::create(['category' => 'Synthetic <Ring>', 'min_stock_threshold' => 5]);
            ReorderRule::create(['category' => 'Synthetic stocked', 'min_stock_threshold' => 1]);
            ReorderRule::create(['category' => 'Synthetic paused', 'min_stock_threshold' => 8, 'is_active' => false]);
            $this->createItem($shop->id, null, ['category' => 'Synthetic <Ring>']);
            $this->createItem($shop->id, null, ['category' => 'Synthetic <Ring>']);
            $this->createItem($shop->id, null, ['category' => 'Synthetic stocked']);
        });
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get(route('reorder.index'))->assertOk()->getContent());
        $this->assertSame(1, $xp->query('//*[@data-reorder-alerts]//tbody/tr')->length);
        $this->assertStringContainsString('Synthetic <Ring>', $xp->evaluate('string(//*[@data-reorder-alerts]//tbody/tr/th)'));
        $this->assertSame(['2', '5', '3'], array_map(fn ($node) => trim($node->textContent), iterator_to_array($xp->query('//*[@data-reorder-alerts]//tbody/tr/td[@class="text-right"]'))));
        $this->assertSame(3, $xp->query('//*[@data-reorder-rules]//tbody/tr')->length);
        $this->assertSame(1, $xp->query('//*[@data-reorder-alerts]/following-sibling::details[@data-reorder-rules and not(@open)]')->length);
    }

    public function test_reorder_distinguishes_no_rules_from_configured_stock_with_no_shortages(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        $this->actingAs($owner)->get(route('reorder.index'))->assertOk()
            ->assertSee('No reorder rules yet')->assertDontSee('Stock is within your minimums');

        TenantContext::runFor($shop->id, function () use ($shop) {
            ReorderRule::create(['category' => 'Synthetic stocked', 'min_stock_threshold' => 1]);
            $this->createItem($shop->id, null, ['category' => 'Synthetic stocked']);
        });
        $response = $this->actingAs($owner)->get(route('reorder.index'))->assertOk()
            ->assertSee('Stock is within your minimums')->assertDontSee('No reorder rules yet');
        $this->assertSame(1, $this->xpath($response->getContent())->query('//details[@data-reorder-rules and @open]')->length);
    }

    public function test_reorder_does_not_call_an_all_paused_configuration_all_clear(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => ReorderRule::create([
            'category' => 'Synthetic paused', 'min_stock_threshold' => 5, 'is_active' => false,
        ]));
        $this->withoutVite();
        $this->actingAs($owner)->get(route('reorder.index'))->assertOk()
            ->assertSee('All rules are paused')->assertDontSee('Stock is within your minimums');
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
