<?php

namespace Tests\Feature\Tables;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class TableConsistencyUiTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function forfeitureReport(bool $populated, int $total = 1): \DOMXPath
    {
        $shop = $this->createShop('dhiran');
        $owner = $this->createOwnerUser($shop, $this->createOwnerRole($shop->id));
        $this->actingAs($owner);

        // Unsaved financial snapshots: this presentation test creates no loan or ledger entries.
        $loan = new DhiranLoan([
            'loan_number' => 'TABLE-REVIEW-001', 'principal_amount' => 12345.67,
            'outstanding_principal' => 12000, 'outstanding_interest' => 345.67,
            'outstanding_penalty' => 5, 'forfeited_at' => '2026-10-31',
            'forfeiture_notice_sent_at' => '2026-10-01',
        ]);
        $loan->id = 123;
        $loan->setRelation('customer', new Customer(['first_name' => 'Table', 'last_name' => 'Example']));
        $loan->setRelation('items', collect([new DhiranLoanItem(), new DhiranLoanItem()]));
        $loans = new LengthAwarePaginator($populated ? [$loan] : [], $populated ? $total : 0, 20, 1, [
            'path' => url('/dhiran/reports/forfeiture'),
        ]);
        $html = TenantContext::runFor($shop->id, function () use ($loans) {
            view()->share('errors', new ViewErrorBag());
            return view('dhiran.reports.forfeiture', compact('loans'))->render();
        });
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    public function test_forfeiture_uses_shared_table_without_changing_values_or_alignment(): void
    {
        $xp = $this->forfeitureReport(true);
        $this->assertSame(1, $xp->query('//div[contains(@class,"jf-table-wrap")]/table[contains(@class,"jf-table")]')->length);
        foreach ([3, 4] as $column) {
            $this->assertSame(1, $xp->query('//table/thead/tr/th['.$column.'][contains(@class,"text-right")]')->length);
            $this->assertSame(1, $xp->query('//table/tbody/tr/td['.$column.'][contains(@class,"text-right")]')->length);
        }
        $this->assertSame('12,345.67', trim($xp->query('//table/tbody/tr/td[3]')->item(0)->textContent));
        $this->assertSame('12,350.67', trim($xp->query('//table/tbody/tr/td[4]')->item(0)->textContent));
        $this->assertSame('2', trim($xp->query('//table/tbody/tr/td[5][contains(@class,"text-center")]')->item(0)->textContent));
        $this->assertSame(1, $xp->query('//table/tbody/tr/td/a[contains(@href,"/dhiran/loans/123")]')->length);
    }

    public function test_forfeiture_empty_state_uses_shared_seven_column_cell(): void
    {
        $xp = $this->forfeitureReport(false);
        $this->assertSame(1, $xp->query('//td[@colspan="7" and contains(@class,"jf-table-empty") and normalize-space(.)="No forfeited loans found"]')->length);
    }

    public function test_forfeiture_exposes_existing_server_pagination_when_more_rows_exist(): void
    {
        $xp = $this->forfeitureReport(true, 21);
        $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"page=2")]')->length);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    public function test_view_only_customer_actions_do_not_advertise_forbidden_mutations(): void
    {
        [$viewer, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->grantOnlyPermissions($viewer, ['customers.view']);

        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get('/customers'))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//table//a[contains(@href,"/customers/'.$customer->id.'") and @data-tip="View"]')->length);
        $this->assertSame(0, $xp->query('//table//a[@data-tip="Edit"]')->length);
        $this->assertSame(0, $xp->query('//table//button[@data-tip="Archive" or @data-tip="Reactivate"]')->length);
        TenantContext::runFor($shop->id, fn () => $this->get('/customers/'.$customer->id.'/edit'))->assertForbidden();
    }

    public function test_customer_pagination_keeps_filters_and_exposes_distinct_real_pages(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        for ($i = 1; $i <= 31; $i++) {
            $this->createCustomer($shop->id, [
                'first_name' => 'TableReview', 'last_name' => sprintf('%02d', $i),
                'created_at' => now()->subMinutes($i),
            ]);
        }
        $this->createCustomer($shop->id, ['first_name' => 'Excluded', 'is_active' => false]);
        $pages = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
                ->get('/customers?search=TableReview&status=active&page='.$page))->assertOk();
            $xp = $this->xpath($response->getContent());
            $pages[] = array_map(fn ($node) => $node->getAttribute('href'), iterator_to_array($xp->query('//table//a[@data-tip="View"]')));
            $this->assertSame($page === 3 ? 1 : 15, count($pages[$page - 1]));
            $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"search=TableReview") and contains(@href,"status=active")]')->length);
        }
        $this->assertCount(31, array_unique(array_merge(...$pages)));
    }

    public function test_repairs_keep_billing_explicit_and_gate_mutating_controls(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $repair = TenantContext::runFor($shop->id, fn () => Repair::create([
            'customer_id' => $customer->id, 'repair_number' => 1,
            'item_description' => 'Synthetic chain clasp', 'gross_weight' => 5,
            'metal_type' => 'gold', 'status' => 'ready',
        ]));
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//table//button[contains(@onclick,"openDeliverModal('.$repair->id.'") and not(contains(@class,"jf-row-action")) and contains(normalize-space(.),"Complete & bill")]')->length);
        $this->assertSame(1, $xp->query('//table//select[@name="status"]')->length);
        $this->assertSame(1, $xp->query('//table/tbody/tr/td[7]/form/select[@name="status"]')->length);
        $this->assertSame(0, $xp->query('//table/tbody/tr/td[last()]//select')->length);
        $this->assertSame(2, $xp->query('//button[contains(@onclick,"openDeliverModal") and contains(normalize-space(.),"Complete & bill")]')->length);

        $this->grantOnlyPermissions($owner, ['repairs.view']);
        $owner->unsetRelation('role');
        $response = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(0, $xp->query('//table//select[@name="status"] | //table//button[contains(@onclick,"openDeliverModal")] | //table//a[@data-tip="Edit"]')->length);
        $this->assertSame(1, $xp->query('//table//a[@data-tip="View"]')->length);
    }

    public function test_pos_grid_action_columns_and_remove_controls_have_meaningful_names(): void
    {
        $xp = $this->xpath(file_get_contents(resource_path('views/pos_customer_retailer.blade.php')));
        $this->assertSame(2, $xp->query('//table[contains(@class,"jf-grid")]/thead/tr/th[last()][normalize-space(.)="Actions"]')->length);
        $this->assertSame(3, $xp->query('//button[(contains(@class,"items-table-remove") or contains(@class,"items-mobile-remove")) and @aria-label="Remove item"]')->length);
    }

    public function test_legacy_table_button_sizing_does_not_override_shared_action_targets(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertTrue(str_contains($css, '.content-inner table:not(.jf-table) td button.btn'),
            'The legacy 30px button rule must not shrink shared 36px/44px row actions.');
    }

    public function test_repair_status_has_one_keyboard_focus_indicator_without_the_global_halo(): void
    {
        $view = file_get_contents(resource_path('views/repairs.blade.php'));
        $this->assertStringContainsString('.content-inner .repairs-status-select:focus-visible', $view);
        $this->assertMatchesRegularExpression('/\.content-inner \.repairs-status-select:focus\s*\{[^}]*box-shadow:\s*none\s*!important;/s', $view);
        $this->assertMatchesRegularExpression('/\.content-inner \.repairs-status-select:focus-visible\s*\{[^}]*outline:\s*2px solid var\(--r-accent\);/s', $view);
    }

    public function test_repair_search_submit_does_not_jump_when_the_button_hover_transform_runs(): void
    {
        $view = file_get_contents(resource_path('views/repairs.blade.php'));
        $this->assertMatchesRegularExpression('/\.repairs-search-submit,\s*\.repairs-search-submit:hover,\s*\.repairs-search-submit:active\s*\{\s*transform:\s*none;/s', $view);
    }

    public function test_repairs_has_horizontal_intake_above_the_register_without_kpi_cards(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))
            ->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(0, $xp->query('//*[contains(@class,"repairs-kpi-card")]')->length);
        $this->assertSame(1, $xp->query('//div[contains(@class,"repairs-form-column")]/following-sibling::div[contains(@class,"repairs-register-column")]')->length);
        $this->assertSame(0, $xp->query('//div[contains(@class,"repairs-layout") and contains(@class,"lg:grid-cols-3")]')->length);
        $this->assertSame(1, $xp->query('//form[contains(@class,"repairs-form") and @method="POST" and @enctype="multipart/form-data"]')->length);
        foreach (['customer_id', 'item_description', 'description', 'metal_type', 'gross_weight', 'purity', 'item_quantity', 'due_date', 'intake_condition', 'image', 'estimated_cost'] as $name) {
            $this->assertSame(1, $xp->query('//form[contains(@class,"repairs-form")]//*[@name="'.$name.'"]')->length, $name.' stays in the existing intake form');
        }
        $this->assertSame(1, $xp->query('//nav[@aria-label="Repair list views"]//a[contains(@href,"status=delivered")]')->length);
        $this->assertSame(1, $xp->query('//div[contains(@class,"repairs-card-header")]/div[contains(@class,"repairs-register-toolbar")]/nav[@aria-label="Repair list views"]')->length);
        $this->assertSame(1, $xp->query('//div[contains(@class,"repairs-register-toolbar")]//input[@id="repairsSearchInput"]')->length);
        $this->assertStringContainsString('grid-template-columns: repeat(12, minmax(0, 1fr));', $html);

        $this->grantOnlyPermissions($owner, ['repairs.view']);
        $owner->unsetRelation('role');
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))
            ->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(0, $xp->query('//form[contains(@class,"repairs-form")]')->length);
        $this->assertSame(1, $xp->query('//table[contains(@class,"repairs-table")]')->length);
        $this->assertSame(1, $xp->query('//nav[@aria-label="Repair list views"]//a[contains(@href,"status=delivered")]')->length);
    }

    public function test_repairs_numbers_continue_on_page_two_without_a_nested_vertical_scroller(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        TenantContext::runFor($shop->id, function () use ($customer) {
            for ($i = 1; $i <= 26; $i++) {
                (new Repair())->forceFill([
                    'customer_id' => $customer->id, 'item_description' => 'IndexReview '.$i,
                    'gross_weight' => 5, 'metal_type' => 'gold', 'status' => 'received',
                    'created_at' => now()->subSeconds($i),
                ])->save();
            }
        });
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/repairs?search=IndexReview&page=2'))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(8, $xp->query('//table/thead/tr/th')->length);
        $this->assertSame(10, $xp->query('//tbody[@id="repairsTbody"]/tr[@data-search]')->length);
        $this->assertSame('11', trim($xp->query('//tbody[@id="repairsTbody"]/tr[@data-search]/td[1]')->item(0)->textContent));
        $this->assertSame(2, $xp->query('//*[@data-repair-index="11"]')->length, 'desktop and phone use the paginator offset');
        $this->assertSame(10, $xp->query('//tr[@data-search and not(@onclick) and not(@role)]//a[contains(@class,"repairs-row-view")]')->length);
        $this->assertSame(1, $xp->query('//form[contains(@class,"repairs-register-search") and @method="GET"]//input[@name="search" and @value="IndexReview"]')->length);
        $this->assertGreaterThan(0, $xp->query('//nav//a[contains(@href,"search=IndexReview") and contains(@href,"page=3")]')->length);
        $this->assertSame(0, $xp->query('//div[contains(@class,"repairs-data-table-shell") and contains(@style,"height:")]')->length);
        $this->assertStringContainsString('scrollbar-width: thin;', $html);
        $this->assertSame(1, $xp->query('//tr[@id="repairsNoMatchRow"]/td[@colspan="8"]')->length);

        $json = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->getJson('/repairs?search=IndexReview'))->assertOk();
        $json->assertJsonPath('per_page', 25)->assertJsonCount(25, 'data');
    }

    public function test_repair_placeholders_reuse_the_readable_muted_color(): void
    {
        $view = file_get_contents(resource_path('views/repairs.blade.php'));
        foreach (['.r-input::placeholder, .r-textarea::placeholder', '.r-dd-trigger .r-dd-placeholder', '.r-dd-search::placeholder'] as $selector) {
            $this->assertMatchesRegularExpression('/'.preg_quote($selector, '/').'\s*\{\s*color:\s*var\(--r-muted\);/', $view);
        }
    }

    public function test_repair_optional_hints_describe_their_controls_without_expanding_labels(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))->assertOk()->getContent();
        $xp = $this->xpath($html);
        foreach (['purityDdTrigger', 'item_quantity', 'due_date', 'repair_image', 'intake_condition'] as $id) {
            $control = $xp->query('//*[@id="'.$id.'"]')->item(0);
            $hintId = $control->getAttribute('aria-describedby');
            $this->assertNotEmpty($hintId, $id.' needs an associated optional/unit hint');
            $hint = $xp->query('//*[@id="'.$hintId.'" and not(ancestor::label)]');
            $this->assertSame(1, $hint->length, $id.' hint must not stretch its field label');
            $this->assertStringContainsString('optional', strtolower($hint->item(0)->textContent));
        }
        $this->assertStringContainsString('pieces', strtolower($xp->query('//*[@id="item_quantity_hint"]')->item(0)->textContent));
        $this->assertStringContainsString("customer's challan", $xp->query('//*[@id="intake_condition_hint"]')->item(0)->textContent);
    }

    public function test_repair_photo_chooser_explains_supported_types_and_size_without_replacing_native_upload(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(1, $xp->query('//form[contains(@class,"repairs-form")]//input[@type="file" and @name="image" and @id="repair_image" and @accept="image/jpeg,image/png,image/webp" and @onchange="previewRepairImage(event)"]')->length);
        $hint = $xp->query('//*[@id="repair_image_hint"]')->item(0);
        $this->assertNotNull($hint);
        foreach (['JPEG', 'PNG', 'WebP', '5 MB', 'optional'] as $guidance) {
            $this->assertStringContainsString($guidance, $hint->textContent);
        }
        $this->assertSame(1, $xp->query('//*[@id="repair_image_preview"]//img[@id="repair_image_preview_img"]')->length);
        $this->assertSame(1, $xp->query('//*[@id="repair_image_preview"]//button[@type="button" and @onclick="clearRepairImage()"]')->length);
    }

    public function test_selected_repair_photo_stays_a_bounded_thumbnail_beside_the_chooser(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(1, $xp->query('//div[contains(@class,"repairs-photo-controls")]/input[@id="repair_image"]/following-sibling::*[@id="repair_image_preview"]')->length,
            'The preview belongs beside the chooser, not in a new full-height grid row.');
        $preview = $xp->query('//img[@id="repair_image_preview_img"]')->item(0);
        $this->assertGreaterThan(0, (int) $preview->getAttribute('width'));
        $this->assertLessThanOrEqual(64, (int) $preview->getAttribute('width'));
        $this->assertGreaterThan(0, (int) $preview->getAttribute('height'));
        $this->assertLessThanOrEqual(64, (int) $preview->getAttribute('height'));
        $this->assertSame(1, $xp->query('//*[@id="repair_image_preview"]//button[@type="button" and @aria-label="Remove selected item photo" and @onclick="clearRepairImage()"]')->length);
    }

    public function test_repair_picker_triggers_announce_their_field_and_popup_state(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createCustomer($shop->id);
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))->assertOk()->getContent();
        $xp = $this->xpath($html);
        foreach (['cust' => 'Customer', 'metal' => 'Metal', 'purity' => 'Purity'] as $id => $name) {
            $this->assertSame(1, $xp->query('//button[@id="'.$id.'DdTrigger" and @aria-controls="'.$id.'DdPanel" and @aria-expanded="false" and starts-with(@aria-label,"'.$name.':")]')->length);
            $this->assertSame(1, $xp->query('//label[@for="'.$id.'DdTrigger"]')->length);
        }
        $this->assertSame(5, $xp->query('//button[@type="button" and contains(@class,"r-dd-opt")]')->length);
        $this->assertSame(1, $xp->query('//input[@id="custom_purity" and @aria-labelledby="purityLabelText"]')->length);
        $this->assertStringContainsString("const opt = document.createElement('button');", $html);
        $this->assertStringContainsString("opt.type = 'button';", $html);
    }

    public function test_repair_delivery_uses_a_named_native_dialog_without_changing_billing_fields(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs'))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(1, $xp->query('//dialog[@id="deliverModal" and @aria-modal="true" and @aria-labelledby="repairDeliveryTitle"]')->length);
        $this->assertSame(1, $xp->query('//dialog//*[@id="repairDeliveryTitle" and normalize-space(.)="Deliver Repair Item"]')->length);
        $this->assertSame(1, $xp->query('//dialog//button[@type="button" and @aria-label="Close delivery dialog"]')->length);
        $this->assertSame(1, $xp->query('//dialog//button[@autofocus and @type="button" and contains(normalize-space(.),"Cancel")]')->length);
        foreach (['amount', 'include_gst', 'gst_rate', '_token'] as $name) {
            $this->assertGreaterThan(0, $xp->query('//dialog//form[@id="deliverForm" and @method="POST"]//input[@name="'.$name.'"]')->length);
        }
    }

    private function repairDetailFixture(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id, ['first_name' => 'Synthetic detail', 'mobile' => null]);
        $repair = TenantContext::runFor($shop->id, fn () => Repair::create([
            'customer_id' => $customer->id, 'item_description' => 'Synthetic chain clasp',
            'gross_weight' => 5, 'metal_type' => 'gold', 'status' => 'ready',
        ]));

        return [$owner, $shop, $repair];
    }

    public function test_repair_detail_header_controls_keep_names_when_their_text_is_compact(): void
    {
        [$owner, $shop, $repair] = $this->repairDetailFixture();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs/'.$repair->id))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $links = $xp->query('//div[contains(@class,"repairs-show-header-actions")]/a');
        $this->assertSame(2, $links->length);
        foreach ($links as $link) {
            $this->assertSame(trim($link->textContent), $link->getAttribute('aria-label'));
            $this->assertSame($link->getAttribute('aria-label'), $link->getAttribute('data-tip'));
        }
        $this->assertSame(1, $xp->query('//a[contains(@href,"/repairs/'.$repair->id.'/challan") and normalize-space(.)="Print challan"]')->length);
        $this->assertSame(1, $xp->query('//a[contains(@href,"/repairs/'.$repair->id.'/challan/pdf") and @data-turbo="false" and normalize-space(.)="Download PDF"]')->length);
    }

    public function test_repair_detail_has_one_main_landmark_and_announces_the_current_step(): void
    {
        [$owner, $shop, $repair] = $this->repairDetailFixture();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs/'.$repair->id))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(1, $xp->query('//main')->length);
        $this->assertSame(1, $xp->query('//section[contains(@class,"repairs-show-workflow")]//h2[normalize-space(.)="Repair progress"]')->length);
        $this->assertSame(4, $xp->query('//ol[contains(@class,"repairs-show-steps")]/li')->length);
        $this->assertSame(1, $xp->query('//ol[contains(@class,"repairs-show-steps")]/li[@aria-current="step"]')->length);
        $this->assertStringContainsString('Ready', $xp->query('//li[@aria-current="step"]')->item(0)->textContent);
    }

    public function test_repair_detail_does_not_offer_edit_to_a_view_only_user(): void
    {
        [$viewer, $shop, $repair] = $this->repairDetailFixture();
        $this->grantOnlyPermissions($viewer, ['repairs.view']);
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)->get('/repairs/'.$repair->id))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(0, $xp->query('//a[contains(@href,"/repairs/'.$repair->id.'/edit")]')->length);
        $this->assertSame(1, $xp->query('//a[normalize-space(.)="Print challan"]')->length);
        TenantContext::runFor($shop->id, fn () => $this->get('/repairs/'.$repair->id.'/edit'))->assertForbidden();
    }

    public function test_repair_detail_keeps_missing_number_help_and_optional_sharing_instructions(): void
    {
        [$owner, $shop, $repair] = $this->repairDetailFixture();
        $html = TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get('/repairs/'.$repair->id))->assertOk()->getContent();
        $xp = $this->xpath($html);
        $this->assertSame(1, $xp->query('//p[not(ancestor::details) and contains(.,"No mobile number is saved for this customer.")]')->length);
        $this->assertSame(1, $xp->query('//details[contains(@class,"repairs-show-share-help") and not(@open)]/summary[normalize-space(.)="How to share the challan PDF"]')->length);
        $this->assertSame(1, $xp->query('//details[contains(@class,"repairs-show-share-help")]//p[contains(.,"tap Download PDF") and contains(.,"Attach")]')->length);
        $this->assertSame(1, $xp->query('//p[@data-challan-share-status and @role="status" and @aria-live="polite"]')->length);
    }
}
