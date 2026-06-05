<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Render\HtmlToPdf;
use App\Services\Reporting\Render\PdfRenderer;
use App\Services\Reporting\Render\ValueFormatter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 2 sign-off proof: every compliance report's PDF is generated through the
 * shared report-document renderer (Chromium document rendering), NOT browser
 * print of the screen, and carries the full §4.2/§15 document furniture.
 * Actual PDFs are written to storage/app/reporting-samples/ for inspection.
 */
class Phase2PdfDocumentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const SAMPLE_DIR = '/var/www/jewelflow/storage/app/reporting-samples';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        if (! app(HtmlToPdf::class)->isAvailable()) {
            $this->markTestSkipped('Chromium binary not available.');
        }
        @mkdir(self::SAMPLE_DIR, 0777, true);
    }

    private function seedData(int $shopId, int $userId): void
    {
        $customerId = $this->createCustomer($shopId)->id;
        $id = (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-100001', 'gold_rate' => 7200,
            'subtotal' => 100000, 'discount' => 0, 'gst' => 3000, 'gst_rate' => 3, 'total' => 103000,
            'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0,
            'buyer_gstin' => '29ZZABC1234F1Z5', 'place_of_supply_state_code' => '29',
            'status' => Invoice::STATUS_DRAFT, 'finalized_at' => null,
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
        ]);
        $item = $this->createItem($shopId);
        DB::table('invoice_items')->insert([
            'invoice_id' => $id, 'item_id' => $item->id, 'weight' => 10, 'rate' => 5000,
            'making_charges' => 0, 'stone_amount' => 0, 'line_total' => 100000, 'gst_rate' => 3,
            'gst_amount' => 3000, 'metal_type' => 'gold', 'hsn_code' => '7113',
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
        ]);
        DB::table('invoices')->where('id', $id)->update(['status' => Invoice::STATUS_FINALIZED, 'finalized_at' => '2026-03-15 10:00:00']);

        // A credit note so cn-register / day-book have content.
        $ro = (int) DB::table('return_orders')->insertGetId([
            'shop_id' => $shopId, 'invoice_id' => $id, 'return_type' => 'customer_return',
            'status' => 'settled', 'created_by_user_id' => $userId,
            'created_at' => '2026-03-20 10:00:00', 'updated_at' => '2026-03-20 10:00:00',
        ]);
        DB::table('credit_notes')->insert([
            'shop_id' => $shopId, 'invoice_id' => $id, 'return_order_id' => $ro, 'customer_id' => $customerId,
            'credit_note_number' => 'CN-1', 'credit_note_sequence' => 1,
            'subtotal' => 20000, 'gst' => 600, 'gst_rate' => 3, 'total' => 20600,
            'cgst_amount' => 300, 'sgst_amount' => 300, 'igst_amount' => 0,
            'status' => 'issued', 'issued_by_user_id' => $userId,
            'issued_at' => '2026-03-20 10:00:00', 'created_at' => '2026-03-20 10:00:00', 'updated_at' => '2026-03-20 10:00:00',
        ]);
    }

    private function requestFor(string $key, int $shopId): ReportRequest
    {
        $definition = app(ReportRegistry::class)->definition($key);
        $rigid = $definition->classification->isRigid();
        $profile = $rigid ? ReportProfile::Fixed : ReportProfile::Detailed;

        $user = Mockery::mock(\App\Models\User::class);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $keys = app(ColumnPolicy::class)->resolve($definition, $profile, $user)->columnKeys;

        return new ReportRequest(
            definition: $definition, shopId: $shopId, userId: 1, userName: 'Auditor',
            profile: $profile, format: ExportFormat::Pdf,
            filters: ['period' => ['from' => \Carbon\CarbonImmutable::parse('2026-03-01'), 'to' => \Carbon\CarbonImmutable::parse('2026-03-31')]],
            columnKeys: $keys,
        );
    }

    private function metaFor(ReportRequest $request): ReportMeta
    {
        return new ReportMeta(
            reportKey: $request->definition->key, reportVersion: $request->definition->version,
            title: $request->definition->title, profileLabel: ucfirst($request->profile->value),
            format: 'pdf', filtersApplied: ['Period' => 'March 2026', 'Status' => 'Finalized'],
            periodLabel: 'March 2026',
            shopLegalName: 'Goldlux Jewellers Pvt Ltd', shopAddress: '12 MG Road, Bengaluru',
            shopGstin: '29ZZABC1234F1Z5', shopStateCode: '29',
            generatedByName: 'Asha Auditor', generatedAt: now(), generatorTag: 'jewelflow-reporting/1', watermark: null,
        );
    }

    public function test_every_compliance_report_pdf_is_a_chromium_document_with_full_furniture(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $this->seedData($shop->id, $owner->id);

        $reports = ['gst', 'gstr1', 'gstr3b', 'cn-register', 'day-book'];

        foreach ($reports as $key) {
            $request = $this->requestFor($key, $shop->id);
            $meta = $this->metaFor($request);
            $definition = $request->definition;

            $dataset = TenantContext::runFor($shop->id, fn () => app(ReportRegistry::class)->datasetService($key)->build($request, $meta));

            // (a) The exact HTML the renderer feeds Chromium — assert §4.2/§15 furniture.
            $columnsByKey = [];
            foreach ($dataset->sections as $section) {
                $columnsByKey[$section->key] = $section->columns;
            }
            $html = view('reporting.layouts.report-document', [
                'meta' => $meta, 'dataset' => $dataset, 'formatter' => new ValueFormatter(),
                'columnsFor' => static fn (string $k): array => $columnsByKey[$k] ?? [],
                'grandTotals' => [],
            ])->render();

            $this->assertStringContainsString('Goldlux Jewellers Pvt Ltd', $html, "$key: shop legal name");
            $this->assertStringContainsString('GSTIN: 29ZZABC1234F1Z5', $html, "$key: shop GSTIN");
            $this->assertStringContainsString($definition->title, $html, "$key: report title");
            $this->assertStringContainsString('March 2026', $html, "$key: reporting period");
            $this->assertStringContainsString('Generated by', $html, "$key: generated-by label");
            $this->assertStringContainsString('Asha Auditor', $html, "$key: generated-by name");
            $this->assertStringContainsString('Generated at', $html, "$key: generated timestamp");
            $this->assertStringContainsString('Report version ' . $definition->version, $html, "$key: report version stamp (§15)");
            $this->assertStringContainsString('rf-page-num', $html, "$key: page-number counter");
            $this->assertStringContainsString('report-table', $html, "$key: tabular layout");
            $this->assertStringNotContainsString('window.print', $html, "$key: must NOT use browser print");

            // (b) The actual PDF via Chromium — proves document rendering, not screen print.
            $output = app(PdfRenderer::class)->render($dataset, $request);
            $this->assertSame('application/pdf', $output->mimeType, "$key: pdf mime");
            $this->assertStringStartsWith('%PDF', $output->contents, "$key: real Chromium PDF (%PDF header)");
            $this->assertGreaterThan(2000, $output->byteSize(), "$key: non-trivial PDF size");

            file_put_contents(self::SAMPLE_DIR . "/{$key}.pdf", $output->contents);
        }
    }
}
