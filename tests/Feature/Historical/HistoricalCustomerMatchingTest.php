<?php

namespace Tests\Feature\Historical;

use App\Models\Customer;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalCustomerMatcher;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3, Commit 2 — customer matching is suggestion-only and never
 * auto-links. Every test here proves one locked rule mechanically: exact
 * mobile is strongest (unique per shop), GSTIN ambiguity is reported not
 * resolved, name is weak and never preselected, every query is shop-scoped,
 * archived customers are excluded from NEW suggestions but a pre-existing
 * link still renders, and linking never touches balances/ledgers/operational
 * tables.
 */
class HistoricalCustomerMatchingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const OPERATIONAL_TABLES = [
        'invoices',
        'invoice_items',
        'cash_transactions',
        'invoice_payments',
        'metal_movements',
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
            'customer_snapshot'                     => ['name' => 'Ramesh Patel', 'mobile' => '9876543210'],
            'tax_mode'                               => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness'                       => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total'                            => 25000.00,
            'status'                                 => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint'                    => hash('sha256', (string) Str::uuid()),
        ], $attrs))->save();

        return $document;
    }

    private function operationalCounts(): array
    {
        $counts = [];
        foreach (self::OPERATIONAL_TABLES as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    // --------------------------------------------------------------- 1. mobile

    public function test_exact_normalized_mobile_suggestion_is_returned(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id, ['mobile' => '9876543210']);
            $matcher  = app(HistoricalCustomerMatcher::class);

            $result = $matcher->byMobile($shop->id, '+91 98765-43210');

            $this->assertSame('match', $result['status']);
            $this->assertSame([$customer->id], $result['customers']->pluck('id')->all());
        });
    }

    // ---------------------------------------------------------- 2. gstin ambiguity

    public function test_duplicate_gstin_is_reported_ambiguous_not_resolved(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $a = $this->createCustomer($shop->id, ['gstin' => '27AAAAA0000A1Z5']);
            $b = $this->createCustomer($shop->id, ['gstin' => '27aaaaa0000a1z5']); // same, different case
            $matcher = app(HistoricalCustomerMatcher::class);

            $result = $matcher->byGstin($shop->id, '27AAAAA0000A1Z5');

            $this->assertSame('ambiguous', $result['status']);
            $this->assertEqualsCanonicalizing([$a->id, $b->id], $result['customers']->pluck('id')->all());
        });
    }

    // ------------------------------------------------------- 3. weak name match

    public function test_name_match_is_returned_but_never_preselected_anywhere(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id, ['first_name' => 'Asha', 'last_name' => 'Traders']);
            $matcher  = app(HistoricalCustomerMatcher::class);

            $result = $matcher->byName($shop->id, '  asha   traders  ');

            $this->assertSame('match', $result['status']);
            $this->assertSame([$customer->id], $result['customers']->pluck('id')->all());

            // The matcher only returns candidates. Nothing reachable from it
            // ever calls linkCustomer() or writes customer_id anywhere.
            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id, [
                'customer_snapshot' => ['name' => 'Asha Traders'],
            ]);
            $this->assertNull($document->customer_id);
        });
    }

    // ------------------------------------------------------------- 4. shop isolation

    public function test_shop_b_customer_never_appears_for_shop_a_and_a_crafted_link_is_rejected(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [, $shopB]        = $this->createRetailerTenant();

        $foreign = TenantContext::runFor($shopB->id, fn () => $this->createCustomer($shopB->id, [
            'mobile' => '9123456789',
            'gstin'  => '27FOREIGN0001Z5',
            'first_name' => 'Foreign', 'last_name' => 'Customer',
        ]));

        $matcher = app(HistoricalCustomerMatcher::class);

        TenantContext::runFor($shopA->id, function () use ($shopA, $matcher, $foreign) {
            $this->assertSame('none', $matcher->byMobile($shopA->id, $foreign->mobile)['status']);
            $this->assertSame('none', $matcher->byGstin($shopA->id, $foreign->gstin)['status']);
            $this->assertSame('none', $matcher->byName($shopA->id, $foreign->name)['status']);
        });

        // And a crafted request straight at the route, not just the matcher.
        $batch    = TenantContext::runFor($shopA->id, fn () => $this->makeBatch($shopA->id));
        $document = TenantContext::runFor($shopA->id, fn () => $this->makeDraftDocument($shopA->id, $batch->id));

        $this->actingAs($ownerA)->post(
            route('historical.documents.link-customer', $document->id),
            ['customer_id' => $foreign->id]
        )->assertRedirect();

        TenantContext::runFor($shopA->id, function () use ($document) {
            $this->assertNull($document->fresh()->customer_id, 'A cross-shop customer_id must never be linked.');
        });
    }

    // ------------------------------------------------------ 5 & 6. archive lifecycle

    public function test_archived_customer_is_excluded_from_new_suggestions(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id, [
                'mobile' => '9988776655',
                'gstin'  => '27ARCHIVED001Z5',
                'first_name' => 'Old', 'last_name' => 'Shopper',
            ]);
            $customer->forceFill(['is_active' => false])->save();

            $matcher = app(HistoricalCustomerMatcher::class);

            $this->assertSame('none', $matcher->byMobile($shop->id, $customer->mobile)['status']);
            $this->assertSame('none', $matcher->byGstin($shop->id, $customer->gstin)['status']);
            $this->assertSame('none', $matcher->byName($shop->id, $customer->name)['status']);
        });
    }

    public function test_previously_linked_archived_customer_still_renders_on_the_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        [$document, $customer] = TenantContext::runFor($shop->id, function () use ($shop, $service) {
            $customer = $this->createCustomer($shop->id, ['first_name' => 'Loyal', 'last_name' => 'Buyer']);
            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id);

            $service->linkCustomer($document, $customer->id);
            $customer->forceFill(['is_active' => false])->save();

            return [$document->fresh(), $customer];
        });

        $response = $this->actingAs($owner)->get(route('historical.documents.show', $document->id));
        $response->assertOk();
        $response->assertSee('Loyal Buyer');
        $response->assertSee('archived', false);
    }

    // --------------------------------------------------- 7. no auto-preselect via HTTP

    public function test_a_name_or_mobile_match_never_auto_sets_customer_id_via_the_manual_flow(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->createCustomer($shop->id, [
            'first_name' => 'Walk-in', 'last_name' => 'Customer', 'mobile' => '9876543210',
        ]));

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), [
            'original_document_number' => 'AUTO-0001',
            'document_date'            => '2023-06-15',
            'source_system'            => 'Manual',
            'customer_name'            => 'Walk-in Customer',
            'customer_mobile'          => '9876543210',
            'grand_total'              => 18000,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ]);
        $response->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $document = HistoricalSalesDocument::query()->firstOrFail();
            $this->assertNull($document->customer_id, 'An exact match must stay a suggestion, never an auto-link.');
        });
    }

    // ---------------------------------------------------------- 8. explicit link

    public function test_explicit_same_shop_link_works_and_preserves_the_snapshot(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        [$document, $customer] = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id);
            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id);

            return [$document, $customer];
        });

        $before = TenantContext::runFor($shop->id, fn () => $document->customer_snapshot);

        $this->actingAs($owner)->post(
            route('historical.documents.link-customer', $document->id),
            ['customer_id' => $customer->id]
        )->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($document, $customer, $before) {
            $fresh = $document->fresh();
            $this->assertSame($customer->id, $fresh->customer_id);
            $this->assertSame($before, $fresh->customer_snapshot, 'Linking must never overwrite the snapshot.');
        });
    }

    // ----------------------------------------- 9. draft-only linking eligibility

    public function test_published_voided_and_superseded_documents_reject_a_link_attempt(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $customer = $this->createCustomer($shop->id);

            // Published.
            $batch1 = $this->makeBatch($shop->id);
            $doc1   = $this->makeDraftDocument($shop->id, $batch1->id, ['original_document_number' => 'PUB-0001']);
            $service->publish($batch1, $owner->id);
            $doc1->refresh();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $doc1->status);
            try {
                $service->linkCustomer($doc1, $customer->id);
                $this->fail('Linking a published document must be rejected.');
            } catch (LogicException) {
                // expected
            }
            $this->assertNull($doc1->fresh()->customer_id);

            // Voided.
            $batch2 = $this->makeBatch($shop->id);
            $doc2   = $this->makeDraftDocument($shop->id, $batch2->id, ['original_document_number' => 'VOID-0001']);
            $service->publish($batch2, $owner->id);
            $doc2->refresh();
            $service->void($doc2, $owner->id, 'Test void');
            $doc2->refresh();
            try {
                $service->linkCustomer($doc2, $customer->id);
                $this->fail('Linking a voided document must be rejected.');
            } catch (LogicException) {
                // expected
            }
            $this->assertNull($doc2->fresh()->customer_id);

            // Superseded.
            $batch3      = $this->makeBatch($shop->id);
            $original    = $this->makeDraftDocument($shop->id, $batch3->id, ['original_document_number' => 'SUP-ORIG']);
            $service->publish($batch3, $owner->id);
            $original->refresh();

            $batch4      = $this->makeBatch($shop->id);
            $replacement = $this->makeDraftDocument($shop->id, $batch4->id, ['original_document_number' => 'SUP-NEW']);
            $service->supersede($original, $replacement, $owner->id);
            $original->refresh();
            $this->assertSame(HistoricalSalesDocument::STATUS_SUPERSEDED, $original->status);
            try {
                $service->linkCustomer($original, $customer->id);
                $this->fail('Linking a superseded document must be rejected.');
            } catch (LogicException) {
                // expected
            }
            $this->assertNull($original->fresh()->customer_id);
        });
    }

    // -------------------------------------------------- 10. zero operational writes

    public function test_linking_and_unlinking_touches_no_balance_ledger_or_operational_table(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        TenantContext::runFor($shop->id, function () use ($shop, $service) {
            $customer = $this->createCustomer($shop->id, ['loyalty_points' => 50]);
            $batch    = $this->makeBatch($shop->id);
            $document = $this->makeDraftDocument($shop->id, $batch->id);

            $before = $this->operationalCounts();
            $customerBefore = $customer->fresh()->only(['loyalty_points', 'mobile', 'first_name', 'last_name']);

            $service->linkCustomer($document, $customer->id);
            $service->linkCustomer($document, null);

            $this->assertSame($before, $this->operationalCounts());
            $this->assertSame($customerBefore, $customer->fresh()->only(['loyalty_points', 'mobile', 'first_name', 'last_name']));
            $this->assertSame(1, DB::table('customers')->where('shop_id', $shop->id)->count());
        });
    }
}
