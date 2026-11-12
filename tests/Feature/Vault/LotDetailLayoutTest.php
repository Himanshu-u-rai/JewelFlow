<?php

namespace Tests\Feature\Vault;

use App\Models\MetalMovement;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class LotDetailLayoutTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    private function page(): string
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id, 100);
        MetalMovement::record(['shop_id' => $shop->id, 'to_lot_id' => $lot->id,
            'fine_weight' => 5.5, 'type' => 'purchase', 'reference_type' => 'metal_lot',
            'reference_id' => $lot->id, 'user_id' => $owner->id]);
        $this->withoutVite();

        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/vault/lots/'.$lot->id))->assertOk();

        return $response->getContent();
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

    public function test_lot_page_uses_compact_neutral_layout(): void
    {
        $html = $this->page();
        $node = $this->xpath($html)->query('//div[contains(concat(" ", normalize-space(@class), " "), " content-inner ")]')->item(0);
        $this->assertNotNull($node);
        $body = $node->ownerDocument->saveHTML($node);

        foreach (['pink-', 'teal-', 'rose-', 'amber-', 'emerald-', 'text-blue-', 'bg-blue-'] as $accent) {
            $this->assertStringNotContainsString($accent, $body, $accent);
        }
        $this->assertStringContainsString('class="lot-facts"', $html);
    }

    public function test_movement_table_has_row_number_column_and_scrolls_in_wrapper(): void
    {
        $xp = $this->xpath($this->page());

        $this->assertSame('Row number', $xp->evaluate('string(//table[@id="lot-movements-table"]//thead//th[1]/@aria-label)'));
        $this->assertSame(1, $xp->query('//table[@id="lot-movements-table"]//td[contains(@class,"jf-table-index")]')->length);
        $this->assertSame(1, $xp->query('//div[contains(@class,"lot-table-wrap")]//table[@id="lot-movements-table"]')->length);
    }

    public function test_correction_fields_stay_labelled_and_use_standard_button(): void
    {
        $xp = $this->xpath($this->page());

        foreach (['direction' => 'vault-adjust-direction', 'fine_weight' => 'vault-adjust-fine-weight', 'reason' => 'vault-adjust-reason'] as $name => $id) {
            $this->assertSame($id, $xp->evaluate('string(//*[@name="'.$name.'"]/@id)'), $name);
            $this->assertSame(1, $xp->query('//label[@for="'.$id.'"]')->length, $id);
        }
        $btn = $xp->query('//form[contains(@action,"/adjust")]//button[@type="submit"]');
        $this->assertSame(1, $btn->length);
        $this->assertStringContainsString('btn btn-primary btn-sm', $btn->item(0)->getAttribute('class'));
        $this->assertSame(1, $xp->query('//form[contains(@action,"/adjust") and @data-confirm-message]')->length);
    }

    public function test_purchase_vault_line_form_has_no_teal_or_amber_cards(): void
    {
        $src = file_get_contents(base_path('resources/views/inventory/purchases/vault-line.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/\b(?:bg|border|text|ring|focus:ring|focus:border|hover:border|hover:bg)-(?:teal|amber)-/', $src);
        $this->assertStringContainsString('name="vault_action"', $src);
        $this->assertStringContainsString('name="metal_lot_id"', $src);
    }
}
