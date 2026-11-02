<?php

namespace Tests\Feature\Tables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class SchemeCreateUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_dates_are_inside_basics_with_the_existing_fields_and_submission_contract(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/schemes/create')->assertOk()->getContent());
        $basics = $xp->query('//section[@id="scheme-basics"]')->item(0);
        $this->assertNotNull($basics);
        foreach (['name', 'type', 'start_date', 'end_date', 'description'] as $field) {
            $this->assertSame(1, $xp->query('.//*[@name="'.$field.'"]', $basics)->length);
        }
        $this->assertSame(0, $xp->query('//h2[text()="Schedule"] | //h3[text()="Schedule"]')->length);
        $this->assertSame(0, $xp->query('//*[contains(@class,"schemes-create-page")]//style')->length);
        $form = $xp->query('//form[@class="schemes-create-form"]')->item(0);
        $this->assertSame(route('schemes.store'), $form->getAttribute('action'));
        $this->assertSame(1, $xp->query('.//input[@name="_token"]', $form)->length);
        $this->assertSame(1, $xp->query('.//button[@type="submit"]', $form)->length);
        $this->assertSame(today()->format('Y-m-d'), $xp->evaluate('string(.//input[@name="start_date"]/@value)', $form));
        $this->assertSame('11', $xp->evaluate('string(.//input[@name="total_installments"]/@value)', $form));
        $this->assertSame('36', $xp->evaluate('string(.//input[@name="total_installments"]/@max)', $form));
        foreach (['total_installments', 'bonus_month_value', 'discount_type', 'discount_value', 'max_discount_amount', 'min_purchase_amount', 'priority', 'max_uses_per_customer', 'applies_to', 'applies_to_value', 'terms'] as $field) {
            $control = $xp->query('.//*[@name="'.$field.'"]', $form)->item(0);
            $this->assertNotNull($control);
            $this->assertSame(1, $xp->query('//label[@for="'.$control->getAttribute('id').'"]')->length);
        }
        foreach (['auto_apply', 'stackable'] as $field) {
            $this->assertSame(1, $xp->query('.//input[@name="'.$field.'" and @type="hidden" and @value="0"]', $form)->length);
            $this->assertSame(1, $xp->query('.//input[@name="'.$field.'" and @type="checkbox" and @value="1"]', $form)->length);
        }
    }

    public function test_validation_state_and_conditional_offer_settings_survive_the_layout_change(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $response = $this->actingAs($owner)->withSession([
            '_old_input' => [
                'name' => 'Synthetic offer', 'type' => 'festival_sale', 'start_date' => '2026-11-01',
                'end_date' => '2026-12-01', 'description' => 'Keep this description', 'terms' => 'Keep these terms',
                'applies_to' => 'category', 'applies_to_value' => 'Rings', 'auto_apply' => 1, 'stackable' => 1,
            ],
            'errors' => (new ViewErrorBag)->put('default', new MessageBag(['name' => ['Synthetic validation message']])),
        ])->get('/schemes/create')->assertOk();
        $response->assertSee('Synthetic validation message');
        $xp = $this->xpath($response->getContent());
        foreach (['name' => 'Synthetic offer', 'start_date' => '2026-11-01', 'end_date' => '2026-12-01', 'applies_to_value' => 'Rings'] as $field => $value) {
            $this->assertSame($value, $xp->evaluate('string(//input[@name="'.$field.'"]/@value)'));
        }
        $this->assertSame('Keep this description', $xp->evaluate('string(//textarea[@name="description"])'));
        $this->assertSame('Keep these terms', $xp->evaluate('string(//textarea[@name="terms"])'));
        foreach (['auto_apply', 'stackable'] as $field) {
            $this->assertSame(1, $xp->query('//input[@type="checkbox" and @name="'.$field.'" and @checked]')->length);
        }
        $this->assertSame(1, $xp->query('//section[@x-show="type === \'gold_savings\'"]')->length);
        $this->assertSame(1, $xp->query('//section[@x-show="type !== \'gold_savings\'"]')->length);
        $state = $xp->query('//form[@x-data]')->item(0)->getAttribute('x-data');
        $this->assertStringContainsString("type: 'festival_sale'", $state);
        $this->assertStringContainsString("appliesTo: 'category'", $state);
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
