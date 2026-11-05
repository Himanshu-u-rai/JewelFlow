<?php

namespace Tests\Feature\Tables;

use App\Models\Karigar;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class KarigarDirectoryUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_directory_has_header_tiles_one_filter_form_and_matching_mobile_indices(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            for ($i = 1; $i <= 24; $i++) {
                Karigar::forceCreate(['shop_id' => $shop->id, 'name' => sprintf('Synthetic artisan %02d', $i), 'is_active' => true]);
            }
        });
        $this->withoutVite();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.index')))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(4, $xp->query('//*[@data-karigar-register-head]//dl[@class="karigars-metric"]')->length);
        $this->assertSame(1, $xp->query('//dialog[@id="karigars-filter-panel"]//form[@method="GET"]')->length);
        $this->assertSame(1, $xp->query('//form//input[@name="search"]')->length);
        $this->assertSame(1, $xp->query('//form//select[@name="status"]')->length);
        $expected = array_map('strval', range(1, 24));
        $this->assertSame($expected, array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//td[@class="jf-table-index"]'))));
        $this->assertSame($expected, array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//*[@data-karigar-mobile-index]'))));
        $this->assertSame(24, $xp->query('//table//a[@data-tip="View"]')->length);
        $this->assertSame(0, $xp->query('//*[contains(@class,"karigars-kpi-grid") or contains(@class,"karigars-filter-tabs")]')->length);
        $this->assertSame(10, $xp->query('//table/thead/tr/th[@scope="col"]')->length);
    }

    public function test_search_is_tenant_scoped_composes_with_status_and_keeps_global_counts(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            Karigar::forceCreate(['shop_id' => $shop->id, 'name' => 'Synthetic active', 'city' => 'Searchville', 'is_active' => true]);
            Karigar::forceCreate(['shop_id' => $shop->id, 'name' => 'Synthetic disabled', 'city' => 'Searchville', 'is_active' => false]);
            Karigar::forceCreate(['shop_id' => $shop->id, 'name' => 'Other artisan', 'city' => 'Elsewhere', 'is_active' => true]);
        });
        [, $otherShop] = $this->createRetailerTenant();
        TenantContext::runFor($otherShop->id, fn () => Karigar::forceCreate(['shop_id' => $otherShop->id, 'name' => 'Foreign artisan', 'city' => 'Searchville']));
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.index', ['search' => 'Searchville', 'status' => 'all'])))->assertOk();
        $this->assertSame(['Synthetic active', 'Synthetic disabled'], $response->viewData('karigars')->pluck('name')->all());
        $this->assertSame(3, (int) $response->viewData('counts')->all_count);
        $this->assertSame(2, (int) $response->viewData('counts')->active_count);
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.index', ['search' => 'Searchville', 'status' => 'inactive'])))->assertOk();
        $this->assertSame(['Synthetic disabled'], $response->viewData('karigars')->pluck('name')->all());
        $this->assertSame('archived', $response->viewData('status'));
    }

    public function test_view_only_users_keep_view_links_without_mutation_actions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => Karigar::forceCreate(['shop_id' => $shop->id, 'name' => 'Synthetic viewer row']));
        $this->grantOnlyPermissions($owner, ['karigar.view']);
        $owner->unsetRelation('role');
        $this->withoutVite();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.index')))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(1, $xp->query('//table//a[@data-tip="View"]')->length);
        $this->assertSame(0, $xp->query('//*[contains(@class,"karigars-index-page")]//form[@method="POST"]')->length);
        $this->assertSame(0, $xp->query('//a[contains(@href,"/karigars/create") or contains(@href,"/edit")]')->length);
    }

    public function test_search_matches_supported_fields_and_preserves_the_filter_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => Karigar::forceCreate([
            'shop_id' => $shop->id, 'name' => 'Synthetic Goldsmith', 'shop_name' => 'Synthetic Workshop',
            'mobile' => '9000000222', 'gst_number' => '24ABCDE1234F1Z5', 'city' => 'Testville',
        ]));
        $this->withoutVite();
        foreach (['goldsmith', 'workshop', '9000000222', 'ABCDE1234', 'testville'] as $search) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.index', ['search' => $search, 'status' => 'all'])))->assertOk();
            $this->assertSame(['Synthetic Goldsmith'], $response->viewData('karigars')->pluck('name')->all());
            $xp = $this->xpath($response->getContent());
            $this->assertSame($search, $xp->evaluate('string(//input[@name="search"]/@value)'));
            $this->assertSame('all', $xp->evaluate('string(//select[@name="status"]/option[@selected]/@value)'));
        }
    }

    public function test_no_match_message_is_not_a_first_karigar_prompt_and_search_is_validated(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('karigars.index', ['search' => 'Nobody'])))->assertOk()
            ->assertSee('No karigars match your search.')->assertDontSee('Add your first karigar');
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->getJson(route('karigars.index', ['search' => ['invalid']])))->assertUnprocessable();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->getJson(route('karigars.index', ['search' => str_repeat('x', 121)])))->assertUnprocessable();
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
