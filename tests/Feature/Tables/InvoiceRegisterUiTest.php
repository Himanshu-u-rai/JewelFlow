<?php

namespace Tests\Feature\Tables;

use App\Services\InvoiceAccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class InvoiceRegisterUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_only_the_two_invoice_counts_live_in_the_register_header_outside_scrolling_rows(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/invoices')->assertOk()->getContent());
        $this->assertSame(0, $xp->query('//*[contains(@class,"invoices-kpi-grid")]')->length);
        $summary = $xp->query('//dl[@aria-label="Invoice summary"]')->item(0);
        $this->assertNotNull($summary);
        $this->assertSame(1, $xp->query('//div[contains(@class,"invoices-register-head")]//dl[@aria-label="Invoice summary"]')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//dl')->length);
        $this->assertSame(2, $xp->query('./div', $summary)->length);
        $this->assertSame('0', $xp->evaluate('string(div[dt="Total Invoices"]/dd)', $summary));
        $this->assertSame('0', $xp->evaluate('string(div[dt="Today\'s Invoices"]/dd)', $summary));
        $this->assertSame('11', $xp->evaluate('string(//*[@data-list-scroll]//td[@colspan]/@colspan)'));
    }

    public function test_one_header_form_retains_all_invoice_filters_and_named_compact_dropdowns(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $html = $this->actingAs($owner)->get('/invoices?search=Example&from_date=2026-01-01&to_date=2026-12-31&status=draft&payment_mode=cash')->assertOk()->getContent();
        $xp = $this->xpath($html);
        // Query the actual form action, not a JavaScript-only filter mechanism.
        $forms = $xp->query('//form[@method="GET"]');
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertSame(route('invoices.index'), $form->getAttribute('action'));
        foreach (['search' => 'Example', 'from_date' => '2026-01-01', 'to_date' => '2026-12-31'] as $name => $value) {
            $input = $xp->query('.//input[@name="'.$name.'"]', $form)->item(0);
            $this->assertNotNull($input);
            $this->assertSame($value, $input->getAttribute('value'));
            $this->assertSame(1, $xp->query('.//label[@for="'.$input->getAttribute('id').'"]', $form)->length);
        }
        foreach (['status' => 'draft', 'payment_mode' => 'cash'] as $name => $value) {
            $select = $xp->query('.//select[@name="'.$name.'"]', $form)->item(0);
            $this->assertNotNull($select);
            $this->assertSame($value, $xp->evaluate('string(option[@selected]/@value)', $select));
            $this->assertSame(1, $xp->query('./ancestor::*[@data-enhance-selects-placement="viewport"]', $select)->length);
            $this->assertSame(1, $xp->query('.//label[@for="'.$select->getAttribute('id').'"]', $form)->length);
        }
        $toggle = $xp->query('.//button[@data-invoice-search-toggle]', $form)->item(0);
        $this->assertNotNull($toggle);
        $this->assertSame('button', $toggle->getAttribute('type'));
        $this->assertSame('true', $toggle->getAttribute('aria-expanded'));
        $this->assertSame(1, $xp->query('.//button[@type="submit"]', $form)->length);
    }

    public function test_invoice_row_numbers_continue_on_filtered_page_two_and_view_only_actions_remain_safe(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        \App\Support\TenantContext::set($shop->id);
        $this->withoutVite()->actingAs($owner);
        for ($i = 0; $i < 21; $i++) {
            InvoiceAccountingService::createDraft([
                'shop_id' => $shop->id, 'customer_id' => $customer->id,
                'gold_rate' => 0, 'gst_rate' => 3, 'wastage_charge' => 0,
                'discount' => 0, 'round_off' => 0,
            ]);
        }
        $this->grantOnlyPermissions($owner, ['sales.view']);
        $html = $this->actingAs($owner->fresh())->get('/invoices?status=draft&page=2')->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame('21', trim($xp->evaluate('string(//*[@data-list-scroll]//tbody/tr[1]/td[contains(@class,"jf-table-index")])')));
        $this->assertSame('Row #21', trim($xp->evaluate('string(//*[contains(@class,"invoices-mobile-card")]//span[contains(@class,"jf-row-number")])')));
        $this->assertSame(0, $xp->query('//a[@aria-label="Edit invoice"]')->length);
        $this->assertSame(1, $xp->query('//*[@data-list-scroll]//a[@aria-label="View invoice"]')->length);
        $this->assertSame(1, $xp->query('//*[@data-list-scroll]//a[@aria-label="Print invoice"]')->length);
        $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"status=draft") and contains(@href,"page=1")]')->length);
        $this->assertSame('21', $xp->evaluate('string(//dl[@aria-label="Invoice summary"]/div[dt="Total Invoices"]/dd)'));
        $this->assertSame('21', $xp->evaluate('string(//dl[@aria-label="Invoice summary"]/div[dt="Today\'s Invoices"]/dd)'));
    }

    public function test_mobile_filter_button_opens_the_same_filter_form_in_a_named_dialog(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/invoices')->assertOk()->getContent());
        $toggle = $xp->query('//button[@data-invoice-filter-toggle]')->item(0);
        $this->assertNotNull($toggle);
        $this->assertSame('button', $toggle->getAttribute('type'));
        $this->assertSame('dialog', $toggle->getAttribute('aria-haspopup'));
        $dialog = $xp->query('//dialog[@id="'.$toggle->getAttribute('aria-controls').'"]')->item(0);
        $this->assertNotNull($dialog);
        $this->assertSame('Invoice filters', $dialog->getAttribute('aria-label'));
        $this->assertSame(1, $xp->query('.//form[@method="GET"]', $dialog)->length);
        $this->assertSame(5, $xp->query('.//input[@name] | .//select[@name]', $dialog)->length);
        $this->assertSame(1, $xp->query('.//button[@type="button" and @aria-label="Close filters"]', $dialog)->length);
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
