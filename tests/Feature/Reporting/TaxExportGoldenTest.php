<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Reporting\ColumnPolicy;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Render\CsvRenderer;
use App\Services\Reporting\Render\RenderedOutput;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;
use ZipArchive;

/**
 * Golden-file tests for the CA tax exports (GSTR-1, CN register) — now against
 * the SPINE CSV format (frozen §5.3): clean single-table CSV (one header row +
 * raw machine values, NO banner rows), and a ZIP of per-section CSVs for the
 * multi-section GSTR-1. A CA files from these, so a silent column/value/shape
 * regression is the highest-consequence reporting failure. We render via the
 * spine CsvRenderer (the same path the export panel uses) and lock the section
 * structure + exact data rows.
 *
 * (Replaces the retired legacy banner-CSV golden test — see
 * COMPLIANCE_CSV_MIGRATION_NOTE.md.)
 */
class TaxExportGoldenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0:User,1:\App\Models\Shop} */
    private function seedGoldenFixture(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $role = Role::withoutGlobalScopes()->findOrFail($user->role_id);
        $perm = Permission::firstOrCreate(['name' => 'reports.view'], ['display_name' => 'View Reports', 'group' => 'reports']);
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        $customer = $this->createCustomer($shop->id);

        $base = [
            'shop_id' => $shop->id, 'customer_id' => $customer->id,
            'gold_rate' => 7200, 'discount' => 0, 'gst_rate' => 3,
            'status' => Invoice::STATUS_FINALIZED,
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00', 'finalized_at' => '2026-03-15 10:00:00',
        ];

        $invB2b = (int) DB::table('invoices')->insertGetId(array_merge($base, [
            'invoice_number' => 'INV-GOLD-B2B', 'buyer_gstin' => '27ABCDE1234F1Z5', 'place_of_supply_state_code' => '27',
            'subtotal' => 100000, 'gst' => 3000, 'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0, 'total' => 103000,
        ]));
        DB::table('invoices')->insert(array_merge($base, [
            'invoice_number' => 'INV-GOLD-B2CS',
            'subtotal' => 50000, 'gst' => 1500, 'cgst_amount' => 750, 'sgst_amount' => 750, 'igst_amount' => 0, 'total' => 51500,
        ]));

        $ro = (int) DB::table('return_orders')->insertGetId([
            'shop_id' => $shop->id, 'invoice_id' => $invB2b, 'return_type' => 'customer_return', 'status' => 'settled',
            'created_by_user_id' => $user->id, 'created_at' => '2026-03-20', 'updated_at' => '2026-03-20',
        ]);
        DB::table('credit_notes')->insert([
            'shop_id' => $shop->id, 'return_order_id' => $ro, 'invoice_id' => $invB2b, 'customer_id' => $customer->id,
            'credit_note_sequence' => 1, 'credit_note_number' => 'CN-GOLD-001',
            'subtotal' => 20000, 'gst' => 600, 'gst_rate' => 3, 'cgst_amount' => 300, 'sgst_amount' => 300, 'igst_amount' => 0, 'total' => 20600,
            'status' => 'issued', 'issued_at' => '2026-03-20 10:00:00', 'issued_by_user_id' => $user->id,
            'created_at' => '2026-03-20 10:00:00', 'updated_at' => '2026-03-20 10:00:00',
        ]);

        return [$user, $shop];
    }

    /** Render a report's CSV through the spine CsvRenderer (same path as the export panel). */
    private function renderCsv(string $key, int $shopId): RenderedOutput
    {
        $definition = app(ReportRegistry::class)->definition($key);

        $user = Mockery::mock(User::class);
        $user->shouldReceive('hasPermission')->andReturn(false);
        $columnKeys = app(ColumnPolicy::class)->resolve($definition, ReportProfile::Fixed, $user)->columnKeys;

        $request = new ReportRequest(
            definition: $definition, shopId: $shopId, userId: 1, userName: 'CA',
            profile: ReportProfile::Fixed, format: ExportFormat::Csv,
            filters: ['period' => ['from' => CarbonImmutable::parse('2026-03-01'), 'to' => CarbonImmutable::parse('2026-03-31')]],
            columnKeys: $columnKeys,
        );
        $meta = new ReportMeta(
            reportKey: $key, reportVersion: $definition->version, title: $definition->title,
            profileLabel: 'Fixed', format: 'csv', filtersApplied: ['Period' => 'March 2026'], periodLabel: 'March 2026',
            shopLegalName: 'Goldlux', shopAddress: null, shopGstin: '27ABCDE1234F1Z5', shopStateCode: '27',
            generatedByName: 'CA', generatedAt: now(), generatorTag: 'test',
        );

        return TenantContext::runFor($shopId, fn () => app(CsvRenderer::class)->render(
            app(ReportRegistry::class)->datasetService($key)->build($request, $meta),
            $request,
        ));
    }

    /** @return array<string,string> zip entry name => contents */
    private function zipEntries(string $bytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'jf-golden-');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($tmp) === true, 'output is a valid ZIP');
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $out[$name] = $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $out;
    }

    /** @return array<int, array<int,string>> */
    private function parse(string $csv): array
    {
        return array_map(static fn ($l) => str_getcsv($l), explode("\n", rtrim($csv, "\n")));
    }

    public function test_gstr1_spine_export_is_a_clean_per_section_zip(): void
    {
        [, $shop] = $this->seedGoldenFixture();

        $output = $this->renderCsv('gstr1', $shop->id);

        $this->assertSame('application/zip', $output->mimeType);
        $this->assertStringNotContainsString('== B2B', $output->contents, 'no banner rows in the spine export');

        $entries = $this->zipEntries($output->contents);
        $names = array_keys($entries);
        // One clean CSV per section + a provenance sidecar.
        foreach (['b2b.csv', 'b2cs.csv', 'hsn.csv', 'credit-notes.csv'] as $expected) {
            $this->assertContains($expected, $names, "ZIP contains {$expected}");
        }
        $this->assertNotEmpty(array_filter($names, static fn ($n) => str_ends_with($n, '-meta.json')), 'provenance sidecar present');

        // B2B section — clean header (labels), exact data row.
        $b2b = $this->parse($entries['b2b.csv']);
        $this->assertSame(['Invoice No', 'Date', 'Buyer GSTIN', 'Place of Supply', 'GST Rate', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total'], $b2b[0]);
        $b2bRow = collect($b2b)->first(fn ($r) => ($r[0] ?? null) === 'INV-GOLD-B2B');
        $this->assertNotNull($b2bRow, 'B2B invoice present');
        $this->assertSame('2026-03-15', $b2bRow[1]);          // ISO date, no time
        $this->assertSame('27ABCDE1234F1Z5', $b2bRow[2]);
        $this->assertSame('100000.00', $b2bRow[5]);           // raw value, no ₹/grouping
        $this->assertSame('1500.00', $b2bRow[6]);
        $this->assertSame('103000.00', $b2bRow[9]);

        // B2CS section — aggregate row.
        $b2cs = $this->parse($entries['b2cs.csv']);
        $this->assertSame(['GST Rate', 'Place of Supply', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoices'], $b2cs[0]);
        $b2csRow = collect($b2cs)->first(fn ($r) => ($r[2] ?? null) === '50000.00');
        $this->assertNotNull($b2csRow, 'B2CS aggregate present');
        $this->assertSame('1500.00', $b2csRow[6]);
        $this->assertSame('1', $b2csRow[7]);

        // Credit-note section carries the reversal.
        $cn = $this->parse($entries['credit-notes.csv']);
        $this->assertNotNull(collect($cn)->first(fn ($r) => ($r[0] ?? null) === 'CN-GOLD-001'), 'CN present in GSTR-1');
    }

    public function test_cn_register_spine_export_is_a_clean_single_csv(): void
    {
        [, $shop] = $this->seedGoldenFixture();

        $output = $this->renderCsv('cn-register', $shop->id);

        $this->assertSame('text/csv', $output->mimeType);
        $rows = $this->parse($output->contents);

        $this->assertSame(
            ['Credit Note No', 'Date', 'Type', 'Original Invoice', 'Customer', 'GST Rate', 'Taxable Value', 'CGST', 'SGST', 'IGST', 'Total GST', 'Total'],
            $rows[0],
            'spine CN register header'
        );

        $cnRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'CN-GOLD-001');
        $this->assertNotNull($cnRow, 'the credit note must appear');
        $this->assertSame('INV-GOLD-B2B', $cnRow[3], 'original invoice reference');
        $this->assertSame('20000.00', $cnRow[6], 'taxable');
        $this->assertSame('300.00', $cnRow[7], 'cgst');
        $this->assertSame('300.00', $cnRow[8], 'sgst');
        $this->assertSame('600.00', $cnRow[10], 'total gst');
        $this->assertSame('20600.00', $cnRow[11], 'cn total');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
