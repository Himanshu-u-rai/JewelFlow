<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3, Commit 1 — the manual pre-save preview.
 *
 * Preview reuses the exact same normalizer/fingerprint/duplicate-detector as
 * Save (HistoricalImportService::previewManual() shares code with
 * storeManual()), and never opens a transaction or calls persistDraft(). These
 * tests prove that structurally: every historical table AND the customers
 * table stay at their pre-preview row count no matter what is POSTed.
 */
class HistoricalManualPreviewTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    private const HISTORICAL_TABLES = [
        'historical_import_profiles',
        'historical_import_batches',
        'historical_sales_documents',
        'historical_sales_lines',
        'historical_import_rows',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, mixed> a valid manual-entry payload. */
    private function manualPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'M-'.fake()->unique()->numberBetween(1, 99999),
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'customer_name' => 'Walk-in Customer',
            'grand_total' => 18000,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ], $override);
    }

    /** @return array<string, int> table => row count, for every table a manual entry could touch. */
    private function snapshotCounts(): array
    {
        $counts = ['customers' => DB::table('customers')->count()];

        foreach (self::HISTORICAL_TABLES as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    public function test_preview_renders_the_computed_bill_with_no_writes(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $before = TenantContext::runFor($shop->id, fn () => $this->snapshotCounts());

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'original_document_number' => 'PREVIEW-0001',
            'customer_name' => 'Asha Traders',
            'grand_total' => 18500,
        ]));

        $response->assertOk();
        $response->assertSee('PREVIEW-0001', false);
        $response->assertSee('Asha Traders', false);
        $response->assertSee('18,500.00', false);
        $response->assertSee(HistoricalSalesDocument::RECORD_DISCLAIMER, false);

        $after = TenantContext::runFor($shop->id, fn () => $this->snapshotCounts());
        $this->assertSame($before, $after, 'Preview wrote to a table it must never touch.');
    }

    public function test_preview_reports_a_duplicate_without_persisting_anything(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop, $owner): void {
            $batch = new HistoricalImportBatch;
            $batch->forceFill([
                'shop_id' => $shop->id,
                'label' => 'Existing record',
                'source_system' => 'Manual',
                'status' => HistoricalImportBatch::STATUS_REVIEW,
                'created_by' => $owner->id,
            ])->save();

            $doc = new HistoricalSalesDocument;
            $doc->forceFill([
                'shop_id' => $shop->id,
                'historical_import_batch_id' => $batch->id,
                'historical_reference' => (string) Str::uuid(),
                'original_document_number' => 'DUP-0001',
                'original_document_number_normalized' => 'DUP-0001',
                'document_type' => HistoricalSalesDocument::TYPE_SALE_INVOICE,
                'document_date' => '2023-06-15',
                'financial_year' => '2023-24',
                'source_system' => 'Manual',
                'customer_snapshot' => ['name' => 'Existing Customer'],
                'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
                'tax_completeness' => HistoricalSalesDocument::TAX_UNKNOWN,
                'grand_total' => 5000.00,
                'status' => HistoricalSalesDocument::STATUS_DRAFT,
                'content_fingerprint' => str_repeat('a', 64),
                'imported_by' => $owner->id,
                'imported_at' => now(),
            ])->save();
        });

        $before = TenantContext::runFor($shop->id, fn () => $this->snapshotCounts());

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'original_document_number' => 'DUP-0001',
            'document_date' => '2023-06-15',
        ]));

        $response->assertOk();
        $response->assertSee('already recorded', false);

        $after = TenantContext::runFor($shop->id, fn () => $this->snapshotCounts());
        $this->assertSame($before, $after, 'A reported duplicate must never be persisted by preview.');
    }

    public function test_preview_preserves_entered_values_for_edit(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'original_document_number' => 'EDIT-0007',
            'customer_mobile' => '9876543210',
            'grand_total' => 22250,
        ]));

        $response->assertOk();

        // The same field partial the create form uses, now filled from flashed
        // input — proves Back/Edit does not lose what was typed.
        $response->assertSee('value="EDIT-0007"', false);
        $response->assertSee('value="9876543210"', false);
        $response->assertSee('value="22250"', false);
    }

    public function test_confirm_save_recomputes_from_raw_input_and_ignores_anything_extra(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // A tampered client could try to smuggle a pre-computed total alongside
        // the real one. store() validates against StoreManualHistoricalRequest's
        // rules and only ever reads the canonical HistoricalFields::HEADER keys
        // (headerFields()), so anything else is structurally dropped, not trusted.
        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload([
            'original_document_number' => 'CONFIRM-0001',
            'grand_total' => 18000,
            'trusted_grand_total' => 999999,
            'computed_total' => 999999,
        ]));

        $response->assertRedirect();

        TenantContext::runFor($shop->id, function (): void {
            $document = HistoricalSalesDocument::query()->firstOrFail();
            $this->assertSame(18000.0, (float) $document->grand_total);
        });
    }

    public function test_a_customer_id_from_another_shop_is_rejected_by_preview_and_save(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $foreignCustomer = TenantContext::runFor($shopB->id, fn () => $this->createCustomer($shopB->id));

        $preview = $this->actingAs($ownerA)->post(route('historical.manual.preview'), $this->manualPayload([
            'customer_id' => $foreignCustomer->id,
        ]));
        $preview->assertRedirect()->assertSessionHasErrors('customer_id');

        $store = $this->actingAs($ownerA)->post(route('historical.manual.store'), $this->manualPayload([
            'original_document_number' => 'XSHOP-0001',
            'customer_id' => $foreignCustomer->id,
        ]));
        $store->assertRedirect()->assertSessionHasErrors('customer_id');

        TenantContext::runFor($shopA->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count(), 'A forged cross-shop customer selection must persist nothing.');
        });
    }

    public function test_manual_save_still_creates_a_draft_document_after_confirmation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'original_document_number' => 'FLOW-0001',
        ]))->assertOk();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->manualPayload([
            'original_document_number' => 'FLOW-0001',
        ]));

        $response->assertRedirect();
        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(1, HistoricalSalesDocument::query()->count());
            $this->assertSame(
                HistoricalSalesDocument::STATUS_DRAFT,
                HistoricalSalesDocument::query()->firstOrFail()->status
            );
        });
    }

    public function test_import_permission_is_required_to_preview(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->grantOnlyPermissions($owner, ['historical.view']);
        $this->actingAs($owner->fresh())
            ->post(route('historical.manual.preview'), $this->manualPayload())
            ->assertForbidden();
    }

    /**
     * preview() renders a 200 HTML view, not a redirect — Turbo Drive rejects
     * any form response that isn't a redirect ("Form responses must redirect
     * to another location") and silently refuses to display it, leaving a real
     * browser stuck on the stale form. Both forms that POST to this route
     * (the create form and the preview page's own Edit/Recalculate + Confirm
     * Save form) must opt out of Turbo. This is a markup regression test, not
     * a behavioral one: PHPUnit never runs Turbo's client-side JS, so only
     * asserting the attribute is present catches a regression here.
     */
    public function test_manual_create_form_opts_out_of_turbo_for_the_200_preview_response(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->get(route('historical.manual.create'));

        $response->assertOk();
        $response->assertSee(
            '<form method="POST" action="'.route('historical.manual.preview').'" data-turbo="false"',
            false
        );
    }

    public function test_manual_preview_page_form_also_opts_out_of_turbo(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $this->manualPayload([
            'original_document_number' => 'TURBO-0001',
        ]));

        $response->assertOk();
        $response->assertSee(
            '<form method="POST" action="'.route('historical.manual.preview').'" data-turbo="false"',
            false
        );
    }

    // ============================================ save buttons on a blocked bill

    /** A payload the tax normalizer must reject: an intra-state split AND igst. */
    private function blockedPayload(string $number): array
    {
        return $this->manualPayload([
            'original_document_number' => $number,
            'grand_total' => 1180,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_EXCLUSIVE,
            'cgst' => 90,
            'sgst' => 90,
            'igst' => 180,
        ]);
    }

    /**
     * The preview screen must not offer an action the server will refuse.
     *
     * Both Save buttons used to render regardless, so a blocked bill invited the
     * operator to click Save, wait for a round trip, and land back on the same
     * page with the same errors. Edit / Recalculate stays — it is the only way
     * out of a blocked bill.
     */
    public function test_a_blocked_bill_offers_no_save_buttons(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)
            ->post(route('historical.manual.preview'), $this->blockedPayload('BLOCKED-0001'));

        $response->assertOk();
        $response->assertSee('Blocking issues', false);

        $response->assertDontSee('data-historical-preview-action="draft"', false);
        $response->assertDontSee('data-historical-preview-action="publish"', false);
        $response->assertSee('Edit / Recalculate preview', false);
        $response->assertSee('Fix the blocking issues above', false);
    }

    /** The buttons must come back the moment the bill is clean — not be lost. */
    public function test_a_clean_bill_still_offers_both_save_buttons(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)
            ->post(route('historical.manual.preview'), $this->manualPayload([
                'original_document_number' => 'CLEAN-0001',
            ]));

        $response->assertOk();
        $response->assertDontSee('Blocking issues', false);
        $response->assertSee('data-historical-preview-action="draft"', false);
        $response->assertSee('data-historical-preview-action="publish"', false);
    }

    /**
     * Hiding the buttons is a courtesy, not the control. Posting the intent by
     * hand must still be refused by the server and must still write nothing.
     */
    public function test_hiding_the_buttons_did_not_become_the_only_guard(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $before = TenantContext::runFor($shop->id, fn () => $this->snapshotCounts());

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->blockedPayload('BLOCKED-0002') + [
            'intent' => \App\Http\Requests\Historical\StoreManualHistoricalRequest::INTENT_PUBLISH,
        ])->assertRedirect();

        $after = TenantContext::runFor($shop->id, fn () => $this->snapshotCounts());
        $this->assertSame($before, $after, 'A blocked bill was written despite the server refusal.');
    }
}
