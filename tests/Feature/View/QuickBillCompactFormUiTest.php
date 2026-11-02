<?php

namespace Tests\Feature\View;

use App\Models\QuickBill;
use App\Services\QuickBillService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class QuickBillCompactFormUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_compact_entry_keeps_the_post_contract_labels_and_numeric_precision(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $html = $this->actingAs($owner)->get(route('quick-bills.create'))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(1, $xp->query('//*[contains(@class,"qb-compact-form")]//form[@method="POST" and @action="'.route('quick-bills.store').'"]')->length);
        foreach (['customer_id', 'bill_date', 'customer_name', 'customer_mobile', 'customer_address', 'notes', 'terms'] as $name) {
            $field = $xp->query('//*[@name="'.$name.'"]')->item(0);
            $this->assertNotNull($field);
            $this->assertNotEmpty($field->getAttribute('id'));
            $this->assertSame(1, $xp->query('//label[@for="'.$field->getAttribute('id').'"]')->length);
        }
        foreach (['customer_address', 'notes', 'terms'] as $name) {
            $this->assertSame('2', $xp->evaluate('string(//textarea[@name="'.$name.'"]/@rows)'));
        }
        foreach (['gross_weight' => '0.001', 'stone_weight' => '0.001', 'net_weight' => '0.001', 'rate' => '0.01', 'making_charge' => '0.01', 'stone_charge' => '0.01', 'wastage_percent' => '0.01'] as $field => $step) {
            $input = $xp->query('//*[@x-model.number="item.'.$field.'"]')->item(0);
            $this->assertNotNull($input);
            $this->assertSame($step, $input->getAttribute('step'));
            $this->assertSame('0', $input->getAttribute('min'));
            $this->assertFalse($input->hasAttribute('max'), 'Compact width must not introduce a numeric cap.');
        }
        $this->assertStringContainsString('@input="recalcNet(item)"', $html);
        $this->assertSame(1, $xp->query('//button[@name="save_action" and @value="issue"]')->length);
        $this->assertSame(1, $xp->query('//button[@name="save_action" and @value="draft"]')->length);
        $this->assertSame(1, $xp->query('//*[@role="dialog" and @aria-label="Review and payment"]')->length);
    }

    public function test_edit_and_validation_retry_preserve_existing_and_unsaved_values(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->withoutVite()->actingAs($owner);
        $bill = TenantContext::runFor($shop->id, fn () => app(QuickBillService::class)->create($shop, $owner, [
            'pricing_mode' => 'no_gst', 'customer_name' => 'Synthetic compact edit',
            'notes' => 'Keep original notes', 'terms' => 'Keep original terms',
            'items' => [['description' => 'Synthetic ring', 'gross_weight' => 10, 'stone_weight' => 1, 'net_weight' => 8, 'rate' => 100, 'hallmark_charge' => 40]],
        ]));
        $response = TenantContext::runFor($shop->id, fn () => $this->get(route('quick-bills.edit', $bill))->assertOk());
        $this->assertSame(8.0, (float) $response->viewData('initialItems')[0]['net_weight']);
        $this->assertSame(40.0, (float) $response->viewData('initialItems')[0]['hallmark_charge']);
        $response->assertSee('Keep original notes')->assertSee('Keep original terms');
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//form[@action="'.route('quick-bills.update', $bill).'"]//input[@name="_method" and @value="PUT"]')->length);

        $this->from(route('quick-bills.create'))->post(route('quick-bills.store'), [
            'bill_date' => today()->toDateString(), 'customer_name' => 'Unsaved synthetic customer',
            'notes' => 'Unsaved notes remain', 'terms' => 'Unsaved terms remain',
            'save_action' => 'draft', 'items' => [],
        ])->assertRedirect(route('quick-bills.create'))->assertSessionHasErrors('items');
        $this->get(route('quick-bills.create'))->assertOk()->assertSee('Unsaved notes remain')->assertSee('Unsaved terms remain');
        $this->assertSame(1, QuickBill::withoutTenant()->where('shop_id', $shop->id)->count());
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
