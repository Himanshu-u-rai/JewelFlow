<?php

namespace Tests\Feature\Historical;

use App\Http\Controllers\Historical\HistoricalDocumentController;
use App\Models\CustomerOpeningBalance;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\OnboardingBatch;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use App\Services\Historical\HistoricalOpeningBalanceEvaluator;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3, Commit 3 — a historical bill's receivable may already be counted
 * inside the linked customer's opening balance. Every test here proves one
 * locked rule mechanically: unlinked is always NONE, MAX(as_of_date) wins
 * when a customer has several opening-balance rows, a date strictly after
 * the cutoff is NONE, only HIGH (never MEDIUM) blocks publish, resolution is
 * draft-only metadata that never touches money, and the evaluator is
 * shop-scoped like every other historical query.
 */
class HistoricalOpeningBalanceOverlapTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const MONEY_TABLES = [
        'customer_opening_balances',
        'invoices',
        'invoice_items',
        'cash_transactions',
        'invoice_payments',
        'customer_gold_transactions',
        'loyalty_transactions',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ------------------------------------------------------------------ helpers

    private function makeBatch(int $shopId): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch();
        $batch->forceFill([
            'shop_id'       => $shopId,
            'label'         => 'FY 2023-24',
            'source_system' => 'Manual',
            'status'        => HistoricalImportBatch::STATUS_DRAFT,
        ])->save();

        return $batch;
    }

    private function makeDraftDocument(int $shopId, int $batchId, array $attrs = []): HistoricalSalesDocument
    {
        $number     = $attrs['original_document_number'] ?? ('DOC-' . Str::random(8));
        $date       = $attrs['document_date'] ?? '2023-11-04';
        $normalized = HistoricalDocumentIdentity::normalizeNumber($number);
        $fy         = HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable($date));

        $document = new HistoricalSalesDocument();
        $document->forceFill(array_merge([
            'shop_id'                              => $shopId,
            'historical_import_batch_id'           => $batchId,
            'historical_reference'                 => (string) Str::uuid(),
            'original_document_number'             => $number,
            'original_document_number_normalized'  => $normalized,
            'document_type'                        => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date'                         => $date,
            'financial_year'                        => $fy,
            'source_system'                         => 'Manual',
            'customer_snapshot'                     => ['name' => 'Ramesh Patel'],
            'tax_mode'                               => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness'                       => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total'                            => 25000.00,
            'status'                                 => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint'                    => hash('sha256', (string) Str::uuid()),
        ], $attrs))->save();

        return $document;
    }

    /** A receivable opening-balance row, backed by a throwaway OnboardingBatch (FK-required). */
    private function makeOpeningBalance(int $shopId, int $customerId, string $asOfDate, float $amount = 0.0): CustomerOpeningBalance
    {
        // status: locked — a terminal state, so it doesn't collide with the
        // "one active batch per shop" unique index when a test needs several
        // opening-balance rows (each backed by its own throwaway batch) for
        // the same shop.
        $onboarding = new OnboardingBatch();
        $onboarding->forceFill([
            'shop_id'    => $shopId,
            'as_of_date' => $asOfDate,
            'start_date' => $asOfDate,
            'status'     => 'locked',
        ])->save();

        $row = new CustomerOpeningBalance();
        $row->forceFill([
            'shop_id'             => $shopId,
            'customer_id'         => $customerId,
            'onboarding_batch_id' => $onboarding->id,
            'direction'           => CustomerOpeningBalance::DIRECTION_RECEIVABLE,
            'amount'              => $amount,
            'as_of_date'          => $asOfDate,
        ])->save();

        return $row;
    }

    private function moneyTableCounts(): array
    {
        $counts = [];
        foreach (self::MONEY_TABLES as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    // -------------------------------------------------------------- 1. unlinked

    public function test_unlinked_document_is_always_none(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id, ['document_date' => '2022-06-01']);

            $evaluator = app(HistoricalOpeningBalanceEvaluator::class);
            $this->assertSame(HistoricalOpeningBalanceEvaluator::NONE, $evaluator->evaluate($document));
        });
    }

    // ---------------------------------------------------------------- 2. HIGH

    public function test_on_or_before_cutoff_with_outstanding_is_high(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date'                => '2022-12-31',
                'customer_id'                   => $customer->id,
                'outstanding_amount_snapshot'   => 1000.00,
            ]);

            $evaluator = app(HistoricalOpeningBalanceEvaluator::class);
            $this->assertSame(HistoricalOpeningBalanceEvaluator::HIGH, $evaluator->evaluate($document));
        });
    }

    // -------------------------------------------------------------- 3. MEDIUM

    public function test_on_or_before_cutoff_with_zero_or_unknown_outstanding_is_medium(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);
            $evaluator = app(HistoricalOpeningBalanceEvaluator::class);
            $batch = $this->makeBatch($shop->id);

            $zero = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 0.00,
            ]);
            $this->assertSame(HistoricalOpeningBalanceEvaluator::MEDIUM, $evaluator->evaluate($zero));

            $unknown = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => null,
            ]);
            $this->assertSame(HistoricalOpeningBalanceEvaluator::MEDIUM, $evaluator->evaluate($unknown));
        });
    }

    // ------------------------------------------------------ 4. max(as_of_date)

    public function test_multiple_opening_balance_rows_use_the_latest_as_of_date(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2021-01-01', 100.00);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-06-30', 200.00); // the real cutoff

            $batch = $this->makeBatch($shop->id);

            // Strictly after the EARLIER row but on the LATER (real) cutoff.
            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2023-06-30', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 50.00,
            ]);

            $evaluator = app(HistoricalOpeningBalanceEvaluator::class);
            $this->assertSame(HistoricalOpeningBalanceEvaluator::HIGH, $evaluator->evaluate($document));
        });
    }

    // ------------------------------------------------- 5. strictly after cutoff

    public function test_document_date_strictly_after_the_cutoff_is_none(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2023-01-02', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
            ]);

            $evaluator = app(HistoricalOpeningBalanceEvaluator::class);
            $this->assertSame(HistoricalOpeningBalanceEvaluator::NONE, $evaluator->evaluate($document));
        });
    }

    // --------------------------------------------------- 6. HIGH blocks publish

    public function test_unresolved_high_overlap_blocks_batch_publish(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $batch = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);
            $batch->forceFill([
                'status'               => HistoricalImportBatch::STATUS_REVIEW,
                'preview_generated_at' => now(),
                'blocking_count'       => 0,
                'warning_count'        => 0,
            ])->save();

            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
                'opening_balance_overlap' => true,
            ]);

            // blockedFromPublishing() queries the batch's documents through the
            // BelongsToShop scope, so it must be evaluated inside the tenant
            // context — same as every other shop-scoped read in this suite.
            $this->assertNotNull($batch->blockedFromPublishing());

            return $batch;
        });

        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $this->assertSame(HistoricalImportBatch::STATUS_REVIEW, $batch->fresh()->status, 'Must not publish while a HIGH overlap is unresolved.');
        });
    }

    // ------------------------------------------------ 7. MEDIUM never blocks

    public function test_medium_overlap_never_blocks_publish(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $batch = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);
            $batch->forceFill([
                'status'               => HistoricalImportBatch::STATUS_REVIEW,
                'preview_generated_at' => now(),
                'blocking_count'       => 0,
                'warning_count'        => 0,
            ])->save();

            // MEDIUM: on/before cutoff, but zero outstanding — opening_balance_overlap
            // is still true (it "overlaps"), only the HIGH-specific field differs.
            $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 0.00,
                'opening_balance_overlap' => true,
            ]);

            return $batch;
        });

        $this->assertNull($batch->blockedFromPublishing());

        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHED, $batch->fresh()->status);
        });
    }

    // ------------------------------------------- 8. resolution unblocks publish

    public function test_resolving_the_high_overlap_unblocks_publish(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        [$batch, $document] = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);
            $batch->forceFill([
                'status'               => HistoricalImportBatch::STATUS_REVIEW,
                'preview_generated_at' => now(),
                'blocking_count'       => 0,
                'warning_count'        => 0,
            ])->save();

            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
                'opening_balance_overlap' => true,
            ]);

            return [$batch, $document];
        });

        $service = app(HistoricalDocumentLifecycleService::class);
        $service->resolveOpeningBalance(
            $document,
            HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
            $owner->id
        );

        $this->assertNull($batch->fresh()->blockedFromPublishing());

        $this->actingAs($owner)->post(route('historical.batches.publish', $batch->id))->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($batch) {
            $this->assertSame(HistoricalImportBatch::STATUS_PUBLISHED, $batch->fresh()->status);
        });
    }

    // ----------------------------------------- 9. resolution is draft-only

    public function test_resolution_is_rejected_once_the_document_is_published(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        $document = TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id);
            $service->publish($batch, $owner->id);

            return $document->fresh();
        });

        $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);

        $this->expectException(LogicException::class);
        $service->resolveOpeningBalance(
            $document,
            HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
            $owner->id
        );
    }

    // ------------------------------------------- 10. no third bypass value

    public function test_resolution_rejects_any_value_other_than_the_two_allowed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id);
        });

        $this->expectException(LogicException::class);
        $service->resolveOpeningBalance($document, 'not_applicable', $owner->id);
    }

    public function test_resolution_rejected_via_http_for_an_invalid_value(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id);
        });

        $this->actingAs($owner)->post(
            route('historical.documents.resolve-opening-balance', $document->id),
            ['resolution' => 'not_applicable']
        )->assertSessionHasErrors('resolution');

        TenantContext::runFor($shop->id, function () use ($document) {
            $this->assertNull($document->fresh()->opening_balance_resolution);
        });
    }

    // --------------------------------------- 11. linkCustomer resets resolution

    public function test_changing_the_linked_customer_resets_a_stale_resolution(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        $document = TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $customerA = $this->createCustomer($shop->id);
            $customerB = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customerA->id, '2023-01-01', 500.00);

            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31',
                'outstanding_amount_snapshot' => 1000.00,
            ]);

            $service->linkCustomer($document, $customerA->id);
            $this->assertTrue($document->fresh()->opening_balance_overlap);

            $service->resolveOpeningBalance($document->fresh(), HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE, $owner->id);
            $this->assertNotNull($document->fresh()->opening_balance_resolution);

            // Re-link to a customer with no opening-balance overlap. Re-fetch
            // first — a real request always binds a fresh model, and this
            // proves the reset (not a stale-instance dirty-tracking quirk).
            $service->linkCustomer($document->fresh(), $customerB->id);

            return $document->fresh();
        });

        $this->assertFalse((bool) $document->opening_balance_overlap);
        $this->assertNull($document->opening_balance_resolution);
        $this->assertNull($document->opening_balance_resolved_by);
        $this->assertNull($document->opening_balance_resolved_at);
    }

    // --------------------------------- 12. publish-time recheck (defense in depth)

    public function test_publish_time_recheck_refuses_a_high_overlap_even_if_the_batch_gate_is_bypassed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        // Every call below is shop-scoped (BelongsToShop), including
        // claimForPublishing()'s own query — same as the batch-level gate
        // test above, this must all run inside the tenant context.
        TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);
            $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
                'opening_balance_overlap' => true,
            ]);

            // Call the claimed-publish WORK directly, bypassing
            // blockedFromPublishing() entirely — this is the codepath's own
            // last line of defense, not the batch-level gate the controller
            // normally checks first.
            $service->claimForPublishing($batch);

            $this->expectException(LogicException::class);
            $service->publishClaimed($batch, $owner->id);
        });
    }

    // ---------------------------------------------------- 13. shop-scoping

    public function test_evaluator_never_reads_another_shops_opening_balance_row(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        // A customer that (by id collision or otherwise) exists in shop A, with an
        // opening-balance row belonging to shop B — must never be seen by shop A's evaluation.
        $customerA = TenantContext::runFor($shopA->id, fn () => $this->createCustomer($shopA->id));

        TenantContext::runFor($shopB->id, function () use ($shopB, $customerA) {
            // A same-id row in shop B's own scope, deliberately reusing the numeric
            // customer id from shop A to prove the query is shop_id-scoped, not just
            // customer_id-scoped (BelongsToShop already scopes reads/writes by shop,
            // withoutTenant() + explicit shop_id is what the evaluator relies on).
            $foreignCustomer = $this->createCustomer($shopB->id);
            $this->makeOpeningBalance($shopB->id, $foreignCustomer->id, '2023-01-01', 500.00);
        });

        TenantContext::runFor($shopA->id, function () use ($shopA, $customerA) {
            $batch    = $this->makeBatch($shopA->id);
            $document = $this->makeDraftDocument($shopA->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customerA->id,
                'outstanding_amount_snapshot' => 1000.00,
            ]);

            $evaluator = app(HistoricalOpeningBalanceEvaluator::class);
            $this->assertSame(HistoricalOpeningBalanceEvaluator::NONE, $evaluator->evaluate($document));
        });
    }

    // ---------------------------------------------- 14. zero money-table writes

    public function test_evaluate_and_resolve_touch_no_balance_ledger_or_operational_table(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
                // A HIGH scenario that is about to be resolved — matches every
                // other resolve-calling fixture in this file, and satisfies the
                // 2026_09_17_000200 CHECK (resolution requires overlap = true).
                'opening_balance_overlap' => true,
            ]);

            $before = $this->moneyTableCounts();

            $evaluator = app(HistoricalOpeningBalanceEvaluator::class);
            $evaluator->evaluate($document);
            $service->resolveOpeningBalance($document, HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED, $owner->id);

            $this->assertSame($before, $this->moneyTableCounts());
        });
    }

    // ----------------------------------------------------------------- 15. UI

    public function test_document_view_shows_blocking_high_warning_and_resolution_form_on_a_draft(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
                'opening_balance_overlap' => true,
            ]);
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $response->assertOk();
        $response->assertSee('HIGH');
        $response->assertSee('Already included in opening balance');
        $response->assertSee('Separate from opening balance');
        $response->assertSee(route('historical.documents.resolve-opening-balance', $document), false);
    }

    public function test_document_view_shows_medium_as_informational_only_with_no_resolution_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 0.00,
                'opening_balance_overlap' => true,
            ]);
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $response->assertOk();
        $response->assertSee('MEDIUM');
        $response->assertDontSee(route('historical.documents.resolve-opening-balance', $document), false);
    }

    public function test_document_view_shows_resolution_read_only_once_resolved_or_published(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        $document = TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
                'opening_balance_overlap' => true,
            ]);

            $service->resolveOpeningBalance($document, HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE, $owner->id);

            return $document->fresh();
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $response->assertOk();
        $response->assertSee('Resolved:');
        $response->assertSee('Separate from opening balance');
        $response->assertDontSee('Confirm resolution');
    }

    // ------------------------------------- 16. permission boundary (Correction 1)

    /**
     * Resolving a HIGH overlap clears a publish gate — it is a publish-control
     * action, not an import action. An import-only user (historical.view +
     * historical.import, no historical.publish) must be refused at the route
     * and must not even see the form.
     */
    public function test_import_only_user_is_forbidden_from_resolving_and_sees_no_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
                'opening_balance_overlap' => true,
            ]);
        });

        // No form on the page — the import-only operator sees the HIGH warning
        // but never a way to act on it.
        $view = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $view->assertOk();
        $view->assertSee('HIGH');
        $view->assertDontSee('Confirm resolution');

        $this->actingAs($owner)->post(
            route('historical.documents.resolve-opening-balance', $document->id),
            ['resolution' => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE]
        )->assertForbidden();

        TenantContext::runFor($shop->id, function () use ($document) {
            $this->assertNull($document->fresh()->opening_balance_resolution, 'A forbidden request must never write.');
        });
    }

    /**
     * A publish-authorized user (historical.view + historical.publish, no
     * historical.import) must be able to see and submit the resolution form —
     * the permission that gates this is publish, not import.
     */
    public function test_publish_only_user_may_view_and_submit_the_resolution_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.publish']);

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 1000.00,
                'opening_balance_overlap' => true,
            ]);
        });

        $view = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $view->assertOk();
        $view->assertSee('Confirm resolution');

        $before = $this->moneyTableCounts();

        $this->actingAs($owner)->post(
            route('historical.documents.resolve-opening-balance', $document->id),
            ['resolution' => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE]
        )->assertRedirect();

        $this->assertSame($before, $this->moneyTableCounts(), 'Resolving must never touch a money table.');

        TenantContext::runFor($shop->id, function () use ($document) {
            $this->assertSame(
                HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
                $document->fresh()->opening_balance_resolution
            );
        });
    }

    /**
     * The controller's own `$this->authorize('historical.publish')` call must
     * refuse an import-only user even with route middleware out of the
     * picture entirely — proves the guard is not solely a routing artifact.
     */
    public function test_controller_level_authorization_is_enforced_independently_of_route_middleware(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($owner, ['historical.view', 'historical.import']);

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31',
                'opening_balance_overlap' => true,
            ]);
        });

        $this->actingAs($owner);

        $controller = $this->app->make(HistoricalDocumentController::class);
        $request = Request::create('/x', 'POST', [
            'resolution' => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
        ]);
        $request->setUserResolver(fn () => $owner);

        $this->expectException(AuthorizationException::class);

        TenantContext::runFor($shop->id, function () use ($controller, $request, $document) {
            $controller->resolveOpeningBalance($request, $document);
        });
    }

    // -------------------------------------- 17. resolution integrity (Correction 2)

    /** A document with no overlap at all (NONE) has nothing to resolve. */
    public function test_resolving_a_none_document_is_rejected(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            // No customer linked, no opening-balance row at all -> NONE.
            return $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31',
            ]);
        });

        $this->expectException(LogicException::class);
        $service->resolveOpeningBalance(
            $document,
            HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
            $owner->id
        );
    }

    /**
     * Defense in depth below the app layer: the CHECK constraint added in
     * 2026_09_17_000200 rejects a resolution row-value at the database even if
     * the service guard were bypassed entirely (a raw UPDATE, a bad migration,
     * a different code path).
     */
    public function test_none_document_cannot_persist_a_resolution_at_the_database_level(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31',
                'opening_balance_overlap' => false,
            ]);
        });

        $this->expectException(QueryException::class);

        DB::table('historical_sales_documents')
            ->where('id', $document->id)
            ->update([
                'opening_balance_resolution'   => HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_SEPARATE,
                'opening_balance_resolved_by'  => $owner->id,
                'opening_balance_resolved_at'  => now(),
            ]);
    }

    /** MEDIUM is not blocking, but the service still allows it to be resolved. */
    public function test_medium_overlap_may_also_be_resolved(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        $document = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $this->makeOpeningBalance($shop->id, $customer->id, '2023-01-01', 500.00);

            $batch = $this->makeBatch($shop->id);

            return $this->makeDraftDocument($shop->id, $batch->id, [
                'document_date' => '2022-12-31', 'customer_id' => $customer->id,
                'outstanding_amount_snapshot' => 0.00,
                'opening_balance_overlap' => true,
            ]);
        });

        $service->resolveOpeningBalance(
            $document,
            HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED,
            $owner->id
        );

        TenantContext::runFor($shop->id, function () use ($document) {
            $this->assertSame(
                HistoricalSalesDocument::OPENING_BALANCE_RESOLUTION_INCLUDED,
                $document->fresh()->opening_balance_resolution
            );
        });
    }
}
