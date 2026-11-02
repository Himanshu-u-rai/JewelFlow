<?php

namespace Tests\Feature\Tables;

use App\Models\StockPurchase;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class PurchaseCreateUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_compact_create_keeps_four_steps_one_navigation_and_labeled_fields(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/inventory/purchases/create')->assertOk()->getContent());
        $root = $xp->query('//*[contains(concat(" ", @class, " "), " purchase-entry-page ")]')->item(0);
        $this->assertNotNull($root);
        $form = $xp->query('.//form[@id="purchase-create-form"]', $root)->item(0);
        $this->assertSame('POST', $form->getAttribute('method'));
        $this->assertSame(route('inventory.purchases.store'), $form->getAttribute('action'));
        $this->assertSame('multipart/form-data', $form->getAttribute('enctype'));
        $this->assertSame(1, $xp->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame(4, $xp->query('.//section[@x-show]', $form)->length);
        $this->assertSame(1, $xp->query('.//nav[@aria-label="Purchase steps"]', $form)->length);
        $this->assertSame(1, $xp->query('.//template[@x-for="step in steps"]', $form)->length);
        foreach ($xp->query('.//input[not(@type="hidden") and not(@type="checkbox")] | .//select | .//textarea', $form) as $field) {
            $id = $field->getAttribute('id');
            if ($id !== '') {
                $this->assertGreaterThan(0, $xp->query('.//label[@for="'.$id.'"]', $form)->length, $id);
            } else {
                $binding = $field->getAttribute(':id');
                $this->assertNotEmpty($binding, $field->getAttribute('name').$field->getAttribute(':name'));
                $labels = iterator_to_array($xp->query('.//label', $form));
                $this->assertNotEmpty(array_filter($labels, fn ($label) => $label->getAttribute(':for') === $binding), $binding);
            }
        }
    }

    public function test_old_input_errors_and_attachment_contract_survive_the_layout_change(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $response = $this->actingAs($owner)->withSession([
            '_old_input' => ['supplier_name' => 'Synthetic supplier', 'invoice_number' => 'DEMO-OLD', 'purchase_date' => '2026-11-01', 'notes' => 'Keep these notes', 'save_as_vendor' => 1],
            'errors' => (new ViewErrorBag)->put('default', new MessageBag(['purchase_date' => ['Synthetic validation error']])),
        ])->get('/inventory/purchases/create')->assertOk()->assertSee('Synthetic validation error');
        $xp = $this->xpath($response->getContent());
        foreach (['supplier_name' => 'Synthetic supplier', 'invoice_number' => 'DEMO-OLD', 'purchase_date' => '2026-11-01'] as $name => $value) {
            $this->assertSame($value, $xp->evaluate('string(//input[@name="'.$name.'"]/@value)'));
        }
        $this->assertSame('Keep these notes', trim($xp->evaluate('string(//textarea[@name="notes"])')));
        $this->assertSame(1, $xp->query('//input[@name="save_as_vendor"][@checked]')->length);
        $this->assertSame('image/jpeg,image/png,application/pdf', $xp->evaluate('string(//input[@name="invoice_image"]/@accept)'));
        $this->assertSame('100', $xp->evaluate('string(//input[@name="cgst_rate"]/@max)'));
    }

    public function test_shared_edit_form_keeps_update_method_and_existing_item_data(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $purchase = TenantContext::runFor($shop->id, function () use ($shop) {
            $purchase = StockPurchase::create(['purchase_number' => 'DEMO-EDIT', 'purchase_date' => today(), 'status' => 'draft', 'invoice_number' => 'SYNTHETIC-INV']);
            $purchase->lines()->create(['shop_id' => $shop->id, 'line_type' => 'ornament', 'design' => 'Synthetic edited ring', 'metal_type' => 'gold', 'purity' => 22, 'gross_weight' => 5, 'net_metal_weight' => 5]);

            return $purchase;
        });
        $this->withoutVite();
        TenantContext::set($shop->id);
        $xp = $this->xpath($this->actingAs($owner)->get(route('inventory.purchases.edit', $purchase))->assertOk()->getContent());
        $this->assertSame(route('inventory.purchases.update', $purchase), $xp->evaluate('string(//form[@id="purchase-create-form"]/@action)'));
        $this->assertSame('PUT', $xp->evaluate('string(//form[@id="purchase-create-form"]//input[@name="_method"]/@value)'));
        $this->assertSame('SYNTHETIC-INV', $xp->evaluate('string(//input[@name="invoice_number"]/@value)'));
        $root = $xp->query('//*[contains(@class,"purchase-entry-page")]')->item(0);
        $this->assertNotNull($root);
        $state = $root->getAttribute('x-data');
        $this->assertStringContainsString('Synthetic edited ring', $state);
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
