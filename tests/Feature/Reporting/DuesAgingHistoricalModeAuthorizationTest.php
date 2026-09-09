<?php

namespace Tests\Feature\Reporting;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Invoice;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Closes the audit finding: `DuesAgingDataset` had no mode-dependent gate, so
 * a role holding `reports.view`/`reports.export` but NOT `historical.view`
 * could read historical customer data (names, mobiles, unpaid amounts)
 * through `sales_source=historical`/`combined` — bypassing the exact
 * protection `HistoricalSalesRegisterDataset` already has for the same data
 * class. LIVE must keep working for this role unchanged; only the
 * historical/combined branch is newly gated
 * (`ReportPermissions::gateForSalesSource()`).
 *
 * Deliberately uses `grantOnlyPermissions()` to build a genuine custom role
 * with exactly `reports.view` + `reports.export` — NOT the default Owner
 * (all permissions) or Manager (all-except-settings/staff, which still
 * includes `historical.view`) fixtures, which would mask this gap entirely.
 */
class DuesAgingHistoricalModeAuthorizationTest extends TestCase
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

    /** Genuine custom role: exactly reports.view + reports.export, historical.view absent. */
    private function restrictToReportsViewExportOnly(\App\Models\User $user): void
    {
        $this->grantOnlyPermissions($user, ['reports.view', 'reports.export']);
        $user->unsetRelation('role');
    }

    private function addHistoricalView(\App\Models\User $user): void
    {
        $this->grantOnlyPermissions($user, ['reports.view', 'reports.export', 'historical.view']);
        $user->unsetRelation('role');
    }

    // ---- 1/2: LIVE screen + export stay accessible ----------------------------

    public function test_live_screen_remains_accessible_without_historical_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->restrictToReportsViewExportOnly($owner);
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 1234);

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging'))
            ->assertOk();

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=live'))
            ->assertOk();
    }

    public function test_live_export_remains_accessible_without_historical_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->restrictToReportsViewExportOnly($owner);
        $c = $this->createCustomer($shop->id);
        $this->invoice($shop->id, $c->id, 1234);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/reports/dues-aging/export', [
            'profile' => 'detailed', 'format' => 'csv', 'sales_source' => 'live',
        ]));

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
    }

    // ---- 3/4: HISTORICAL/COMBINED screen + export are denied -------------------

    public function test_historical_and_combined_screens_are_denied_without_historical_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->restrictToReportsViewExportOnly($owner);

        foreach (['historical', 'combined'] as $mode) {
            TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get("/report/dues-aging?sales_source={$mode}"))
                ->assertForbidden();
        }
    }

    public function test_historical_and_combined_exports_are_denied_without_historical_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->restrictToReportsViewExportOnly($owner);

        foreach (['historical', 'combined'] as $mode) {
            $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/reports/dues-aging/export', [
                'profile' => 'detailed', 'format' => 'csv', 'sales_source' => $mode,
            ]));

            $response->assertForbidden();
        }
    }

    // ---- 5: granting historical.view enables the historical modes -------------

    public function test_granting_historical_view_enables_historical_and_combined_modes(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->restrictToReportsViewExportOnly($owner);
        $batch = $this->makeBatch($shop->id);
        $this->makeDocument($shop->id, $batch->id);

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=historical'))
            ->assertForbidden();

        $this->addHistoricalView($owner);

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=historical'))
            ->assertOk();
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=combined'))
            ->assertOk();

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/reports/dues-aging/export', [
            'profile' => 'detailed', 'format' => 'csv', 'sales_source' => 'historical',
        ]));
        $response->assertOk();
    }

    // ---- 6: reports.export still required even with historical.view -----------

    public function test_missing_reports_export_still_blocks_export_even_with_historical_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['reports.view', 'historical.view']); // no reports.export
        $owner->unsetRelation('role');

        // Screen (view-only) still works...
        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=historical'))
            ->assertOk();

        // ...but export is denied regardless of sales_source.
        foreach (['live', 'historical', 'combined'] as $mode) {
            $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/reports/dues-aging/export', [
                'profile' => 'detailed', 'format' => 'csv', 'sales_source' => $mode,
            ]));
            $response->assertForbidden();
        }
    }

    // ---- 7: Historical Sales Register remains protected ------------------------

    public function test_historical_sales_register_still_requires_historical_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->restrictToReportsViewExportOnly($owner);

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/historical-register'))
            ->assertForbidden();

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->post('/reports/historical-sales-register/export', [
            'profile' => 'detailed', 'format' => 'csv',
        ]));
        $response->assertForbidden();

        $this->addHistoricalView($owner);

        TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/historical-register'))
            ->assertOk();
    }

    // ---- 8: cross-shop isolation intact -----------------------------------------

    public function test_cross_shop_isolation_intact_for_historical_mode(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $this->addHistoricalView($ownerB);

        $batchA = $this->makeBatch($shopA->id);
        $this->makeDocument($shopA->id, $batchA->id, [
            'customer_id' => null, 'outstanding_amount_snapshot' => 5000,
            'customer_snapshot' => ['name' => 'Shop A Only Customer'],
        ]);

        $response = TenantContext::runFor((int) $shopB->id, fn () => $this->actingAs($ownerB)->get('/report/dues-aging?sales_source=historical'));

        $response->assertOk();
        $response->assertDontSee('Shop A Only Customer');
    }

    // ---- export-panel metadata: Historical/Combined options hidden without the gate --

    public function test_export_panel_hides_historical_and_combined_options_without_historical_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->restrictToReportsViewExportOnly($owner);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/reports/dues-aging/export'));

        $response->assertOk();
        $response->assertSee('Live');
        $response->assertDontSee('Historical');
        $response->assertDontSee('Combined');
    }

    public function test_export_panel_shows_historical_and_combined_options_with_historical_view(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->addHistoricalView($owner);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/reports/dues-aging/export'));

        $response->assertOk();
        $response->assertSee('Live');
        $response->assertSee('Historical');
        $response->assertSee('Combined');
    }

    // ---- invalid-mode rejection preserved even for the restricted role ----------

    public function test_invalid_sales_source_still_rejected_not_authorization_bypassed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->restrictToReportsViewExportOnly($owner);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/report/dues-aging?sales_source=bogus'));

        $response->assertSessionHasErrors('sales_source');
    }
}
