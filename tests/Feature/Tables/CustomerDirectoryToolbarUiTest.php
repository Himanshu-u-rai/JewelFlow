<?php

namespace Tests\Feature\Tables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class CustomerDirectoryToolbarUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_add_customer_remains_one_named_header_link_for_the_mobile_icon_and_desktop_label(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/customers')->assertOk()->getContent());
        $links = $xp->query('//a[contains(@class,"customers-add-btn")]');
        $this->assertSame(1, $links->length);
        $link = $links->item(0);
        $this->assertSame(route('customers.create'), $link->getAttribute('href'));
        $this->assertSame('Add Customer', $link->getAttribute('aria-label'));
        $this->assertSame('Add Customer', trim($xp->evaluate('string(.//span[@class="customers-add-label"])', $link)));
        $this->assertSame(1, $xp->query('.//svg', $link)->length);

        $this->grantOnlyPermissions($owner, ['customers.view']);
        $xp = $this->xpath($this->get('/customers')->assertOk()->getContent());
        $this->assertSame(0, $xp->query('//a[contains(@class,"customers-add-btn")]')->length);
    }

    public function test_directory_status_uses_the_same_dropdown_configuration_as_the_emi_filter(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/customers')->assertOk()->getContent());
        $directory = $xp->query('//select[@name="status"]/ancestor::*[@data-enhance-selects][1]')->item(0);
        $emi = $xp->query('//select[@name="emi_status"]/ancestor::*[@data-enhance-selects][1]')->item(0);
        $this->assertNotNull($directory, 'Directory Status must opt into the existing EMI dropdown, not another native or custom picker.');
        $this->assertNotNull($emi);
        foreach (['data-enhance-selects' => 'true', 'data-enhance-selects-variant' => 'standard', 'data-enhance-selects-placement' => 'viewport'] as $attribute => $value) {
            $this->assertSame($value, $emi->getAttribute($attribute));
            $this->assertSame($value, $directory->getAttribute($attribute));
        }
        $this->assertSame(1, $xp->query('.//select', $directory)->length);
        $this->assertSame(0, $xp->query('.//input[@name="search"]', $directory)->length, 'Enhance Status only; the search disclosure stays independent.');
    }

    public function test_customer_totals_are_present_once_in_the_table_header_outside_scrolling_rows(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer($shop->id, ['email' => 'first@example.test']);
        $this->createCustomer($shop->id, ['email' => null]);
        $this->createCustomer($shop->id, ['email' => 'archived@example.test', 'is_active' => false]);
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/customers')->assertOk()->getContent());
        $summary = $xp->query('//dl[@aria-label="Customer summary"]')->item(0);
        $this->assertNotNull($summary);
        $this->assertSame(1, $xp->query('//dl[@aria-label="Customer summary"]')->length);
        $this->assertSame(1, $xp->query('//div[contains(@class,"customers-directory-head")]//dl[@aria-label="Customer summary"]')->length);
        $this->assertSame(0, $xp->query('//*[@data-list-scroll]//dl[@aria-label="Customer summary"]')->length);
        $directory = $xp->query('//*[@data-list-panel][@x-show="view === \'customers\'"]')->item(0);
        $this->assertSame(0, $xp->query('.//*[contains(@class,"customers-kpi-grid")]', $directory)->length);
        $values = [];
        foreach ($xp->query('./div', $summary) as $metric) {
            $values[trim($xp->evaluate('string(dt)', $metric))] = trim($xp->evaluate('string(dd)', $metric));
        }
        // Keep the incumbent scopes: selected customer total and all invoices.
        $this->assertSame(['Total Customers' => '2', 'Total Purchases' => '0'], $values);
        $this->assertSame(0, $xp->query('//dl[@aria-label="Customer summary"]//dt[text()="With Email"]')->length);
    }

    public function test_expanded_search_preserves_query_status_counts_and_filtered_pagination_for_view_only_users(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['customers.view']);
        for ($i = 0; $i < 17; $i++) {
            $this->createCustomer($shop->id, ['first_name' => 'Toolbar '.$i, 'is_active' => $i !== 16]);
        }
        $this->withoutVite();
        $response = $this->actingAs($owner)->get('/customers?search=Toolbar&status=all&page=2')->assertOk();
        $response->assertDontSee('Add Customer')->assertDontSee('Archive customer');
        $xp = $this->xpath($response->getContent());
        $form = $xp->query('//form[contains(@class,"customers-directory-controls")]')->item(0);
        $this->assertNotNull($form);
        $this->assertSame('GET', $form->getAttribute('method'));
        $this->assertSame(route('customers.index'), $form->getAttribute('action'));
        $toggle = $xp->query('.//button[@data-customer-search-toggle]', $form)->item(0);
        $this->assertNotNull($toggle);
        $this->assertSame('button', $toggle->getAttribute('type'));
        $this->assertSame('true', $toggle->getAttribute('aria-expanded'));
        $panel = $xp->query('//*[@id="'.$toggle->getAttribute('aria-controls').'"]')->item(0);
        $this->assertNotNull($panel);
        $input = $xp->query('.//input[@name="search"]', $panel)->item(0);
        $this->assertSame('Toolbar', $input->getAttribute('value'));
        $this->assertSame('customers-tbody', $input->getAttribute('data-live-filter'));
        $this->assertSame('customers', $input->getAttribute('data-suggest'));
        $this->assertSame(1, $xp->query('//label[@for="'.$input->getAttribute('id').'"]')->length);
        $this->assertSame(1, $xp->query('.//select[@name="status" and @id="customers-status"]', $form)->length);
        $this->assertSame('all', $xp->evaluate('string(.//select[@name="status"]/option[@selected]/@value)', $form));
        $labels = [];
        foreach ($xp->query('.//select[@name="status"]/option', $form) as $option) {
            $labels[] = trim($option->textContent);
        }
        $this->assertSame(['Active (16)', 'Archived (1)', 'All (17)'], $labels);
        $this->assertSame(1, $xp->query('.//button[@type="submit"]', $form)->length);
        $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"status=all") and contains(@href,"search=Toolbar") and contains(@href,"page=1")]')->length);
    }

    public function test_empty_directory_retains_native_form_controls_and_collapsed_search_state(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/customers')->assertOk()->getContent());
        $toggle = $xp->query('//button[@data-customer-search-toggle]')->item(0);
        $this->assertNotNull($toggle);
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
        $this->assertSame('Search customers', $toggle->getAttribute('aria-label'));
        $this->assertSame(1, $xp->query('//input[@name="search" and not(@disabled)]')->length);
        $form = $xp->query('//form[contains(@class,"customers-directory-controls")]')->item(0);
        $this->assertFalse($form->hasAttribute('hidden') || $form->hasAttribute('x-cloak'));
        $this->assertSame(0, $xp->query('.//*[@hidden or @x-cloak][.//input[@name="search"]]', $form)->length, 'The disclosure must not add a JavaScript dependency to its native input; existing tab visibility remains unchanged.');
        $this->assertSame(1, $xp->query('.//button[@type="submit" and not(@hidden) and not(@x-cloak)]', $form)->length);
        $this->assertSame('0', $xp->evaluate('string(//dl[@aria-label="Customer summary"]/div[dt="Total Customers"]/dd)'));
    }

    public function test_manufacturer_summary_keeps_gold_page_scope_instead_of_retail_purchases(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->createCustomer($shop->id);
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/customers')->assertOk()->getContent());
        $this->assertSame('0.000 g', $xp->evaluate('string(//dl[@aria-label="Customer summary"]/div[dt="Gold on This Page"]/dd)'));
        $this->assertSame(0, $xp->query('//dl[@aria-label="Customer summary"]//dt[text()="Total Purchases"]')->length);
    }

    public function test_related_registers_keep_summaries_and_controls_outside_scrolling_rows(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer($shop->id, ['loyalty_points' => 120, 'date_of_birth' => today()->addDays(3)->subYears(30)]);
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/customers')->assertOk()->getContent());
        foreach (['loyalty', 'emi', 'occasions'] as $view) {
            $panel = $xp->query('//*[@data-list-panel][@x-show="view === \''.$view.'\'"]')->item(0);
            $this->assertNotNull($panel);
            $this->assertSame(0, $xp->query('.//*[contains(@class,"customers-kpi-grid")]', $panel)->length);
            $this->assertSame(1, $xp->query('.//*[@data-list-frame]//dl', $panel)->length);
            $this->assertSame($view === 'loyalty' ? 1 : 3, $xp->query('.//*[@data-list-frame]//dl/div/dd', $panel)->length);
            $this->assertSame(0, $xp->query('.//*[@data-list-scroll]//dl', $panel)->length);
            $this->assertSame(1, $xp->query('.//form[contains(@class,"customers-directory-controls")]/input[@name="view" and @value="'.$view.'"]', $panel)->length);
        }
        $this->assertSame('1', $xp->evaluate('string(//dl[@aria-label="Loyalty summary"]/div[dt="Members with Points"]/dd)'));
        $this->assertSame(0, $xp->query('//dl[@aria-label="Loyalty summary"]//dt[text()="Total Issued" or text()="Total Redeemed"]')->length);
        $this->assertSame('1', $xp->evaluate('string(//dl[@aria-label="Occasions summary"]/div[dt="Upcoming"]/dd)'));
    }

    public function test_related_filters_can_be_applied_again_and_keep_active_query_values(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite()->actingAs($owner);
        foreach (['loyalty' => ['loyalty_search', 'Example'], 'emi' => ['emi_status', 'completed'], 'occasions' => ['occasion_days', '60']] as $view => [$name, $value]) {
            $xp = $this->xpath($this->get('/customers?view='.$view.'&'.$name.'='.$value)->assertOk()->getContent());
            $form = $xp->query('//input[@name="view" and @value="'.$view.'"]/ancestor::form[1]')->item(0);
            $this->assertSame('GET', $form->getAttribute('method'));
            $this->assertSame(route('customers.index'), $form->getAttribute('action'));
            $this->assertSame(1, $xp->query('.//button[@type="submit"]', $form)->length, 'An active filter must retain Apply, not replace it with Clear.');
            if ($view === 'loyalty') {
                $toggle = $xp->query('.//button[@data-customer-search-toggle]', $form)->item(0);
                $this->assertNotNull($toggle);
                $this->assertSame('true', $toggle->getAttribute('aria-expanded'));
                $this->assertSame($value, $xp->evaluate('string(.//input[@name="'.$name.'"]/@value)', $form));
            } else {
                $this->assertSame($value, $xp->evaluate('string(.//select[@name="'.$name.'"]/option[@selected]/@value)', $form));
                $this->assertSame(1, $xp->query('.//*[contains(@class,"customers-directory-status")][@data-enhance-selects-placement="viewport"]', $form)->length);
            }
        }
    }

    public function test_each_customer_tab_has_a_mobile_filter_dialog_without_duplicating_its_form(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->withoutVite();
        $xp = $this->xpath($this->actingAs($owner)->get('/customers')->assertOk()->getContent());
        $ids = [];
        foreach (['customers' => ['search', 'status'], 'loyalty' => ['view', 'loyalty_search'], 'emi' => ['view', 'emi_status'], 'occasions' => ['view', 'occasion_days']] as $view => $fields) {
            $panel = $xp->query('//*[@data-list-panel][@x-show="view === \''.$view.'\'"]')->item(0);
            $toggle = $xp->query('.//button[@data-filter-toggle]', $panel)->item(0);
            $this->assertNotNull($toggle, $view.' needs the same mobile filter button as Invoices.');
            $this->assertSame('dialog', $toggle->getAttribute('aria-haspopup'));
            $id = $toggle->getAttribute('aria-controls');
            $this->assertNotContains($id, $ids);
            $ids[] = $id;
            $dialog = $xp->query('.//dialog[@id="'.$id.'"]', $panel)->item(0);
            $this->assertNotNull($dialog);
            $this->assertNotEmpty($dialog->getAttribute('aria-label'));
            $this->assertSame(1, $xp->query('.//form[@method="GET"]', $panel)->length);
            foreach ($fields as $field) {
                $this->assertSame(1, $xp->query('.//input[@name="'.$field.'"] | .//select[@name="'.$field.'"]', $dialog)->length);
            }
        }
        $this->assertSame(0, $xp->query('//dialog//a[@href="'.route('installments.create').'"]')->length, 'Create EMI Plan is an action, not a filter.');
        $this->assertSame(1, $xp->query('//a[@href="'.route('installments.create').'"]')->length);
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
