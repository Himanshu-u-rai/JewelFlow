<?php

namespace Tests\Feature\Tables;

use App\Services\RetailerReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class InventoryStockPagesTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_legacy_stock_views_redirect_without_losing_query_parameters(): void
    {
        [$owner] = $this->createRetailerTenant();
        foreach (['aging' => '/inventory/stock-aging', 'sellers' => '/inventory/sell-trend'] as $view => $path) {
            $response = $this->actingAs($owner)->get('/inventory/items?'.http_build_query(['view' => $view, 'seller_period' => '90', 'search' => 'Synthetic', 'page' => 2]));
            $response->assertRedirect();
            $url = $response->headers->get('Location');
            $this->assertSame($path, parse_url($url, PHP_URL_PATH));
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame(['seller_period' => '90', 'search' => 'Synthetic', 'page' => '2'], $query);
        }
    }

    public function test_stock_list_does_not_load_either_analytics_report(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $this->mock(RetailerReportService::class, function ($mock) {
            $mock->shouldNotReceive('stockAging');
            $mock->shouldNotReceive('bestSellers');
            $mock->shouldNotReceive('worstSellers');
        });
        $this->actingAs($owner)->get('/inventory/items')->assertOk()
            ->assertDontSee('data-aging-empty', false)->assertDontSee('data-sales-empty', false);
    }

    public function test_reports_are_real_pages_with_native_links_and_no_hidden_stock_register(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        foreach (['/inventory/stock-aging' => 'Stock Aging', '/inventory/sell-trend?seller_period=90' => 'Sell Trend'] as $url => $title) {
            $response = $this->actingAs($owner)->get($url)->assertOk();
            $response->assertSee($title)->assertDontSee('data-stock-filters', false)
                ->assertSee('href="'.url('/inventory/stock-aging').'"', false)
                ->assertSee('href="'.url('/inventory/sell-trend').'"', false);
            $this->assertMatchesRegularExpression('~href="'.preg_quote(route('inventory.items.index'), '~').'" class="nav-link active"~', $response->getContent());
            if ($title === 'Sell Trend') {
                $response->assertSee('action="'.url('/inventory/sell-trend').'"', false)
                    ->assertSee('Last 90 days')->assertDontSee('name="view"', false);
            }
        }
    }

    public function test_analytics_keep_inventory_permission_and_retailer_edition_gates(): void
    {
        [$staff] = $this->createRetailerTenant();
        $this->withoutVite();
        $this->grantOnlyPermissions($staff, ['inventory.view']);
        foreach (['/inventory/stock-aging', '/inventory/sell-trend'] as $url) {
            $this->actingAs($staff)->get($url)->assertOk();
        }
        $this->grantOnlyPermissions($staff, ['reports.view']);
        foreach (['/inventory/stock-aging', '/inventory/sell-trend'] as $url) {
            $this->actingAs($staff)->get($url)->assertForbidden();
        }
        [$maker] = $this->createManufacturerTenant();
        foreach (['/inventory/stock-aging', '/inventory/sell-trend'] as $url) {
            $this->actingAs($maker)->get($url)->assertForbidden();
        }
    }
}
