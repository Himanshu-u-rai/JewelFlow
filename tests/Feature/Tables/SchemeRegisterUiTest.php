<?php

namespace Tests\Feature\Tables;

use App\Models\Scheme;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class SchemeRegisterUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_scheme_summary_and_single_filter_form_stay_outside_scrolling_records(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->scheme($shop->id, 'Gold plan');
        $this->scheme($shop->id, 'Festival offer', 'festival_sale', false);
        $this->scheme($shop->id, 'Discount offer', 'discount_offer');
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/schemes?search=Gold&type=gold_savings')->assertOk()->getContent());

        $this->assertSame(1, $xp->query('//*[@data-list-workspace]//*[@data-list-frame]')->length);
        $summary = $xp->query('//dl[@aria-label="Scheme summary"]')->item(0);
        $this->assertNotNull($summary);
        $this->assertSame('1', $xp->evaluate('string(div[dt="Gold Savings"]/dd)', $summary));
        $this->assertSame('2', $xp->evaluate('string(div[dt="Offers / Sales"]/dd)', $summary));
        $this->assertSame('2', $xp->evaluate('string(div[dt="Active"]/dd)', $summary));
        $this->assertSame(0, $xp->query('//*[contains(@class,"schemes-kpi-grid")]')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//dl | //*[@data-list-scroll]//form')->length);
        $this->assertSame(1, $xp->query('//form[@method="GET"]')->length);
        $form = $xp->query('//dialog[@id="schemes-filter-panel"]//form')->item(0);
        $this->assertNotNull($form);
        $this->assertSame(route('schemes.index'), $form->getAttribute('action'));
        $this->assertSame('Gold', $xp->evaluate('string(.//input[@name="search"]/@value)', $form));
        $this->assertSame('gold_savings', $xp->evaluate('string(.//select[@name="type"]/option[@selected]/@value)', $form));
        $this->assertSame(4, $xp->query('.//select[@name="type"]/option', $form)->length);
        $this->assertSame(1, $xp->query('.//*[@data-enhance-selects-placement="viewport"]', $form)->length);
        $this->assertSame('true', $xp->evaluate('string(.//button[@data-scheme-search-toggle]/@aria-expanded)', $form));
        $this->assertSame(1, $xp->query('.//button[@type="submit"]', $form)->length);
        $this->assertSame(1, $xp->query('.//a[@href="'.route('schemes.index').'"]', $form)->length);
    }

    public function test_filtered_page_two_keeps_continuous_numbers_and_query_pagination_in_both_layouts(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        for ($i = 1; $i <= 20; $i++) {
            $this->scheme($shop->id, 'Review plan '.$i);
        }
        $this->scheme($shop->id, 'Review offer', 'discount_offer');
        [, $otherShop] = $this->createRetailerTenant();
        $this->scheme($otherShop->id, 'Review private plan');
        $this->withoutVite();
        $response = $this->actingAs($owner)->get('/schemes?search=Review&type=gold_savings&page=2')->assertOk();
        $response->assertDontSee('Review offer')->assertDontSee('Review private plan');
        $xp = $this->xpath($response->getContent());
        $numbers = [];
        foreach ($xp->query('//*[@data-list-scroll]//tbody/tr/td[@class="jf-table-index"]') as $cell) {
            $numbers[] = (int) trim($cell->textContent);
        }
        $this->assertSame([16, 17, 18, 19, 20], $numbers);
        $this->assertSame(5, $xp->query('//article//*[@class="jf-record-index"]')->length);
        $scroll = $xp->query('//*[@data-list-scroll]')->item(0);
        $this->assertSame('0', $scroll->getAttribute('tabindex'));
        $this->assertSame('region', $scroll->getAttribute('role'));
        $this->assertSame('Scheme records', $scroll->getAttribute('aria-label'));
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//nav')->length);
        $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"search=Review") and contains(@href,"type=gold_savings") and contains(@href,"page=1")]')->length);
        $this->assertSame(7, $xp->query('//table/thead/tr/th')->length);
    }

    public function test_empty_register_retains_named_controls_and_the_same_mobile_filter_panel(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/schemes')->assertOk()->getContent());
        $this->assertSame('7', $xp->evaluate('string(//*[@data-list-scroll]//td[@colspan]/@colspan)'));
        $this->assertSame('false', $xp->evaluate('string(//button[@data-scheme-search-toggle]/@aria-expanded)'));
        $this->assertSame(1, $xp->query('//input[@name="search"]')->length);
        $this->assertSame(1, $xp->query('//label[@for="schemes-search"]')->length);
        $this->assertSame(1, $xp->query('//label[@for="schemes-type"]')->length);
        $this->assertSame(1, $xp->query('//button[@data-filter-toggle][@aria-controls="schemes-filter-panel"]')->length);
        $this->assertSame('Scheme filters', $xp->evaluate('string(//dialog/@aria-label)'));
        $this->assertSame('Create Scheme', $xp->evaluate('string(//a[contains(@class,"customers-add-btn")]/@aria-label)'));
    }

    public function test_catalog_only_role_keeps_view_and_create_but_cannot_enroll(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $scheme = $this->scheme($shop->id, 'Restricted role plan');
        $this->grantOnlyPermissions($owner, ['catalog.manage']);
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/schemes')->assertOk()->getContent());
        $this->assertSame(3, $xp->query('//a[@href="'.route('schemes.show', $scheme).'"]')->length);
        $this->assertSame(0, $xp->query('//a[@href="'.route('schemes.enroll.form', $scheme).'"]')->length);
        $this->assertSame(1, $xp->query('//a[@href="'.route('schemes.create').'"]')->length);
        $this->grantOnlyPermissions($owner, ['sales.view']);
        $this->get('/schemes')->assertForbidden();
    }

    private function scheme(int $shopId, string $name, string $type = 'gold_savings', bool $active = true): Scheme
    {
        return TenantContext::runFor($shopId, fn () => Scheme::create([
            'name' => $name, 'type' => $type, 'is_active' => $active,
            'start_date' => today(), 'total_installments' => 11,
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
