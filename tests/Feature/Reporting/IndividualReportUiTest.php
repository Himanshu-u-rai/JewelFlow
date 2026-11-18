<?php

namespace Tests\Feature\Reporting;

use App\Services\Reporting\Definition\ReportRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class IndividualReportUiTest extends TestCase
{
    use CreatesTestTenant, RefreshDatabase;

    public function test_invalid_screen_periods_return_validation_errors_not_server_errors(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        foreach (['date_preset=custom', 'date_preset=custom&date_from=2026-11-20&date_to=2026-11-01',
            'date_preset=named_fy', 'date_preset=named_fy&fy_name=invalid', 'date=not-a-date', 'month=13&year=2026'] as $query) {
            $this->getJson('/report/cash?'.$query)->assertUnprocessable();
        }
    }

    public function test_all_retail_spine_screens_retain_canonical_rows_totals_and_valid_export_scope(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        $registry = app(ReportRegistry::class);
        foreach ($registry->all() as $key => $definition) {
            if ($definition->permissions->edition !== null && $definition->permissions->edition !== 'retailer') continue;
            $response = $this->get('/reporting/'.$key.'/screen?date_preset=custom&date_from=2026-11-01&date_to=2026-11-02')->assertOk();
            $xp = $this->xpath($response->getContent());
            $view = $response->viewData('view');
            $this->assertSame(count($view['sections']), $xp->query('//section[@data-report-section]')->length, $key);
            $this->assertSame(1, $xp->query('//dialog[@id="individual-report-filters"]')->length, $key);
            foreach ($view['sections'] as $section) {
                $scope = '//section[@data-report-section="'.$section['key'].'"]';
                $this->assertSame($section['rowCount'], $xp->query($scope.'//*[@data-report-row]')->length, $key.' '.$section['key']);
                if ($xp->query($scope.'//table')->length) {
                    $this->assertSame(count($section['columns']) + 1, $xp->query($scope.'//thead/tr/th')->length, $key.' index header');
                    $this->assertSame(1, $xp->query($scope.'//thead/tr/th[@aria-label="Row number"]')->length, $key);
                    if ($section['hasTotals']) {
                        $this->assertSame(count($section['columns']) + 1, $xp->query($scope.'//tfoot/tr/*')->length, $key.' aligned totals');
                        $this->assertSame('Total', trim($xp->evaluate('string('.$scope.'//tfoot/tr/th)')), $key);
                        $headerKeys = array_map(fn ($n) => $n->getAttribute('data-col-key'), iterator_to_array($xp->query($scope.'//thead/tr/th[@data-col-key]')));
                        $footerKeys = array_map(fn ($n) => $n->getAttribute('data-col-key'), iterator_to_array($xp->query($scope.'//tfoot/tr/td')));
                        $this->assertSame($headerKeys, $footerKeys, $key.' totals remain under their own columns');
                    }
                }
                foreach ($section['totals'] as $column => $value) {
                    $this->assertSame($value, trim($xp->evaluate('string('.$scope.'//tfoot//td[@data-col-key="'.$column.'"])')), $key.' '.$column);
                }
            }
            $export = $xp->evaluate('string(//a[@data-report-export]/@href)');
            parse_str(parse_url($export, PHP_URL_QUERY), $query);
            $this->assertSame('2026-11-01', $query['date_from'], $key);
            $this->assertSame('2026-11-02', $query['date_to'], $key);
            $this->assertSame($response->viewData('profile')->value, $query['profile'], $key);
        }
    }

    public function test_as_of_and_legacy_periods_match_export_and_sales_route_uses_shared_screen(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        foreach (['/report/closing?date=2026-10-12' => ['2026-10-12', '2026-10-12'],
            '/report/gst?month=9&year=2026' => ['2026-09-01', '2026-09-30']] as $url => [$from, $to]) {
            $response = $this->get($url)->assertOk();
            $query = $response->viewData('exportQuery');
            $this->assertSame('custom', $query['date_preset']);
            $this->assertSame($from, $query['date_from']);
            $this->assertSame($to, $query['date_to']);
        }
        $closing = $this->get('/report/closing')->assertOk();
        $this->assertSame('today', $closing->viewData('periodPreset'));
        $this->get('/report/sales-register')->assertOk()->assertViewIs('reporting.reports.generic-screen');
    }

    public function test_export_setup_retains_sensitive_choice_and_offers_named_financial_year(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        $response = $this->get('/reports/sales-register/export?profile=detailed&include_sensitive=1&date_preset=named_fy&fy_name=2025-26')->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame(1, $xp->query('//input[@name="include_sensitive" and @checked]')->length);
        $this->assertSame('2025-26', $xp->evaluate('string(//input[@name="fy_name"]/@value)'));
    }

    public function test_export_popup_loads_only_its_form_and_preserves_the_standalone_fallback(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        foreach (['scheme-liability', 'sales-register', 'cash-flow'] as $key) {
            $url = '/reports/'.$key.'/export?date_preset=custom&date_from=2026-11-01&date_to=2026-11-02&profile=detailed';
            $response = $this->get($url, ['Turbo-Frame' => 'report-export-frame'])->assertOk();
            $xp = $this->xpath($response->getContent());
            $this->assertSame(1, $xp->query('//turbo-frame[@id="report-export-frame"]')->length);
            $this->assertSame(0, $xp->query('//main|//aside[contains(@class,"sidebar")]|//style')->length);
            $this->assertSame('2026-11-01', $xp->evaluate('string(//input[@name="date_from"]/@value)'));
            $this->assertSame(1, $xp->query('//button[@data-export-close]')->length);
            $this->assertSame(1, $xp->query('//form[@data-report-download]')->length);
            $this->get($url)->assertOk()->assertSee('Back to report');
        }
        $this->postJson('/reports/scheme-liability/export', ['date_preset' => 'custom', 'format' => 'csv', 'profile' => 'detailed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['date_from', 'date_to']);
    }

    public function test_popup_presets_return_to_the_frame_and_retain_their_selected_scope(): void
    {
        [$owner] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        $panel = '/reports/scheme-liability/export';
        $headers = ['Turbo-Frame' => 'report-export-frame', 'Accept' => 'application/json'];
        $this->from($panel)->post($panel.'-presets', [
            'name' => 'Popup monthly review', 'profile' => 'detailed', 'format' => 'csv',
            'filters' => ['date_preset' => 'named_fy', 'fy_name' => '2025-26'],
        ], $headers)->assertRedirect($panel);
        $preset = \App\Models\Reporting\ReportingPreset::withoutTenant()->where('created_by', $owner->id)->firstOrFail();
        $response = $this->get($panel.'?preset='.$preset->id, $headers)->assertOk();
        $xp = $this->xpath($response->getContent());
        $this->assertSame('named_fy', $xp->evaluate('string(//select[@name="date_preset"]/option[@selected]/@value)'));
        $this->assertSame('csv', $xp->evaluate('string(//select[@name="format"]/option[@selected]/@value)'));
        $this->assertSame('2025-26', $xp->evaluate('string(//input[@name="fy_name"]/@value)'));
        $this->assertSame(1, $xp->query('//a[@data-turbo-frame="report-export-frame"]')->length);
        \App\Support\TenantContext::runFor((int) $owner->shop_id, fn () =>
            $this->from($panel)->delete($panel.'-presets/'.$preset->id, [], $headers))->assertRedirect($panel);
        $this->get($panel, $headers)->assertOk()->assertSee('No saved presets yet.');
    }

    public function test_cash_export_keeps_the_screen_row_filters_without_changing_drawer_summary(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        foreach ([['in', 'manual', 'Keep this filtered row'], ['out', 'manual', 'Exclude direction row'], ['in', 'other', 'Exclude source row']] as [$type, $source, $description]) {
            \Illuminate\Support\Facades\DB::table('cash_transactions')->insert([
                'shop_id' => $shop->id, 'user_id' => $owner->id, 'type' => $type, 'amount' => 100,
                'payment_mode' => 'cash', 'source_type' => $source, 'description' => $description,
                'created_at' => '2026-11-05 12:00:00', 'updated_at' => '2026-11-05 12:00:00',
            ]);
        }
        $query = ['date_preset' => 'custom', 'date_from' => '2026-11-01', 'date_to' => '2026-11-30',
            'profile' => 'detailed', 'cash_type' => 'in', 'cash_source' => 'manual'];
        $screen = $this->get('/report/cash?'.http_build_query($query))->assertOk()->viewData('view');
        $sections = collect($screen['sections'])->keyBy('key');
        $this->assertSame(1, $sections['ledger']['rowCount']);
        $export = $this->post('/reports/cash-flow/export', $query + ['format' => 'csv'])->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'report-ui-');
        try {
            file_put_contents($path, $export->getContent());
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path));
            $csv = $zip->getFromName('ledger.csv');
            $this->assertStringContainsString('Keep this filtered row', $csv);
            $this->assertStringNotContainsString('Exclude direction row', $csv);
            $this->assertStringNotContainsString('Exclude source row', $csv);
            $zip->close();
        } finally { unlink($path); }
        $this->postJson('/reports/cash-flow/export', $query + ['format' => 'csv', 'fy_name' => 'bad-year'])->assertUnprocessable();
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

    public function test_category_sales_count_finalized_lines_by_accounting_date_not_draft_creation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->actingAs($owner);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id);
        foreach ([['draft', now(), null, 999], ['finalized', now()->subDays(60), now()->subDay(), 200]] as [$status, $created, $finalized, $amount]) {
            $id = \Illuminate\Support\Facades\DB::table('invoices')->insertGetId([
                'shop_id' => $shop->id, 'customer_id' => $customer->id,
                'invoice_number' => 'REPORT-'.fake()->unique()->numerify('######'), 'gold_rate' => 7200,
                'subtotal' => $amount, 'total' => $amount, 'gst' => 0, 'discount' => 0,
                'status' => 'draft', 'created_at' => $created, 'updated_at' => $created,
            ]);
            \Illuminate\Support\Facades\DB::table('invoice_items')->insert([
                'invoice_id' => $id, 'item_id' => $item->id, 'weight' => 1, 'rate' => $amount,
                'making_charges' => 0, 'stone_amount' => 0, 'line_total' => $amount, 'gst_rate' => 0,
                'gst_amount' => 0, 'metal_type' => 'gold', 'hsn_code' => '7113', 'created_at' => $created, 'updated_at' => $created,
            ]);
            if ($status === 'finalized') \Illuminate\Support\Facades\DB::table('invoices')->where('id', $id)->update(['status' => $status, 'finalized_at' => $finalized]);
        }
        \App\Support\TenantContext::runFor($shop->id, function () {
            $service = app(\App\Services\RetailerReportService::class);
            $best = $service->bestSellers(10, '30')['by_category'];
            $this->assertCount(1, $best);
            $this->assertSame(1, (int) $best[0]['sold_count']);
            $this->assertEquals(200, $best[0]['total_revenue']);
            $this->assertEquals(200, $service->worstSellers(10, '30')[0]['total_revenue']);
        });
    }

    public function test_specialist_reports_preserve_records_indices_escaping_and_filter_validation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->actingAs($owner)->withoutVite();
        $customer = $this->createCustomer($shop->id, ['first_name' => 'Review <customer>', 'date_of_birth' => now()->addDays(3)->setYear(1990)->toDateString()]);
        \App\Support\TenantContext::runFor($shop->id, function () use ($shop, $customer) {
            for ($i = 1; $i <= 26; $i++) {
                (new \App\Models\Repair)->forceFill(['shop_id' => $shop->id, 'customer_id' => $customer->id,
                    'repair_number' => $i, 'item_description' => 'Review ring '.$i, 'description' => 'Repair note',
                    'gross_weight' => 1, 'metal_type' => 'gold', 'status' => 'in_repair'])->save();
            }
        });
        $repairs = $this->get('/report/repairs?page=2')->assertOk()->assertSee('Delivered charges');
        $xp = $this->xpath($repairs->getContent());
        $this->assertSame('26', trim($xp->evaluate('string(//tbody/tr/td[@class="ir-index"])')));
        $this->assertSame(7, $xp->query('//tbody/tr/td')->length);
        $this->createItem($shop->id, null, ['barcode' => 'AGE-REVIEW', 'created_at' => now()->subDays(100)]);
        $this->get('/report/stock-aging')->assertOk()->assertSee('AGE-REVIEW')->assertSee('Selling value');
        $occasions = $this->get('/report/occasions?days=7')->assertOk()->assertSee('Review &lt;customer&gt;', false);
        $xp = $this->xpath($occasions->getContent());
        $this->assertSame('1', trim($xp->evaluate('string(//tbody/tr/td[@class="ir-index"])')));
        $this->assertSame(0, $xp->query('//customer')->length);
        foreach (['/report/repairs?from_date=2026-11-20&to_date=2026-11-01', '/report/sellers?period=bad', '/report/occasions?days=-5'] as $url) $this->getJson($url)->assertUnprocessable();
    }
}
