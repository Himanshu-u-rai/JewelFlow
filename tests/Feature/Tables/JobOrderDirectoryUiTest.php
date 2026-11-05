<?php

namespace Tests\Feature\Tables;

use App\Models\JobOrder;
use App\Models\Karigar;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class JobOrderDirectoryUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    private function orders(int $shopId, int $count = 30): Karigar
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $count) {
            $karigar = Karigar::create(['shop_id' => $shopId, 'name' => 'Synthetic long workshop name for wrapping checks']);
            for ($i = 1; $i <= $count; $i++) {
                JobOrder::forceCreate([
                    'shop_id' => $shopId, 'karigar_id' => $karigar->id,
                    'job_order_number' => sprintf('SYN-JO-%03d', $i), 'challan_number' => 'SYN-DC-'.$i,
                    'metal_type' => 'gold', 'purity' => 22, 'job_type' => 'repair',
                    'issue_date' => sprintf('2026-10-%02d', min($i, 30)),
                    'status' => ['cancelled', 'issued', 'partial_return', 'completed'][$i % 4],
                    'discrepancy_flags' => $i % 5 === 0 ? ['SHORT_RETURN'] : [],
                    'created_at' => now()->startOfDay()->addSeconds($i),
                ]);
            }
            return $karigar;
        });
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

    public function test_register_has_header_metrics_one_filter_form_and_continuing_page_indices(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->orders($shop->id);
        $this->withoutVite();
        foreach ([1 => range(1, 25), 2 => range(26, 30)] as $page => $indices) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.index', ['page' => $page])))->assertOk();
            $xp = $this->xpath($response->getContent());
            $this->assertSame(4, $xp->query('//*[@data-job-register-head]//dl[@data-job-metric]')->length);
            $this->assertSame(1, $xp->query('//dialog[@id="job-orders-filter-panel"]//form[@method="GET"]')->length);
            $this->assertSame(1, $xp->query('//select[@name="status"]')->length);
            $this->assertSame(1, $xp->query('//select[@name="karigar_id"]')->length);
            $expected = array_map('strval', $indices);
            $this->assertSame($expected, array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//td[@class="jf-table-index"]'))));
            $this->assertSame($expected, array_map(fn ($n) => trim($n->textContent), iterator_to_array($xp->query('//*[@data-job-mobile-index]'))));
            $this->assertSame(count($indices), $xp->query('//table//a[@data-tip="View"]')->length);
            $this->assertSame(10, $xp->query('//table/thead/tr/th[@scope="col"]')->length);
            $this->assertSame(1, $xp->query('//*[@data-job-pagination and not(ancestor::*[@data-list-scroll])]')->length);
            $this->assertSame('30', $xp->evaluate('string(//*[@data-job-metric="total"]/dd)'));
            if ($page === 2) {
                $this->assertSame('3', $xp->evaluate('string(//*[@data-job-metric="open"]/dd)'));
                $this->assertSame('1', $xp->evaluate('string(//*[@data-job-metric="completed"]/dd)'));
                $this->assertSame('1', $xp->evaluate('string(//*[@data-job-metric="flagged"]/dd)'));
                $response->assertSee('Open · page')->assertSee('Completed · page')->assertSee('Flagged · page');
            }
        }
        $this->assertSame(30, TenantContext::runFor($shop->id, fn () => JobOrder::count()));
    }

    public function test_all_existing_filters_compose_and_pagination_preserves_them_without_foreign_data(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $karigar = $this->orders($shop->id);
        [, $otherShop] = $this->createRetailerTenant();
        $this->orders($otherShop->id, 1);
        TenantContext::runFor($otherShop->id, fn () => Karigar::create(['shop_id' => $otherShop->id, 'name' => 'Foreign private workshop']));
        $this->withoutVite();
        $filters = ['status' => 'issued', 'karigar_id' => $karigar->id, 'from' => '2026-10-01', 'to' => '2026-10-10'];
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.index', $filters)))->assertOk();
        $this->assertSame(['SYN-JO-009', 'SYN-JO-005', 'SYN-JO-001'], $response->viewData('orders')->pluck('job_order_number')->all());
        $response->assertDontSee('Foreign private workshop');
        $xp = $this->xpath($response->getContent());
        $this->assertSame('issued', $xp->evaluate('string(//select[@name="status"]/option[@selected]/@value)'));
        $this->assertSame((string) $karigar->id, $xp->evaluate('string(//select[@name="karigar_id"]/option[@selected]/@value)'));
        $this->assertSame('2026-10-01', $xp->evaluate('string(//input[@name="from"]/@value)'));
        $this->assertSame('2026-10-10', $xp->evaluate('string(//input[@name="to"]/@value)'));
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.index', ['karigar_id' => $karigar->id])))->assertOk();
        $this->assertSame(30, $response->viewData('orders')->total());
        $next = $response->viewData('orders')->nextPageUrl();
        $this->assertStringContainsString('karigar_id='.$karigar->id, $next);
        TenantContext::runFor($shop->id, fn () => $this->get($next))->assertOk();
    }

    public function test_viewers_have_read_only_view_actions_and_empty_filter_results_are_clear(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->orders($shop->id, 1);
        $this->grantOnlyPermissions($owner, ['job_order.view']);
        $owner->unsetRelation('role');
        $this->withoutVite();
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(route('job-orders.index')))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//table//a[@data-tip="View"]')->length);
        $this->assertSame(0, $xp->query('//a[contains(@href,"/job-orders/create")]')->length);
        $this->assertSame(0, $xp->query('//*[contains(@class,"job-orders-index-page")]//form[@method="POST"]')->length);
        TenantContext::runFor($shop->id, fn () => $this->get(route('job-orders.index', ['from' => '2030-01-01'])))->assertOk()
            ->assertSee('No job orders match your filters.')->assertDontSee('Issue your first job order');
    }
}
