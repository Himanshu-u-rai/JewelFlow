<?php

namespace Tests\Feature\Historical;

use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\OnboardingBatch;
use App\Models\User;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Manual historical entry is its own workflow, not a one-document bulk import.
 *
 *   bulk file   : Upload → Map → Review batch → Publish batch     (unchanged)
 *   manual bill : Enter → Preview → Save draft | Save & publish   (this file)
 *
 * The internal one-document batch survives because acknowledgement,
 * immutability and publication all hang off it — but it is an implementation
 * detail from here on: a manual operator is never handed a batch URL, and
 * opening one by hand lands on the document instead.
 *
 * Every test here is a contract, not a smoke check. The direct Save & publish
 * path is the dangerous one — it collapses create + acknowledge + publish into
 * one request — so most of the file is about what that path must REFUSE.
 */
class HistoricalManualFlowSeparationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    /** Tables a historical record may write. Nothing else may move. */
    private const HISTORICAL_TABLES = [
        'historical_import_batches',
        'historical_sales_documents',
        'historical_sales_lines',
        'historical_import_rows',
    ];

    /** Live accounting/inventory. A historical record must never touch these. */
    private const LIVE_TABLES = [
        'invoices',
        'invoice_items',
        'invoice_payments',
        'stock_items',
        'cash_transactions',
        'customer_gold_transactions',
        'loyalty_transactions',
        'customer_opening_balances',
        'shop_counters',
        'invoice_number_events',
        'metal_movements',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A clean bill: dated today (so it predates nothing and postdates nothing),
     * numbered, with a real line and an explicit tax posture — the combination
     * that produces informational findings only.
     *
     * `tax_total => 0` is load-bearing and is NOT the same as omitting it.
     * HistoricalTaxNormalizer treats an absent figure as `tax_unknown` (a warning)
     * no matter what the operator confirms, because "the paper said nothing" is not
     * a claim anyone can confirm. Only an explicit zero can be promoted to
     * `not_applicable` by zero_tax_confirmed — which is what makes this fixture
     * warning-free, and every test below asserts that rather than assuming it.
     *
     * @return array<string, mixed>
     */
    private function cleanPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'QA-SEP-' . fake()->unique()->numberBetween(1, 999999),
            'document_date'            => now()->toDateString(),
            'source_system'            => 'Manual QA',
            'customer_name'            => 'QA Separation Customer',
            'taxable_amount'           => 1000,
            'tax_total'                => 0,
            'grand_total'              => 1000,
            'paid_amount'              => 1000,
            'outstanding_amount'       => 0,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed'       => '1',
            'lines'                    => [[
                'line_item_name' => 'QA Gold Item A',
                'line_quantity'  => 1,
                'line_total'     => 1000,
            ]],
        ], $override);
    }

    /** Header-only: guaranteed to raise the `header_only_document` warning. */
    private function warningPayload(array $override = []): array
    {
        return $this->cleanPayload(array_merge(['lines' => []], $override));
    }

    /** POST the preview and hand back the acknowledgement digest it rendered. */
    private function previewDigest(User $owner, array $payload): ?string
    {
        $response = $this->actingAs($owner)->post(route('historical.manual.preview'), $payload);
        $response->assertOk();

        return $response->viewData('warningDigest');
    }

    /** @return array{HistoricalImportBatch, HistoricalSalesDocument} */
    private function savedManualRecord(int $shopId): array
    {
        return TenantContext::runFor($shopId, function (): array {
            $document = HistoricalSalesDocument::query()->latest('id')->with('batch')->firstOrFail();

            return [$document->batch, $document];
        });
    }

    private function existingTables(array $names): array
    {
        return array_values(array_filter($names, fn (string $t): bool => Schema::hasTable($t)));
    }

    /** @return array<string, int> */
    private function counts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    private function assertNoHistoricalRecords(int $shopId, string $because): void
    {
        TenantContext::runFor($shopId, function () use ($because): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count(), $because);
            $this->assertSame(0, HistoricalImportBatch::query()->count(), $because);
        });
    }

    /** A CSV batch taken through upload + mapping, i.e. a genuine file import. */
    private function importCsvBatch(User $owner, int $shopId): HistoricalImportBatch
    {
        $file = UploadedFile::fake()->createWithContent(
            'legacy-separation.csv',
            "Invoice No,Date,Customer,Amount\nCSV-SEP-1,15/06/2024,Ramesh Patel,42000\n"
        );

        $this->actingAs($owner)->post(route('historical.upload.store'), [
            'file'          => $file,
            'source_system' => 'Legacy POS',
        ])->assertRedirect();

        $batch = TenantContext::runFor($shopId, fn () => HistoricalImportBatch::query()->latest('id')->firstOrFail());

        $this->actingAs($owner)->post(route('historical.batches.map.save', $batch->id), [
            'name'                => 'Legacy CSV separation profile',
            'source_system'       => 'Legacy POS',
            'layout_type'         => \App\Models\Historical\HistoricalImportProfile::LAYOUT_HEADER_ONLY,
            'header_row'          => 1,
            'date_format'         => \App\Services\Historical\HistoricalDateParser::FORMAT_DMY,
            'decimal_separator'   => '.',
            'thousands_separator' => ',',
            'tax_mode'            => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'mapping'             => [
                'original_document_number' => 'Invoice No',
                'document_date'            => 'Date',
                'customer_name'            => 'Customer',
                'grand_total'              => 'Amount',
            ],
            'column_decisions'    => [],
        ])->assertRedirect();

        return $batch->refresh();
    }

    // ------------------------------------------------------------------ 1. draft

    public function test_manual_save_draft_redirects_to_the_document_never_the_batch(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)
            ->post(route('historical.manual.store'), $this->cleanPayload(['intent' => 'draft']));

        [$batch, $document] = $this->savedManualRecord($shop->id);

        $response->assertRedirect(route('historical.documents.show', $document));
        $this->assertNotSame(route('historical.batches.show', $batch), $response->headers->get('Location'));
        $this->assertSame(HistoricalSalesDocument::STATUS_DRAFT, $document->status);
    }

    // ---------------------------------------------------------------- 2 & 4. publish

    public function test_manual_save_and_publish_redirects_to_the_published_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $payload = $this->cleanPayload(['intent' => 'publish']);

        // A clean bill has nothing to acknowledge — proven, not assumed.
        $this->assertNull($this->previewDigest($owner, $payload), 'Fixture is not warning-free.');

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);

        [$batch, $document] = $this->savedManualRecord($shop->id);

        $response->assertRedirect(route('historical.documents.show', $document));
        $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
        $this->assertNotNull($document->published_at);
        $this->assertSame($owner->id, (int) $document->published_by);
        $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHED, $batch->status);
    }

    // ------------------------------------------------------------- 3. permissions

    public function test_import_only_user_cannot_direct_publish(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);

        $this->actingAs($owner->fresh())
            ->post(route('historical.manual.store'), $this->cleanPayload(['intent' => 'publish']))
            ->assertForbidden();

        $this->assertNoHistoricalRecords($shop->id, 'A forbidden direct publish still wrote records.');

        // …and the same user may still save a draft.
        $this->actingAs($owner->fresh())
            ->post(route('historical.manual.store'), $this->cleanPayload(['intent' => 'draft']))
            ->assertRedirect();

        TenantContext::runFor($shop->id, fn () => $this->assertSame(
            1,
            HistoricalSalesDocument::query()->where('status', HistoricalSalesDocument::STATUS_DRAFT)->count()
        ));
    }

    // ---------------------------------------------------------- 5. acknowledgement

    public function test_current_warnings_must_be_acknowledged_before_direct_publish(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $payload = $this->warningPayload(['intent' => 'publish']);

        $digest = $this->previewDigest($owner, $payload);
        $this->assertNotNull($digest, 'Fixture must actually raise a warning to test the gate.');

        // Unacknowledged: refused, and nothing is written.
        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $payload)
            ->assertSessionHas('error');

        $this->assertNoHistoricalRecords($shop->id, 'An unacknowledged direct publish persisted records.');

        // Acknowledged with the digest the operator was actually shown: allowed.
        $this->actingAs($owner)->post(route('historical.manual.store'), $payload + [
            'acknowledge_warnings'        => '1',
            'acknowledged_warning_digest' => $digest,
        ])->assertRedirect();

        [, $document] = $this->savedManualRecord($shop->id);
        $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
    }

    // ------------------------------------------------------- 6. stale acknowledgement

    public function test_a_stale_acknowledgement_digest_is_refused(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // The operator acknowledged the header-only bill's warning set…
        $staleDigest = $this->previewDigest($owner, $this->warningPayload());
        $this->assertNotNull($staleDigest);

        // …then edited the bill so the warning set is no longer the same one.
        $edited = $this->warningPayload([
            'intent'          => 'publish',
            'document_date'   => '2019-04-02', // adds the before-shop warning
            'acknowledge_warnings'        => '1',
            'acknowledged_warning_digest' => $staleDigest,
        ]);

        $this->assertNotSame(
            $staleDigest,
            $this->previewDigest($owner, $edited),
            'Fixture must change the warning set for this test to mean anything.'
        );

        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $edited)
            ->assertSessionHas('error');

        $this->assertNoHistoricalRecords($shop->id, 'A stale acknowledgement published a changed bill.');
    }

    // ------------------------------------------------------------- 7. blocking

    public function test_blocking_findings_persist_nothing_in_direct_publish_mode(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->cleanPayload([
            'intent'        => 'publish',
            'document_date' => now()->addYear()->toDateString(), // future date: blocking
        ]))->assertSessionHas('error');

        $this->assertNoHistoricalRecords($shop->id, 'A blocked direct publish left records behind.');
    }

    // ---------------------------------------------------------- 8. atomic failure

    public function test_a_failed_direct_publication_leaves_no_partial_state(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->instance(
            HistoricalDocumentLifecycleService::class,
            new class(new \App\Services\Historical\HistoricalOpeningBalanceEvaluator()) extends HistoricalDocumentLifecycleService
            {
                public function publish(HistoricalImportBatch $batch, ?int $actorId = null): HistoricalImportBatch
                {
                    throw new RuntimeException('Simulated publication failure.');
                }
            }
        );

        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $this->cleanPayload(['intent' => 'publish']))
            ->assertSessionHas('error');

        // Not "a draft was left behind" — nothing at all. A half-saved bill that
        // silently became a draft is exactly the misleading state to avoid.
        $this->assertNoHistoricalRecords($shop->id, 'A failed publish left a partial record.');
    }

    // ------------------------------------------------------------ 9. duplicates

    public function test_duplicate_detection_still_blocks_a_manual_direct_publish(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $first = $this->cleanPayload(['intent' => 'publish']);
        $this->actingAs($owner)->post(route('historical.manual.store'), $first)->assertRedirect();

        TenantContext::runFor($shop->id, fn () => $this->assertSame(1, HistoricalSalesDocument::query()->count()));

        // Same number, same financial year — the number guard must refuse it.
        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $first)
            ->assertSessionHas('error');

        TenantContext::runFor($shop->id, fn () => $this->assertSame(
            1,
            HistoricalSalesDocument::query()->count(),
            'A duplicate manual bill was published a second time.'
        ));
    }

    // -------------------------------------------------- 10. opening-balance overlap

    public function test_high_opening_balance_overlap_blocks_publishing_a_manual_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->cleanPayload([
            'intent'             => 'draft',
            'document_date'      => '2023-06-15',
            'paid_amount'        => 0,
            'outstanding_amount' => 1000,
        ]))->assertRedirect();

        [, $document] = $this->savedManualRecord($shop->id);

        $customer = $this->createCustomer($shop->id);
        TenantContext::runFor($shop->id, function () use ($shop, $customer): void {
            $onboarding = new OnboardingBatch();
            $onboarding->forceFill([
                'shop_id'    => $shop->id,
                'as_of_date' => '2024-03-31',
                'start_date' => '2024-03-31',
                'status'     => 'locked',
            ])->save();

            $row = new CustomerOpeningBalance();
            $row->forceFill([
                'shop_id'             => $shop->id,
                'customer_id'         => $customer->id,
                'onboarding_batch_id' => $onboarding->id,
                'direction'           => CustomerOpeningBalance::DIRECTION_RECEIVABLE,
                'amount'              => 5000,
                'as_of_date'          => '2024-03-31',
            ])->save();
        });

        $this->actingAs($owner)->post(route('historical.documents.link-customer', $document->id), [
            'customer_id' => $customer->id,
        ])->assertRedirect();

        $this->actingAs($owner)
            ->post(route('historical.documents.publish', $document->id))
            ->assertSessionHas('error');

        TenantContext::runFor($shop->id, fn () => $this->assertSame(
            HistoricalSalesDocument::STATUS_DRAFT,
            HistoricalSalesDocument::query()->findOrFail($document->id)->status,
            'A HIGH unresolved opening-balance overlap was published.'
        ));
    }

    // ----------------------------------------------------- 11 & 12. batch URL

    public function test_a_manual_batch_url_redirects_to_its_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->cleanPayload())->assertRedirect();
        [$batch, $document] = $this->savedManualRecord($shop->id);

        $this->actingAs($owner)
            ->get(route('historical.batches.show', $batch->id))
            ->assertRedirect(route('historical.documents.show', $document));
    }

    public function test_a_file_import_batch_url_still_renders_the_batch_page(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->importCsvBatch($owner, $shop->id);

        $this->actingAs($owner)->get(route('historical.batches.show', $batch->id))->assertOk();
    }

    // ------------------------------------------------------------ 13. cross-shop

    public function test_manual_batch_and_document_are_not_reachable_from_another_shop(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();

        $this->actingAs($ownerA)->post(route('historical.manual.store'), $this->cleanPayload())->assertRedirect();
        [$batchA, $documentA] = $this->savedManualRecord($shopA->id);

        $this->actingAs($ownerB)->get(route('historical.batches.show', $batchA->id))->assertNotFound();
        $this->actingAs($ownerB)->get(route('historical.documents.show', $documentA->id))->assertNotFound();
        $this->actingAs($ownerB)->post(route('historical.documents.publish', $documentA->id))->assertNotFound();
        $this->actingAs($ownerB)->post(route('historical.documents.acknowledge', $documentA->id))->assertNotFound();

        TenantContext::runFor($shopB->id, fn () => $this->assertSame(0, HistoricalSalesDocument::query()->count()));
    }

    // -------------------------------------------------------- 14 & 15. preview

    public function test_a_get_on_the_preview_url_redirects_instead_of_405(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->get(route('historical.manual.preview.expired'));

        $response->assertRedirect(route('historical.manual.create'));
        $this->assertSame(
            route('historical.manual.create'),
            $response->headers->get('Location'),
            'The expired-preview fallback must not carry submitted values in the URL.'
        );

        // Following the redirect, not just inspecting the session, is the point of
        // this assertion — and it must pin the *channel*, not only the text.
        // layouts/app.blade.php emits a `flash-warning` meta tag, but nothing
        // consumes it: showFlashToasts() in resources/js/app.js reads only
        // `flash-success`/`flash-error`, and <x-app-alerts> renders only `success`
        // and `error`. So a message flashed as `warning` satisfies both
        // assertSessionHas('warning') and a bare assertSee() of its text (it is
        // sitting in a dead <head> meta tag) while the operator sees nothing at all.
        // Asserting the flash-error meta proves it reaches a surface that displays.
        $this->actingAs($owner)
            ->get(route('historical.manual.create'))
            ->assertOk()
            ->assertSee('meta name="flash-error"', false)
            ->assertSee('That preview has expired', false);
    }

    public function test_posting_the_preview_writes_nothing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $tables = TenantContext::runFor($shop->id, fn () => $this->existingTables(
            array_merge(self::HISTORICAL_TABLES, self::LIVE_TABLES)
        ));
        $before = TenantContext::runFor($shop->id, fn () => $this->counts($tables));

        $this->actingAs($owner)->post(route('historical.manual.preview'), $this->cleanPayload())->assertOk();

        $after = TenantContext::runFor($shop->id, fn () => $this->counts($tables));
        $this->assertSame($before, $after, 'Preview is not zero-write.');
    }

    // ------------------------------------------------------- 16. no side effects

    public function test_neither_draft_save_nor_direct_publish_touches_live_accounting(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $live   = TenantContext::runFor($shop->id, fn () => $this->existingTables(self::LIVE_TABLES));
        $before = TenantContext::runFor($shop->id, fn () => $this->counts($live));

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->cleanPayload(['intent' => 'draft']))
            ->assertRedirect();
        $this->actingAs($owner)->post(route('historical.manual.store'), $this->cleanPayload(['intent' => 'publish']))
            ->assertRedirect();

        $after = TenantContext::runFor($shop->id, fn () => $this->counts($live));
        $this->assertSame($before, $after, 'A manual historical record moved live accounting or inventory.');
    }

    // ------------------------------------------------------- 17. batch idempotency

    public function test_file_import_batch_publication_remains_idempotent(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $batch = $this->importCsvBatch($owner, $shop->id);

        if ((int) $batch->warning_count > 0) {
            $this->actingAs($owner)->post(route('historical.batches.acknowledge', $batch->id))->assertRedirect();
        }

        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();
        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch): void {
            $this->assertSame(1, HistoricalSalesDocument::query()->where('historical_import_batch_id', $batch->id)->count());
            $this->assertSame(
                HistoricalImportBatch::STATUS_PUBLISHED,
                HistoricalImportBatch::query()->findOrFail($batch->id)->status
            );
        });
    }

    // ------------------------------------------------------------- 18. blank rows

    public function test_blank_padded_item_rows_are_still_filtered(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $blank = array_fill_keys(
            ['line_item_name', 'line_sku', 'line_quantity', 'line_net_weight', 'line_total'],
            ''
        );

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->cleanPayload([
            'intent' => 'draft',
            'lines'  => [
                ['line_item_name' => 'QA Gold Item A', 'line_quantity' => 1, 'line_total' => 1000],
                $blank,
                $blank,
                $blank,
            ],
        ]))->assertRedirect();

        [, $document] = $this->savedManualRecord($shop->id);

        TenantContext::runFor($shop->id, fn () => $this->assertSame(
            1,
            $document->lines()->count(),
            'Empty padded form rows were saved as item lines.'
        ));
    }
}
