<?php

namespace Tests\Feature\Tables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Http\Middleware\EnsurePlatformAdminMfa;
use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Support\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;

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
            'occasion register' => ['retailer', '/report/occasions'],
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

    public function test_occasion_records_remain_available_in_mobile_cards_on_both_entry_points(): void
    {
        $this->travelTo(now()->startOfDay());
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id, [
            'first_name' => 'Occasion Example',
            'date_of_birth' => now()->addDay()->subYears(25)->toDateString(),
            'anniversary_date' => now()->addDays(2)->subYears(10)->toDateString(),
        ]);
        $this->withoutVite();
        foreach (['/customers?view=occasions&occasion_days=7', '/report/occasions?days=7'] as $url) {
            $html = $this->actingAs($owner)->get($url)->assertOk()->getContent();
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xp = new \DOMXPath($dom);
            $cards = $xp->query('//*[contains(@class,"customers-mobile-cards--occasions")]')->item(0);
            $this->assertNotNull($cards, $url.' must not hide its only record presentation on phones.');
            $this->assertSame(2, $xp->query('./article', $cards)->length);
            $this->assertStringContainsString('Occasion Example', $cards->textContent);
            $this->assertStringContainsString('1 days', $cards->textContent);
            $this->assertStringContainsString('2 days', $cards->textContent);
            $this->assertSame(2, $xp->query('.//a[@href="'.route('customers.show', $customer).'"]', $cards)->length);
            $this->assertSame(2, $xp->query('//*[@aria-label="Occasion records"]//tbody/tr')->length);
        }
    }

    public function test_loyalty_tab_preserves_filtered_pagination_outside_its_rows(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        for ($i = 0; $i < 16; $i++) {
            $this->createCustomer($shop->id, ['first_name' => 'TabMember'.$i, 'loyalty_points' => 160 - $i]);
        }
        $this->withoutVite();
        $response = $this->actingAs($owner)->get('/customers?view=loyalty&loyalty_search=TabMember&loyalty_page=2');
        $response->assertOk();
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);
        $loyalty = $xp->query('//*[@aria-label="Loyalty records"]')->item(0);
        $this->assertStringContainsString('TabMember15', $loyalty->textContent);
        $this->assertStringNotContainsString('TabMember14', $loyalty->textContent);
        $this->assertSame(1, $xp->query('//*[@aria-label="Loyalty records"]//tbody/tr')->length);
        $this->assertSame(0, $xp->query('//*[@aria-label="Loyalty records"]//nav')->length);
        $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"view=loyalty") and contains(@href,"loyalty_search=TabMember") and contains(@href,"loyalty_page=1")]')->length);
    }

    public function test_populated_emi_rows_keep_filtered_pagination_outside_the_scroll_region(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite();
        $response = $this->actingAs($owner)->get('/customers?view=emi&emi_status=active')->assertOk();
        $data = $response->original->getData();
        // Presentation snapshots only: do not create invoices, plans or payment records.
        $plans = [];
        for ($i = 1; $i <= 15; $i++) {
            $plan = new InstallmentPlan([
                'total_amount' => 12000, 'emi_amount' => 1000, 'emis_paid' => 2,
                'total_emis' => 12, 'status' => 'active', 'next_due_date' => now()->addMonth(),
            ]);
            $plan->id = 1000 + $i;
            $plan->setRelation('customer', new Customer(['first_name' => 'EMI Example '.$i]));
            $plans[] = $plan;
        }
        $data['installmentData']['installmentPlans'] = new LengthAwarePaginator($plans, 31, 15, 1, [
            'path' => url('/customers'), 'pageName' => 'emi_page',
        ]);
        $html = TenantContext::runFor($shop->id, fn () => view('customers.index', $data)->render());
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xp = new \DOMXPath($dom);
        $this->assertSame(15, $xp->query('//*[@aria-label="Installment records"]//tbody/tr')->length);
        $this->assertSame(0, $xp->query('//*[@aria-label="Installment records"]//nav')->length);
        $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"view=emi") and contains(@href,"emi_status=active") and contains(@href,"emi_page=2")]')->length);
        $this->assertSame(15, $xp->query('//*[contains(@class,"customers-mobile-cards--emi")]/article')->length);
    }
}
