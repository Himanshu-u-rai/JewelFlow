<?php

namespace Tests\Feature\Historical;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * UI audit F12 — Historical entry/import screens. Layout/markup only: the
 * manual line table gets its own scroll wrapper and minimum widths instead of
 * being squeezed, the customer search uses the themed control, every visible
 * control is labelled, and Bulk Imports gets labelled uploads plus a row-number
 * column in its history table.
 */
class HistoricalScreensLayoutTest extends TestCase
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
        $doc = new DOMDocument;
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return new DOMXPath($doc);
    }

    private function hasClass(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    public function test_historical_and_imports_screens_render(): void
    {
        [$owner] = $this->createRetailerTenant();

        foreach (['historical.index', 'historical.manual.create', 'historical.upload.create'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }
        $this->actingAs($owner)->get(route('imports.index'))->assertOk();
    }

    public function test_manual_line_table_sits_in_its_own_scroll_wrapper_with_minimum_widths(): void
    {
        [$owner] = $this->createRetailerTenant();
        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $wrapper = $xpath->query("//*[@data-historical-item-grid-desktop]//*[@data-historical-line-scroll and ".$this->hasClass('historical-line-scroll')."]/table[@data-historical-item-table]");
        $this->assertSame(1, $wrapper->length, 'The line table must sit directly inside the scroll wrapper.');

        $this->assertStringContainsString('.historical-line-scroll', $html);
        $this->assertMatchesRegularExpression('/historical-line-scroll\s*>\s*table\[data-historical-item-table\]\s*\{[^}]*min-width:\s*68rem/', $html);
        $this->assertMatchesRegularExpression('/\.historical-line-select\s*\{\s*min-width:/', $html);

        $this->assertGreaterThan(0, $xpath->query("//*[@data-historical-item-core-row]//select[".$this->hasClass('historical-line-select')."]")->length, 'Metal/purity selects need the min-width class.');
        $this->assertGreaterThan(0, $xpath->query("//*[@data-historical-item-core-row]//input[".$this->hasClass('historical-line-total')."]")->length, 'Line total needs the min-width class.');
        $this->assertSame(1, $xpath->query("//*[@data-historical-amount-grid]")->length, 'Amount fields use the auto-fit grid.');
    }

    public function test_customer_search_uses_the_themed_control(): void
    {
        [$owner] = $this->createRetailerTenant();
        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $search = $xpath->query("//input[@type='search']");
        $this->assertGreaterThan(0, $search->length);
        foreach ($search as $input) {
            $this->assertStringContainsString('jf-control', $input->getAttribute('class'), 'Search inputs must use the themed jf-control class.');
        }
    }

    public function test_every_visible_manual_form_control_is_labelled(): void
    {
        [$owner] = $this->createRetailerTenant();
        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $labelFor = [];
        foreach ($xpath->query('//label') as $label) {
            /** @var DOMElement $label */
            foreach (['for', ':for'] as $attr) {
                if ($label->hasAttribute($attr)) {
                    $labelFor[$attr.'='.$label->getAttribute($attr)] = true;
                }
            }
        }

        $unlabelled = [];
        foreach ($xpath->query('//form[@data-historical-form="manual"]//input[not(@type="hidden")] | //form[@data-historical-form="manual"]//select | //form[@data-historical-form="manual"]//textarea') as $control) {
            /** @var DOMElement $control */
            $type = $control->getAttribute('type');
            if (in_array($type, ['submit', 'button'], true)) {
                continue;
            }
            if ($control->hasAttribute('aria-label') || $control->hasAttribute(':aria-label') || $control->hasAttribute('aria-labelledby')) {
                continue;
            }
            $id = $control->getAttribute('id');
            if ($id !== '' && isset($labelFor['for='.$id])) {
                continue;
            }
            $dynamicId = $control->getAttribute(':id');
            if ($dynamicId !== '' && isset($labelFor[':for='.$dynamicId])) {
                continue;
            }
            $inLabel = false;
            for ($p = $control->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
                if ($p->tagName === 'label') {
                    $inLabel = true;
                    break;
                }
            }
            if (! $inLabel) {
                $unlabelled[] = $control->tagName.'#'.($id ?: $dynamicId ?: $control->getAttribute('name').$control->getAttribute(':name'));
            }
        }

        $this->assertSame([], $unlabelled, 'Controls without a label association or aria-label: '.implode(', ', $unlabelled));
    }

    public function test_bulk_imports_history_has_a_row_number_column_and_labelled_uploads(): void
    {
        [$owner] = $this->createRetailerTenant();
        $html = $this->actingAs($owner)->get(route('imports.index'))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $headers = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' imports-history-table ')]//thead/tr/th");
        $this->assertGreaterThan(1, $headers->length);
        $first = $headers->item(0);
        $this->assertSame('Row number', $first->getAttribute('aria-label'));
        $this->assertSame('#', trim($first->textContent));
        $this->assertStringContainsString('jf-table-index', $first->getAttribute('class'));

        // Empty state spans every column, index included.
        $empty = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' imports-history-table ')]//tbody//td[@colspan]");
        $this->assertSame(1, $empty->length);
        $this->assertSame((string) $headers->length, $empty->item(0)->getAttribute('colspan'));

        foreach ($xpath->query("//input[@type='file']") as $file) {
            $id = $file->getAttribute('id');
            $this->assertNotSame('', $id, 'File inputs need an id.');
            $this->assertSame(1, $xpath->query("//label[@for='{$id}']")->length, "File input #{$id} needs an associated label.");
        }
    }
}
