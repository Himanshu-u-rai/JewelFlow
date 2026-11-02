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
        $this->assertSame(0, $xp->query('//button[@data-confirm-url] | //dialog[@id="purchase-confirm-dialog"]')->length);
    }

    public function test_only_drafts_have_named_confirmation_icons_and_one_unclipped_patch_dialog(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $draft = TenantContext::runFor($shop->id, function () {
            $draft = StockPurchase::create([
                'purchase_number' => 'UI-DRAFT-1', 'supplier_name' => 'Synthetic <supplier>',
                'purchase_date' => today(), 'status' => 'draft', 'total_amount' => 1234.50,
            ]);
            foreach (['confirmed', 'stocked'] as $status) {
                StockPurchase::create(['purchase_number' => 'UI-'.$status, 'purchase_date' => today(), 'status' => $status]);
            }

            return $draft;
        });
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/inventory/purchases')->assertOk()->getContent());
        $buttons = $xp->query('//button[@data-confirm-url]');
        $this->assertSame(2, $buttons->length, 'One desktop and one phone action for the draft only.');
        foreach ($buttons as $button) {
            $this->assertSame('button', $button->getAttribute('type'));
            $this->assertSame('Confirm draft', $button->getAttribute('data-tip'));
            $this->assertSame('dialog', $button->getAttribute('aria-haspopup'));
            $this->assertSame('purchase-confirm-dialog', $button->getAttribute('aria-controls'));
            $this->assertSame('Confirm draft UI-DRAFT-1', $button->getAttribute('aria-label'));
            $this->assertSame(route('inventory.purchases.confirm', $draft), $button->getAttribute('data-confirm-url'));
            $this->assertSame('UI-DRAFT-1', $button->getAttribute('data-number'));
            $this->assertSame('Synthetic <supplier>', $button->getAttribute('data-supplier'));
            $this->assertSame('₹1,234.50', $button->getAttribute('data-total'));
            $this->assertSame(1, $xp->query('preceding-sibling::a[1][@href="'.route('inventory.purchases.show', $draft).'"]', $button)->length);
        }
        $this->assertSame(1, $xp->query('//dialog[@id="purchase-confirm-dialog"]')->length);
        $dialog = $xp->query('//dialog[@id="purchase-confirm-dialog"]')->item(0);
        $this->assertNotNull($dialog);
        $this->assertFalse($dialog->hasAttribute('open'));
        $this->assertSame(0, $xp->query('ancestor::*[@data-list-scroll or @data-list-frame]', $dialog)->length);
        $this->assertSame('purchase-confirm-title', $dialog->getAttribute('aria-labelledby'));
        $this->assertSame('POST', $xp->evaluate('string(.//form/@method)', $dialog));
        $this->assertSame('PATCH', $xp->evaluate('string(.//input[@name="_method"]/@value)', $dialog));
        $this->assertSame(1, $xp->query('.//input[@name="_token"]', $dialog)->length);
        $this->assertSame('Cancel', trim($xp->evaluate('string(.//button[@autofocus])', $dialog)));
        $this->assertSame(1, $xp->query('.//button[@type="submit"]', $dialog)->length);
        $this->assertSame('draft', $draft->fresh()->status, 'Opening the register must not confirm anything.');
    }

    public function test_confirmation_uses_existing_endpoint_without_stocking_and_rejects_repeat_confirmation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $draft = TenantContext::runFor($shop->id, function () use ($shop) {
            $draft = StockPurchase::create(['purchase_number' => 'UI-CONFIRM', 'purchase_date' => today(), 'status' => 'draft']);
            $draft->lines()->create([
                'shop_id' => $shop->id, 'line_type' => 'ornament', 'metal_type' => 'gold',
                'purity' => 22, 'gross_weight' => 5, 'net_metal_weight' => 5, 'purchase_line_amount' => 1000,
            ]);

            return $draft;
        });
        $itemCount = \App\Models\Item::withoutGlobalScopes()->count();
        $url = route('inventory.purchases.confirm', $draft);
        TenantContext::set($shop->id);
        $this->actingAs($owner)->patch($url)->assertRedirect(route('inventory.purchases.show', $draft));
        $this->assertSame('confirmed', $draft->fresh()->status);
        $this->assertSame('1000.00', $draft->fresh()->total_amount);
        $this->assertSame($itemCount, \App\Models\Item::withoutGlobalScopes()->count());
        $this->assertNull($draft->lines()->first()->item_id);
        TenantContext::set($shop->id);
        $this->patch($url)->assertRedirect()->assertSessionHas('error');
        $this->assertSame(1, \App\Models\AuditLog::withoutGlobalScopes()->where('action', 'purchase_confirmed')->where('model_id', $draft->id)->count());
    }

    public function test_confirmation_endpoint_rejects_cross_shop_and_view_only_requests(): void
    {
        [, $otherShop] = $this->createRetailerTenant();
        $foreign = TenantContext::runFor($otherShop->id, fn () => StockPurchase::create([
            'purchase_number' => 'UI-FOREIGN', 'purchase_date' => today(), 'status' => 'draft',
        ]));
        [$viewer, $shop] = $this->createRetailerTenant();
        $local = TenantContext::runFor($shop->id, fn () => StockPurchase::create([
            'purchase_number' => 'UI-LOCAL', 'purchase_date' => today(), 'status' => 'draft',
        ]));
        TenantContext::set($shop->id);
        $this->assertContains($this->actingAs($viewer)->patch(route('inventory.purchases.confirm', $foreign))->status(), [403, 404]);
        $this->grantOnlyPermissions($viewer, ['inventory.view']);
        $this->patch(route('inventory.purchases.confirm', $local))->assertForbidden();
        $this->assertSame('draft', $local->fresh()->status);
        $this->assertSame('draft', StockPurchase::withoutGlobalScopes()->findOrFail($foreign->id)->status);
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
