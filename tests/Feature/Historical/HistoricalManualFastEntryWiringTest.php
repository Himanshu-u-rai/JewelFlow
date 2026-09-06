<?php

namespace Tests\Feature\Historical;

use App\Http\Requests\Historical\StoreManualHistoricalRequest;
use App\Models\Historical\HistoricalSalesDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3 fast-entry — HISTORICAL-BATCH-3-UX-CONTRACT-V2 final tranche.
 *
 * "Save draft & new" / "Publish & new" let a high-volume operator enter one
 * bill after another without re-typing the header fields that stay constant
 * across a run (date, series, source, tax posture). Everything bill-specific
 * must start blank on the next form — this file is the business-logic proof
 * of that split; HistoricalManualFastEntryUiTest.php proves the rendered
 * markup for the same contract.
 *
 * A failed save/publish must NEVER open a fresh form — the operator's typed
 * bill would be silently discarded. store()/storeAndPublish()'s existing
 * backToForm() branches already run before wantsFreshFormAfterSuccess() is
 * ever reached, so those tests are really regression proof of code placement,
 * not new controller logic.
 */
class HistoricalManualFastEntryWiringTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function fastEntryPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'FE-'.fake()->unique()->numberBetween(1, 999999),
            'document_series' => 'A',
            // Today's date, like HistoricalManualFlowSeparationTest::cleanPayload() —
            // a backdated bill raises a cutover warning that blocks a direct
            // publish pending acknowledgement, which is a different contract
            // (HistoricalOpeningBalanceOverlapTest et al.) than the one this
            // file is proving. Tests that need a fixed date override it below.
            'document_date' => now()->toDateString(),
            'source_system' => 'Manual QA',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed' => '1',
            'customer_name' => 'Fast Entry Customer',
            'taxable_amount' => 1000,
            'tax_total' => 0,
            'grand_total' => 1000,
            'paid_amount' => 1000,
            'outstanding_amount' => 0,
            'lines' => [[
                'line_item_name' => 'QA Gold Item',
                'line_quantity' => 1,
                'line_total' => 1000,
            ]],
        ], $override);
    }

    public function test_save_draft_and_new_creates_the_document_and_reopens_a_fresh_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
        ]));

        $response->assertRedirect(route('historical.manual.create'));
        $response->assertSessionHas('success');

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame($shop->id, $document->shop_id);
            $this->assertSame(HistoricalSalesDocument::STATUS_DRAFT, $document->status);
        });
    }

    public function test_publish_and_new_creates_and_publishes_the_document_and_reopens_a_fresh_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_PUBLISH_AND_NEW,
        ]));

        $response->assertRedirect(route('historical.manual.create'));
        $response->assertSessionHas('success');

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame($shop->id, $document->shop_id);
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
        });
    }

    public function test_the_carry_forward_flash_contains_only_the_six_allowed_fields(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
            'document_series' => 'B-2024',
            'document_date' => '2024-05-01',
            'source_system' => 'Manual QA',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
        ]));

        // bill_gst_rate/tax_split_type joined the carry-forward set in Batch 5
        // (requirement #1) — a run of historical bills from the same source
        // batch typically shares one ordinary tax rate, same as date/series/
        // source/tax_mode already did.
        $response->assertSessionHas('historical_carry_forward', [
            'document_date' => '2024-05-01',
            'document_series' => 'B-2024',
            'source_system' => 'Manual QA',
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'bill_gst_rate' => null,
            'tax_split_type' => null,
        ]);
    }

    public function test_the_fresh_form_after_and_new_renders_the_carried_fields_prefilled_and_everything_else_blank(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
            'original_document_number' => 'FE-CARRY-0001',
            'document_series' => 'B-2024',
            'source_system' => 'Legacy Register',
        ]))->assertRedirect(route('historical.manual.create'));

        $create = $this->actingAs($owner)->get(route('historical.manual.create'));
        $create->assertOk();

        $html = $create->getContent();
        $this->assertStringContainsString('B-2024', $html);
        $this->assertStringContainsString('Legacy Register', $html);
        $this->assertStringContainsString('Carried from previous bill', $html);
        // The original invoice number is bill-specific and must NOT survive.
        $this->assertStringNotContainsString('FE-CARRY-0001', $html);
    }

    public function test_ordinary_draft_intent_is_unaffected_and_redirects_to_the_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT,
        ]));

        $response->assertSessionMissing('historical_carry_forward');

        TenantContext::runFor($shop->id, function () use ($response): void {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $response->assertRedirect(route('historical.documents.show', $document));
        });
    }

    public function test_ordinary_publish_intent_is_unaffected_and_redirects_to_the_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_PUBLISH,
        ]));

        $response->assertSessionMissing('historical_carry_forward');

        TenantContext::runFor($shop->id, function () use ($response): void {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $response->assertRedirect(route('historical.documents.show', $document));
        });
    }

    public function test_duplicate_detection_still_blocks_a_publish_and_new_and_never_opens_a_fresh_form(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $first = $this->fastEntryPayload(['intent' => StoreManualHistoricalRequest::INTENT_PUBLISH_AND_NEW]);
        $this->actingAs($owner)->post(route('historical.manual.store'), $first)->assertRedirect(route('historical.manual.create'));

        TenantContext::runFor($shop->id, fn () => $this->assertSame(1, HistoricalSalesDocument::query()->count()));

        // Same number, same financial year — the duplicate-number guard must
        // refuse it, and MUST NOT open a fresh blank form on the way out.
        $second = $this->actingAs($owner)->post(route('historical.manual.store'), $first);
        $second->assertSessionHas('error');
        $second->assertSessionMissing('historical_carry_forward');
        $second->assertSessionHasInput('original_document_number', $first['original_document_number']);

        TenantContext::runFor($shop->id, fn () => $this->assertSame(
            1,
            HistoricalSalesDocument::query()->count(),
            'A duplicate manual bill was published a second time.'
        ));
    }

    public function test_a_validation_failure_with_draft_and_new_preserves_the_typed_bill_and_creates_no_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
            'document_date' => null,
            'original_document_number' => 'FE-INVALID-0001',
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);

        $response->assertSessionHasErrors('document_date');
        $response->assertSessionMissing('historical_carry_forward');
        $response->assertSessionHasInput('original_document_number', 'FE-INVALID-0001');

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
        });
    }

    public function test_a_validation_failure_with_publish_and_new_preserves_the_typed_bill_and_creates_no_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_PUBLISH_AND_NEW,
            'document_date' => null,
            'original_document_number' => 'FE-INVALID-0002',
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);

        $response->assertSessionHasErrors('document_date');
        $response->assertSessionMissing('historical_carry_forward');
        $response->assertSessionHasInput('original_document_number', 'FE-INVALID-0002');

        TenantContext::runFor($shop->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
        });
    }

    public function test_a_fast_entry_document_from_one_shop_is_never_visible_to_another(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $this->actingAs($ownerA)->post(route('historical.manual.store'), $this->fastEntryPayload([
            'intent' => StoreManualHistoricalRequest::INTENT_DRAFT_AND_NEW,
        ]))->assertRedirect(route('historical.manual.create'));

        TenantContext::runFor($shopA->id, function (): void {
            $this->assertSame(1, HistoricalSalesDocument::query()->count());
        });

        TenantContext::runFor($shopB->id, function (): void {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
        });
    }
}
