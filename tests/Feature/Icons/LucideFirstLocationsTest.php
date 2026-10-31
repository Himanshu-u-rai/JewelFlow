<?php

namespace Tests\Feature\Icons;

use App\Models\Category;
use App\Models\SubCategory;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * First verification step of the Lucide rollout: six locations draw their icons through <x-icon>.
 * Labels, links, permissions and sizes must be exactly what they were; only the glyph changed.
 */
class LucideFirstLocationsTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutVite();
    }

    private function xp(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    /** Every icon on the page is decorative and sits in a control that still has its own name. */
    private function assertIconsAreDecorativeInsideNamedControls(\DOMXPath $xp): void
    {
        $icons = $xp->query('//svg[@data-icon]');
        $this->assertGreaterThan(0, $icons->length, 'no <x-icon> on the page');
        foreach ($icons as $svg) {
            $name = $svg->getAttribute('data-icon');
            $this->assertSame('true', $svg->getAttribute('aria-hidden'), "{$name} is announced");
            $this->assertFalse($svg->hasAttribute('role') || $svg->hasAttribute('aria-label'), "{$name} carries its own name");
            for ($c = $svg->parentNode; $c && $c->nodeName !== 'body'; $c = $c->parentNode) {
                if (in_array($c->nodeName, ['a', 'button'], true)) {
                    $named = trim($c->textContent) !== '' || $c->getAttribute('aria-label') !== '' || $c->getAttribute('title') !== '';
                    $this->assertTrue($named, "{$name} sits in a control with no accessible name");
                    break;
                }
            }
        }
    }

    private function issueQuickBill($owner): int
    {
        $payload = [
            'bill_date' => now()->toDateString(), 'pricing_mode' => 'gst_exclusive', 'gst_rate' => 3, 'save_action' => 'issue', 'customer_name' => 'Walk-in',
            'items' => [['description' => 'Gold chain', 'metal_type' => 'gold', 'net_weight' => 10, 'rate' => 7200, 'line_total' => 1000]],
            'payments' => [['payment_mode' => 'cash', 'amount' => 1030]],
        ];
        TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)->post(route('quick-bills.store'), $payload)->assertSessionHasNoErrors());

        return (int) DB::table('quick_bills')->where('shop_id', $owner->shop_id)->value('id');
    }

    private function page($owner, string $url): \DOMXPath
    {
        return $this->xp(TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)->get($url)->assertOk()->getContent()));
    }

    public function test_the_quick_bill_page_header_draws_print_and_edit_with_lucide(): void
    {
        [$owner] = $this->createRetailerTenant();
        $id = $this->issueQuickBill($owner);

        $xp = $this->page($owner, route('quick-bills.show', $id));

        $this->assertSame(1, $xp->query('//a[contains(@class,"qb-header-action-primary")]//svg[@data-icon="printer"]')->length);
        $this->assertSame(1, $xp->query('//a[contains(@class,"qb-header-action-edit")]//svg[@data-icon="pencil"]')->length);
        $this->assertSame('Print', trim($xp->query('//a[contains(@class,"qb-header-action-primary")]')->item(0)->textContent));
        $this->assertSame('Edit', trim($xp->query('//a[contains(@class,"qb-header-action-edit")]')->item(0)->textContent));
        $this->assertSame(0, $xp->query('//a[contains(@class,"qb-header-action")]//path[@d="M12 20h9" or @d="M6 9V3h12v6"]')->length, 'the old drawings are still there');
        // The phone floating menu repeats the same two actions at 14px.
        $this->assertSame(1, $xp->query('//a[contains(@class,"invoice-emi-mobile-fab-link")]//svg[@data-icon="printer" and @width="14" and @height="14"]')->length);
        $this->assertSame(1, $xp->query('//a[contains(@class,"invoice-emi-mobile-fab-link")]//svg[@data-icon="pencil" and @width="14" and @height="14"]')->length);
        $this->assertIconsAreDecorativeInsideNamedControls($xp);
    }

    public function test_the_quick_bills_list_draws_search_and_print_with_lucide(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->issueQuickBill($owner);

        $xp = $this->page($owner, route('quick-bills.index'));

        $this->assertGreaterThanOrEqual(1, $xp->query('//*[contains(@class,"qb-input-wrap")]//svg[contains(@class,"qb-input-icon") and @data-icon="search"]')->length);
        $this->assertGreaterThanOrEqual(1, $xp->query('//a[contains(@class,"is-print")]//svg[@data-icon="printer"]')->length);
        $this->assertSame(1, $xp->query('//button[contains(@class,"qb-btn") and normalize-space(.)="Search"]//svg[@data-icon="search"]')->length, 'the phone Search button');
        $this->assertSame(0, $xp->query('//a[contains(@class,"is-print")]//path[contains(@d,"M6 9V2h12v7")]')->length, 'the old print drawing is still there');
        foreach ($xp->query('//a[contains(@class,"is-print")]') as $a) {
            $this->assertSame('Print', trim($a->textContent));
        }
        $this->assertIconsAreDecorativeInsideNamedControls($xp);
    }

    public function test_the_category_cards_draw_rename_and_delete_with_lucide_and_keep_their_names_and_sizes(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function () use ($shop) {
            $ring = Category::create(['shop_id' => $shop->id, 'name' => 'Ring']);
            SubCategory::create(['category_id' => $ring->id, 'name' => 'Band']);
        });

        $xp = $this->page($owner, route('categories.index'));

        $cases = [
            ['Rename category Ring', 'pencil', 'w-4'], ['Delete category Ring', 'trash-2', 'w-4'],
            ['Rename sub-category Band', 'pencil', 'w-3.5'], ['Delete sub-category Band', 'trash-2', 'w-3.5'],
        ];
        foreach ($cases as [$label, $icon, $sizeClass]) {
            $svg = $xp->query('//button[@aria-label="' . $label . '"]//svg[@data-icon="' . $icon . '"]');
            $this->assertSame(1, $svg->length, "{$label}: expected one {$icon} icon");
            $this->assertStringContainsString($sizeClass, $svg->item(0)->getAttribute('class'), "{$label}: size class lost");
        }
        $this->assertIconsAreDecorativeInsideNamedControls($xp);
    }

    public function test_the_customers_list_draws_edit_with_lucide_in_an_icon_only_row_action(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer($shop->id, ['first_name' => 'Imran', 'last_name' => 'Khan']);

        $xp = $this->page($owner, route('customers.index'));

        // the row action is icon-only (16px glyph, accessible name in a visually hidden span, floating label in data-tip)
        $edit = $xp->query('//a[contains(@class,"jf-row-action--edit") and @data-tip="Edit" and normalize-space(.)="Edit"]//svg[@data-icon="pencil" and @width="16" and @height="16"]');
        $this->assertGreaterThanOrEqual(1, $edit->length);
        $this->assertSame(0, $xp->query('//a[contains(@class,"jf-row-action")]//path[starts-with(@d,"M17 3a2.828")]')->length, 'the old edit drawing is still there');
        $this->assertIconsAreDecorativeInsideNamedControls($xp);
    }

    public function test_the_masters_hub_draws_customers_with_the_lucide_users_icon_at_20px(): void
    {
        [$owner] = $this->createRetailerTenant();

        $xp = $this->page($owner, route('masters.index'));

        $card = $xp->query('//a[@data-masters-card="customers"]');
        $this->assertSame(1, $card->length);
        $this->assertSame(1, $xp->query('//a[@data-masters-card="customers"]//svg[@data-icon="users" and @width="20" and @height="20"]')->length);
        $this->assertStringContainsString('Customers', $card->item(0)->textContent);
        $this->assertGreaterThan(0, $xp->query('//a[@data-masters-card="vendors"]//svg[@data-icon]')->length, 'the rollout reached the other Masters cards too');
    }
}
