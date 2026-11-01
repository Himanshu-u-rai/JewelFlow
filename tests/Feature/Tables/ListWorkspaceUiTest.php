<?php

namespace Tests\Feature\Tables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Http\Middleware\EnsurePlatformAdminMfa;

class ListWorkspaceUiTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    public static function listRoutes(): array
    {
        return [
            'vendors' => ['retailer', '/vendors'],
            'invoices' => ['retailer', '/invoices'],
            'quick bills' => ['retailer', '/quick-bills'],
            'installments' => ['retailer', '/installments'],
            'stock' => ['retailer', '/inventory/items'],
            'purchases' => ['retailer', '/inventory/purchases'],
            'karigars' => ['retailer', '/karigars'],
            'products' => ['manufacturer', '/products'],
            'job orders' => ['retailer', '/job-orders'],
            'karigar invoices' => ['retailer', '/karigar-invoices'],
            'borrowers' => ['dhiran', '/dhiran/borrowers'],
            'loans' => ['dhiran', '/dhiran/loans'],
            'active loan report' => ['dhiran', '/dhiran/reports/active'],
            'overdue loan report' => ['dhiran', '/dhiran/reports/overdue'],
            'interest report' => ['dhiran', '/dhiran/reports/interest'],
            'forfeiture report' => ['dhiran', '/dhiran/reports/forfeiture'],
            'profitability report' => ['dhiran', '/dhiran/reports/profitability'],
            'cashbook report' => ['dhiran', '/dhiran/reports/cashbook'],
            'admin shops' => ['admin', '/admin/shops'],
            'admin edition requests' => ['admin', '/admin/edition-requests'],
            'admin users' => ['admin', '/admin/users'],
            'admin invoices' => ['admin', '/admin/invoices'],
            'admin subscriptions' => ['admin', '/admin/subscriptions'],
        ];
    }

    #[DataProvider('listRoutes')]
    public function test_comparable_registers_opt_in_without_moving_filters_or_pagination_into_rows(string $realm, string $url): void
    {
        $this->withoutVite();
        if ($realm === 'admin') {
            $this->actingAs($this->createPlatformAdmin(), 'platform_admin')
                ->withSession([EnsurePlatformAdminMfa::SESSION_PASSED => true]);
        } elseif ($realm === 'dhiran') {
            $shop = $this->createShop('dhiran');
            \App\Models\Dhiran\DhiranSettings::getForShop($shop->id)->update(['is_enabled' => true]);
            $owner = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));
            $owner->forceFill(['realm' => 'dhiran', 'email_verified_at' => now()])->save();
            $this->createSubscription($shop->id, $this->createPlatformAdmin(), $this->createPlan('dhiran'));
            $this->actingAs($owner);
        } else {
            [$owner] = $realm === 'retailer' ? $this->createRetailerTenant() : $this->createManufacturerTenant();
            $this->actingAs($owner);
        }
        $response = $this->get($realm === 'dhiran' ? 'http://dhiran.localhost'.$url : $url);
        $this->assertSame(200, $response->status(), $url.' '.$response->headers->get('Location').' '.substr(trim(preg_replace('/\s+/', ' ', strip_tags($response->getContent()))), 0, 600));
        $html = $response->getContent();
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);
        $this->assertSame(1, $xp->query('//*[@data-list-workspace]')->length);
        foreach ($xp->query('//*[@data-list-scroll]') as $scroll) {
            $this->assertSame('0', $scroll->getAttribute('tabindex'));
            $this->assertSame('region', $scroll->getAttribute('role'));
            $this->assertNotEmpty($scroll->getAttribute('aria-label'));
            $this->assertSame(1, $xp->query('.//table[contains(@class,"jf-table")]', $scroll)->length);
            $this->assertSame(0, $xp->query('.//form[translate(@method,"get","GET")="GET"] | .//nav[@role="navigation"]', $scroll)->length);
        }
        $this->assertSame(0, $xp->query('//*[@data-list-fit]')->length);
    }

    public function test_customer_controls_and_pagination_stay_outside_the_row_scroll_regions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        for ($i = 0; $i < 16; $i++) {
            $this->createCustomer($shop->id, ['first_name' => 'Workspace '.$i]);
        }
        $this->withoutVite();
        $html = $this->actingAs($owner)->get('/customers')->assertOk()->getContent();
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);

        $this->assertSame(1, $xp->query('//*[@data-list-workspace]')->length);
        $this->assertSame(4, $xp->query('//*[@data-list-panel and @x-show]')->length);
        $this->assertSame(4, $xp->query('//*[@data-list-frame]')->length);
        $scrolls = $xp->query('//*[@data-list-scroll]');
        $this->assertSame(4, $scrolls->length);
        foreach ($scrolls as $scroll) {
            $this->assertSame('0', $scroll->getAttribute('tabindex'));
            $this->assertSame('region', $scroll->getAttribute('role'));
            $this->assertNotEmpty($scroll->getAttribute('aria-label'));
            $this->assertSame(1, $xp->query('.//table[contains(@class,"jf-table")]', $scroll)->length);
            $this->assertSame(0, $xp->query('.//form[@method="GET"] | .//nav[@role="navigation"] | .//*[contains(@class,"customers-kpi-grid")]', $scroll)->length);
        }
        $this->assertSame(1, $xp->query('//*[@data-list-frame]//nav[@role="navigation"]')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-fit]')->length, 'Without JavaScript the page must retain normal scrolling.');
        $this->assertSame(1, $xp->query('//input[@name="search" and @data-live-filter="customers-tbody"]')->length);
    }

    public function test_filtered_second_page_keeps_read_only_permissions_and_navigation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['customers.view']);
        for ($i = 0; $i < 16; $i++) {
            $this->createCustomer($shop->id, [
                'first_name' => 'Workspace '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                'created_at' => now()->subMinutes(16 - $i),
            ]);
        }
        $this->withoutVite();
        $response = $this->actingAs($owner)->get('/customers?search=Workspace&page=2');
        $response->assertOk()->assertSee('Workspace 00')->assertDontSee('Workspace 15')
            ->assertSee('data-list-scroll', false)->assertSee('page=1', false)
            ->assertDontSee('Archive customer')->assertDontSee('Add Customer');
        $this->assertStringContainsString('search=Workspace', $response->getContent());
    }
}
