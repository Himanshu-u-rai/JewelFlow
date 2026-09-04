<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Services\MetalRegistry;
use App\Support\TenantContext;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class HistoricalManualItemGridTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function xpath(string $html): DOMXPath
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML($html));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function calculatedLine(array $override = []): array
    {
        return array_replace([
            'line_calculation_enabled' => '1',
            'line_item_name' => 'Gold Ring',
            'line_metal_type' => 'gold',
            'line_purity' => '22K',
            'line_purity_value' => 22,
            'line_quantity' => 1,
            'line_billable_weight_basis' => HistoricalSalesLine::BILLABLE_WEIGHT_MANUAL,
            'line_billable_weight' => 10,
            'line_billable_weight_mode' => 'manual',
            'line_rate' => 6000,
            'line_metal_value_mode' => 'auto',
            'line_stone_value' => 500,
            'line_stone_value_mode' => 'manual',
            'line_making_basis' => 'fixed_line',
            'line_making_value' => '100',
            'line_making_amount_mode' => 'auto',
            'line_wastage_basis' => HistoricalSalesLine::WASTAGE_BASIS_FLAT,
            'line_wastage_value' => 50,
            'line_wastage_amount_mode' => 'auto',
            'line_hallmark_charge' => 10,
            'line_rhodium_charge' => 20,
            'line_other_charge' => 30,
            'line_discount_type' => HistoricalSalesLine::DISCOUNT_TYPE_FIXED,
            'line_discount_value' => 100,
            'line_discount_amount_mode' => 'auto',
            'line_tax_mode' => 'gst_exclusive',
            'line_gst_rate' => 3,
            'line_total' => 999,
            'line_total_mode' => 'auto',
            'line_notes' => 'Original bill note',
        ], $override);
    }

    private function payload(array $lineOverride = [], array $headerOverride = []): array
    {
        return array_replace([
            'original_document_number' => 'GRID-'.fake()->unique()->numberBetween(1, 999999),
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'grand_total' => 999,
            'grand_total_mode' => 'auto',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'intent' => 'draft',
            'lines' => [$this->calculatedLine($lineOverride)],
        ], $headerOverride);
    }

    public function test_manual_form_has_a_compact_core_grid_separate_advanced_rows_and_mobile_cards(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $xpath = $this->xpath($html);
        $desktop = '//*[@data-historical-item-grid-desktop]';
        $mobile = '//*[@data-historical-item-grid-mobile]';
        $core = "{$desktop}//*[@data-historical-item-core-row]";
        $advanced = "{$desktop}//*[@data-historical-item-advanced-row]";

        $headers = $xpath->query("{$desktop}//thead/tr/th");
        $this->assertNotFalse($headers);
        $this->assertSame(
            ['#', 'Item', 'Metal', 'Purity', 'Qty', 'Billable wt (g)', 'Historical rate (₹/g)', 'Line total (₹)', 'Actions'],
            array_map(
                static fn ($node) => trim(preg_replace('/\s+/', ' ', $node->textContent) ?? ''),
                iterator_to_array($headers)
            )
        );

        foreach (['line_item_name', 'line_metal_type', 'line_purity_value', 'line_quantity', 'line_billable_weight', 'line_rate', 'line_total'] as $field) {
            $this->assertSame(1, $xpath->query("{$core}//*[@x-model='line.{$field}']")?->length, "{$field} must be in the core row.");
            $this->assertSame(0, $xpath->query("{$advanced}//*[@x-model='line.{$field}']")?->length, "{$field} must not be repeated in advanced details.");
        }

        foreach (['line_sku', 'line_hsn', 'line_gross_weight', 'line_net_weight', 'line_stone_weight', 'line_billable_weight_basis', 'line_making_value', 'line_wastage_value', 'line_hallmark_charge', 'line_rhodium_charge', 'line_other_charge', 'line_discount_value', 'line_gst_rate', 'line_notes'] as $field) {
            $this->assertSame(0, $xpath->query("{$core}//*[@x-model='line.{$field}']")?->length, "{$field} must stay out of the compact row.");
            $this->assertSame(1, $xpath->query("{$advanced}//*[@x-model='line.{$field}']")?->length, "{$field} must be available in advanced details.");
        }

        $this->assertSame(1, $xpath->query('//*[@data-historical-item-grid-mobile]//*[@data-historical-item-card]')?->length);
        $this->assertSame(1, $xpath->query("{$mobile}//*[@x-model='line.line_item_name' and @type='text']")?->length);
        foreach (['line_sku', 'line_hsn', 'line_making_label'] as $textField) {
            $this->assertSame(1, $xpath->query("{$advanced}//*[@x-model='line.{$textField}' and @type='text']")?->length);
        }
        $this->assertSame(0, $xpath->query("{$desktop}[contains(concat(' ', normalize-space(@class), ' '), ' overflow-x-auto ')]")?->length);
        $this->assertSame(1, $xpath->query("{$desktop}//table[contains(concat(' ', normalize-space(@class), ' '), ' table-fixed ')]")?->length);
        $this->assertStringContainsString('minimumRows: 1', $html);
        $this->assertStringContainsString('@click="duplicateLine(i)"', $html);
        $this->assertStringContainsString('@click="removeLine(i)"', $html);
        $this->assertStringContainsString('@click="addLine()"', $html);
        $this->assertStringContainsString("matchMedia('(max-width: 1279px)')", file_get_contents(resource_path('js/historical-manual.js')));
    }

    public function test_grid_uses_enabled_metals_and_shop_purity_profiles_including_22k(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        $xpath = $this->xpath($html);
        $this->assertSame(1, $xpath->query("//*[@data-historical-item-grid-desktop]//select[@x-model='line.line_metal_choice']/option[@value='gold']")?->length);
        $this->assertSame(1, $xpath->query("//*[@data-historical-item-grid-desktop]//select[@x-model='line.line_metal_choice']/option[@value='silver']")?->length);
        $this->assertStringContainsString('22K', $html);
        $this->assertStringContainsString('Custom purity', $html);
    }

    public function test_advanced_panel_is_compact_grouped_unit_aware_and_warns_without_blocking(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        foreach (['identity', 'weight-valuation', 'stone-making', 'additional-charges', 'discount-tax', 'notes'] as $group) {
            $this->assertSame(2, $xpath->query("//*[@data-historical-advanced-group='{$group}']")?->length, "{$group} must exist in desktop and mobile Advanced Details.");
        }

        $this->assertStringContainsString('grid-cols-[7rem_minmax(0,1fr)]', $html);
        $this->assertStringContainsString('w-[80px]', $html);
        $this->assertStringContainsString('w-[110px]', $html);
        $this->assertStringContainsString('w-[150px]', $html);
        $this->assertStringContainsString('x-text="makingUnit(line.line_making_basis)"', $html);
        $this->assertStringContainsString('x-text="wastageUnit(line.line_wastage_basis)"', $html);
        $this->assertStringContainsString('x-text="discountUnit(line.line_discount_type)"', $html);
        $this->assertSame(2, $xpath->query('//*[@data-historical-line-outlier-warning and @role="status"]')?->length);
        $this->assertSame(1, $xpath->query("//*[@data-historical-advanced-layout='mobile']//input[@x-model='line.line_sku' and contains(concat(' ', normalize-space(@class), ' '), ' h-11 ')]")?->length);

        $source = file_get_contents(resource_path('js/historical-manual.js'));
        $this->assertIsString($source);
        foreach (['makingUnit(basis)', 'wastageUnit(basis)', 'discountUnit(type)', 'outlierWarnings(line)'] as $method) {
            $this->assertStringContainsString($method, $source);
        }
        $this->assertStringContainsString('Did you intend a flat', $source);
    }

    public function test_grid_reuses_enabled_platinum_hallmark_profiles_and_keeps_custom_fallback(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        DB::table('shop_enabled_metals')->updateOrInsert(
            ['shop_id' => $shop->id, 'metal_type' => 'platinum'],
            ['enabled' => DB::raw('TRUE'), 'created_at' => now(), 'updated_at' => now()],
        );
        MetalRegistry::clearShopCache($shop->id);

        $response = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk();

        $this->assertContains('platinum', $response->viewData('enabledMetals'));
        $this->assertContains(
            ['metal' => 'platinum', 'label' => '95', 'value' => 95.0],
            $response->viewData('purityProfiles'),
        );
        $this->assertStringContainsString('Custom purity', $response->getContent());
    }

    public function test_server_recomputes_tampered_auto_line_and_document_totals_and_persists_inputs_and_state(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->payload())->assertRedirect();

        [$document, $line] = TenantContext::runFor($shop->id, function (): array {
            $document = HistoricalSalesDocument::query()->with('lines')->firstOrFail();

            return [$document, $document->lines->firstOrFail()];
        });

        // 10g × ₹6000 × 22/24 = 55,000; +100 making +50 wastage +500 stone
        // +60 charges -100 discount = 55,610 taxable; +3% GST = 57,278.30.
        $this->assertEqualsWithDelta(57278.30, (float) $line->line_total, 0.01);
        $this->assertEqualsWithDelta(57278.30, (float) $document->grand_total, 0.01);
        $this->assertEqualsWithDelta(55000.00, (float) $line->calculation_state['metal_value']['value'], 0.01);
        $this->assertSame('auto', $line->calculation_state['line_total']['mode']);
        $this->assertSame('auto', $document->calculation_state['grand_total']['mode']);
        $this->assertSame('gold', $line->line_metal_type);
        $this->assertSame('manual', $line->billable_weight_basis);
        $this->assertEqualsWithDelta(10, (float) $line->billable_weight, 0.001);
        $this->assertEqualsWithDelta(10, (float) $line->hallmark_charge, 0.01);
        $this->assertEqualsWithDelta(20, (float) $line->rhodium_charge, 0.01);
        $this->assertEqualsWithDelta(30, (float) $line->other_charge, 0.01);
        $this->assertSame('Original bill note', $line->raw_payload['line_notes']);
    }

    public function test_manual_line_total_survives_source_changes_until_recalculate_restores_auto(): void
    {
        [$owner] = $this->createRetailerTenant();

        $manualPayload = $this->payload([
            'line_total' => 60000,
            'line_total_mode' => 'manual',
        ], [
            'grand_total' => 60000,
            'grand_total_mode' => 'manual',
        ]);

        $manualPayload['lines'][0]['line_rate'] = 6200;
        $manual = $this->actingAs($owner)->post(route('historical.manual.preview'), $manualPayload)->assertOk();
        $manualState = $manual->viewData('lines')[0]['calculation_state']['line_total'];

        $this->assertSame('manual', $manualState['mode']);
        $this->assertSame(60000.0, $manualState['value']);
        $this->assertEqualsWithDelta(59166.63, $manualState['suggestion'], 0.01);
        $form = $this->xpath($manual->getContent())->query("//*[@data-historical-form='manual-preview']")?->item(0);
        $this->assertNotNull($form);
        $this->assertStringContainsString('line_total_mode', $form->attributes?->getNamedItem('x-data')?->nodeValue ?? '');
        $this->assertStringContainsString('manual', $form->attributes?->getNamedItem('x-data')?->nodeValue ?? '');
        $this->assertSame(
            'Manual',
            trim($this->xpath($manual->getContent())->query("//*[@data-historical-line-calculation-mode='1']")?->item(0)?->textContent ?? '')
        );
        $this->assertSame(
            'Manual',
            trim($this->xpath($manual->getContent())->query("//*[@data-historical-calculation-mode='grand_total']")?->item(0)?->textContent ?? '')
        );

        $manualPayload['lines'][0]['line_total_recalculate'] = '1';
        $recalculated = $this->actingAs($owner)->post(route('historical.manual.preview'), $manualPayload)->assertOk();
        $autoState = $recalculated->viewData('lines')[0]['calculation_state']['line_total'];

        $this->assertSame('auto', $autoState['mode']);
        $this->assertEqualsWithDelta(59166.63, $autoState['value'], 0.01);
        $this->assertSame(
            'Auto',
            trim($this->xpath($recalculated->getContent())->query("//*[@data-historical-line-calculation-mode='1']")?->item(0)?->textContent ?? '')
        );
    }
}
