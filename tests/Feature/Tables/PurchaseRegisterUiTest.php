<?php

namespace Tests\Feature\Tables;

use App\Models\StockPurchase;
use App\Models\Vendor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class PurchaseRegisterUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_one_header_form_keeps_all_filters_selected_and_summary_outside_rows(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $vendor = TenantContext::runFor($shop->id, fn () => Vendor::create(['name' => 'Synthetic supplier', 'is_active' => true]));
        $this->withoutVite();
        $html = $this->actingAs($owner)->get('/inventory/purchases?search=Review&status=draft&vendor_id='.$vendor->id.'&date_from=2026-01-01&date_to=2026-12-31')->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertStringNotContainsString('Filter stock purchases', $html);
        $form = $xp->query('//dialog[@id="purchases-filter-panel"]//form')->item(0);
        $this->assertNotNull($form);
        $this->assertSame('GET', $form->getAttribute('method'));
        $this->assertSame(route('inventory.purchases.index'), $form->getAttribute('action'));
        $this->assertSame(1, $xp->query('//form[@method="GET"]')->length);
        foreach (['search' => 'Review', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31'] as $name => $value) {
            $input = $xp->query('.//input[@name="'.$name.'"]', $form)->item(0);
            $this->assertNotNull($input);
            $this->assertSame($value, $input->getAttribute('value'));
            $this->assertSame(1, $xp->query('.//label[@for="'.$input->getAttribute('id').'"]', $form)->length);
        }
        foreach (['status' => 'draft', 'vendor_id' => (string) $vendor->id] as $name => $value) {
            $this->assertSame($value, $xp->evaluate('string(.//select[@name="'.$name.'"]/option[@selected]/@value)', $form));
        }
        $this->assertSame(1, $xp->query('//dl[@aria-label="Stock purchase summary"]')->length);
        $this->assertSame(4, $xp->query('//dl[@aria-label="Stock purchase summary"]/div')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//form[@method="GET"] | //*[@data-list-scroll]//dl')->length);
        $this->assertSame(1, $xp->query('//button[@data-filter-toggle][@aria-controls="purchases-filter-panel"]')->length);
    }

    public function test_filtered_second_page_has_continuous_desktop_and_phone_numbers_and_external_pagination(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, function (): void {
            for ($i = 1; $i <= 22; $i++) {
                StockPurchase::create([
                    'purchase_number' => 'UI-REVIEW-'.$i, 'supplier_name' => 'Synthetic supplier',
                    'purchase_date' => today(), 'status' => 'draft',
                ]);
            }
        });
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/inventory/purchases?search=UI-REVIEW&status=draft&page=2')->assertOk()->getContent());
        $numbers = [];
        foreach ($xp->query('//*[@data-list-scroll]//tbody/tr/td[@class="jf-table-index"]') as $cell) {
            $numbers[] = (int) trim($cell->textContent);
        }
        $this->assertSame([21, 22], $numbers);
        $this->assertSame(2, $xp->query('//article//span[@class="jf-record-index"]')->length);
        $this->assertSame(9, $xp->query('//table/thead/tr/th')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//nav')->length);
        $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"search=UI-REVIEW") and contains(@href,"status=draft") and contains(@href,"page=1")]')->length);
        $this->assertSame('22', $xp->evaluate('string(//dl[@aria-label="Stock purchase summary"]/div[dt="Drafts Pending"]/dd)'));
    }

    public function test_restricted_inventory_viewer_sees_records_but_not_new_edit_or_delete_actions(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $purchase = TenantContext::runFor($shop->id, fn () => StockPurchase::create([
            'purchase_number' => 'UI-VIEW-ONLY', 'purchase_date' => today(), 'status' => 'draft',
        ]));
        $this->grantOnlyPermissions($owner, ['inventory.view']);
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/inventory/purchases')->assertOk()->getContent());
        $this->assertGreaterThan(0, $xp->query('//a[@href="'.route('inventory.purchases.show', $purchase).'"]')->length);
        $this->assertSame(0, $xp->query('//a[@href="'.route('inventory.purchases.create').'"] | //a[@href="'.route('inventory.purchases.edit', $purchase).'"] | //form[@action="'.route('inventory.purchases.destroy', $purchase).'"]')->length);
        $this->assertSame(1, $xp->query('//*[@data-list-scroll][@tabindex="0"][@role="region"]')->length);
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
