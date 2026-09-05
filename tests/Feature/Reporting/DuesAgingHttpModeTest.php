<?php

namespace Tests\Feature\Reporting;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Invoice;
use App\Models\Permission;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;
use ZipArchive;

/**
 * HTTP-level coverage for the `sales_source` mode selector on the Customer
 * Dues Aging report (owner-agreed reporting semantics — see
 * `DuesAgingDataset`'s class docblock). Screen and export share one dataset
 * build, so every assertion here proves screen/export parity by construction:
 * both routes are driven by the same `sales_source` value and must render the
 * same sections.
 */
class DuesAgingHttpModeTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);
    }

    private function invoice(int $shopId, int $customerId, float $total): void
    {
        DB::table('invoices')->insert([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-'.fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => $total, 'discount' => 0, 'gst' => 0, 'gst_rate' => 0,
            'total' => $total, 'status' => Invoice::STATUS_FINALIZED,
            'created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5), 'finalized_at' => now()->subDays(5),
        ]);
    }

    private function makeBatch(int $shopId): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch;
        $batch->forceFill([
            'shop_id' => $shopId, 'label' => 'FY 2023-24',
            'source_system' => 'Manual', 'status' => HistoricalImportBatch::STATUS_DRAFT,
        ])->save();

        return $batch;
    }

    private function makeDocument(int $shopId, int $batchId, array $attrs = []): HistoricalSalesDocument
    {
        $number = $attrs['original_document_number'] ?? ('DOC-'.Str::random(8));
        $date = $attrs['document_date'] ?? '2026-03-15';

        $document = new HistoricalSalesDocument;
        $document->forceFill(array_merge([
            'shop_id' => $shopId,
            'historical_import_batch_id' => $batchId,
            'historical_reference' => (string) Str::uuid(),
            'original_document_number' => $number,
            'original_document_number_normalized' => HistoricalDocumentIdentity::normalizeNumber($number),
            'document_type' => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date' => $date,
            'financial_year' => HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable($date)),
            'source_system' => 'Manual',
            'customer_snapshot' => ['name' => 'Ramesh Patel'],
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness' => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total' => 5000.00,
            'outstanding_amount_snapshot' => 5000.00,
            'status' => HistoricalSalesDocument::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', (string) Str::uuid()),
            'published_at' => now(),
        ], $attrs))->save();

        return $document;
    }

    private function grantReportsPermissions(\App\Models\User $owner, int $shopId): void
    {
        TenantContext::runFor($shopId, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(
                Permission::whereIn('name', ['reports.view', 'reports.export'])->pluck('id')
            );
        });
        $owner->unsetRelation('role');
    }

    // ---- screen: one section per mode, no cross-mode leakage -----------------

    public function test_screen_live_mode_shows_only_the_live_section(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantReportsPermissions($owner, $shop->id);
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 1234);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging'));

        $response->assertOk();
        $response->assertSee('By Customer');
        $response->assertDontSee('Unpaid as Recorded');
    }

    public function test_screen_historical_mode_shows_unpaid_as_recorded_and_days_since_invoice_date(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantReportsPermissions($owner, $shop->id);
        $c = $this->createCustomer($shop->id);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 1500]);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=historical'));

        $response->assertOk();
        $response->assertSee('Historical — Unpaid as Recorded');
        $response->assertSee('days since invoice date');
        $response->assertDontSee('Live — By Customer');
    }

    public function test_screen_combined_mode_shows_both_sections_and_no_grand_total_row(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantReportsPermissions($owner, $shop->id);
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 750);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 9999]);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=combined'));

        $response->assertOk();
        $response->assertSee('Live — By Customer');
        $response->assertSee('Historical — Unpaid as Recorded');
        $response->assertSee('Combined presentation');
        // 750 + 9999 = 10749 — that combined figure must never render anywhere.
        $response->assertDontSee('10,749.00');
        $response->assertDontSee('10749.00');
    }

    /** Screen and export offer the exact same three options — §A "consistent screen/export filtering". */
    public function test_screen_and_export_panel_offer_the_same_sales_source_options(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantReportsPermissions($owner, $shop->id);

        $screen = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging'));
        $panel = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/reports/dues-aging/export'));

        foreach ([$screen, $panel] as $response) {
            $response->assertOk();
            $response->assertSee('Live');
            $response->assertSee('Historical');
            $response->assertSee('Combined');
        }
    }

    // ---- input handling: explicit invalid value is REJECTED, not relabelled --

    public function test_screen_rejects_an_unsupported_sales_source_value_instead_of_silently_falling_back(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantReportsPermissions($owner, $shop->id);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=bogus'));

        $response->assertSessionHasErrors('sales_source');
    }

    public function test_export_rejects_an_unsupported_sales_source_value_instead_of_silently_falling_back(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantReportsPermissions($owner, $shop->id);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/reports/dues-aging/export', [
            'profile' => 'detailed', 'format' => 'csv', 'sales_source' => 'bogus',
        ]));

        $response->assertSessionHasErrors('sales_source');
    }

    // ---- export: single-section modes render CSV, multi-section render a ZIP -

    public function test_export_live_mode_renders_a_plain_csv(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantReportsPermissions($owner, $shop->id);
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 1234);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/reports/dues-aging/export', [
            'profile' => 'detailed', 'format' => 'csv', 'sales_source' => 'live',
        ]));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('Total Outstanding', $response->getContent());
    }

    /** Combined mode always has 2+ sections, so the CsvRenderer zips it — a grand total cannot hide inside either entry. */
    public function test_export_combined_mode_renders_a_zip_with_separate_live_and_historical_entries(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantReportsPermissions($owner, $shop->id);
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 750);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id, ['customer_id' => $c->id, 'outstanding_amount_snapshot' => 9999]);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/reports/dues-aging/export', [
            'profile' => 'detailed', 'format' => 'csv', 'sales_source' => 'combined',
        ]));

        $response->assertOk();
        $this->assertSame('application/zip', $response->headers->get('Content-Type'));

        $tmp = tempnam(sys_get_temp_dir(), 'jf-test-zip-');
        file_put_contents($tmp, $response->getContent());
        $zip = new ZipArchive;
        $zip->open($tmp);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $liveCsv = $zip->getFromName('aging-live.csv');
        $historicalCsv = $zip->getFromName('aging-historical.csv');
        $zip->close();
        @unlink($tmp);

        $this->assertContains('aging-live.csv', $names);
        $this->assertContains('aging-historical.csv', $names);
        $this->assertStringContainsString('750.00', (string) $liveCsv);
        $this->assertStringContainsString('9999.00', (string) $historicalCsv);
    }

    // ---- tenant isolation over HTTP -------------------------------------------

    public function test_tenant_isolation_shop_bs_screen_never_shows_shop_as_historical_customer(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $this->grantReportsPermissions($ownerB, $shopB->id);

        $batchA = $this->makeBatch($shopA->id);
        $this->makeDocument($shopA->id, $batchA->id, [
            'customer_id' => null, 'outstanding_amount_snapshot' => 5000,
            'customer_snapshot' => ['name' => 'Shop A Only Customer'],
        ]);

        $response = TenantContext::runFor((int) $shopB->id, fn () => $this->actingAs($ownerB)->get('/report/dues-aging?sales_source=historical'));

        $response->assertOk();
        $response->assertDontSee('Shop A Only Customer');
    }

    // ---- permission boundary ----------------------------------------------------

    public function test_reports_view_permission_is_required_for_every_mode(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->detach(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');

        foreach (['live', 'historical', 'combined'] as $mode) {
            TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get("/report/dues-aging?sales_source={$mode}"))
                ->assertForbidden();
        }

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::where('name', 'reports.view')->pluck('id'));
        });
        $owner->unsetRelation('role');

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=historical'))
            ->assertOk();
    }
}
