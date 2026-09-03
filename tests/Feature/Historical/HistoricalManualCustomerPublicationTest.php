<?php

namespace Tests\Feature\Historical;

use App\Models\Customer;
use App\Models\CustomerOpeningBalance;
use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\OnboardingBatch;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3 §B — publication-time customer creation/reuse, exercised through
 * the manual entry HTTP path. R1 (a fuzzy suggestion alone never sets
 * customer_id — see HistoricalCustomerMatchingTest.php, which already covers
 * that end to end) is untouched by anything here: every test below turns the
 * new behaviour on with an explicit `add_customer_on_publish` submitted on
 * THIS request, never a suggestion the operator merely saw on screen.
 */
class HistoricalManualCustomerPublicationTest extends TestCase
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

    private function billPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'CUSTPUB-' . fake()->unique()->numberBetween(1, 999999),
            'document_date'            => now()->toDateString(),
            'source_system'            => 'Manual QA',
            'grand_total'              => 1000,
            'tax_total'                => 0,
            'tax_mode'                 => HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE,
            'zero_tax_confirmed'       => '1',
            'lines'                    => [[
                'line_item_name' => 'QA Gold Item',
                'line_quantity'  => 1,
                'line_total'     => 1000,
            ]],
        ], $override);
    }

    /** A legacy-spelled row the matcher's fallback scan finds, not the exact-match query. */
    private function legacyMobileRow(int $shopId, string $firstName, string $asTyped): Customer
    {
        $customer = TenantContext::runFor($shopId, fn () => Customer::create([
            'first_name' => $firstName,
            'mobile'     => '9000000000',
        ]));

        DB::table('customers')->where('id', $customer->id)->update(['mobile' => $asTyped]);

        return $customer->fresh();
    }

    /** Mirrors HistoricalOpeningBalanceOverlapTest's helper: a receivable row, FK-backed by a throwaway locked batch. */
    private function makeOpeningBalance(int $shopId, int $customerId, string $asOfDate, float $amount): CustomerOpeningBalance
    {
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

    private function makeBulkBatch(int $shopId): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch();
        $batch->forceFill([
            'shop_id'       => $shopId,
            'label'         => 'Bulk import QA',
            'source_system' => 'Import',
            'status'        => HistoricalImportBatch::STATUS_DRAFT,
        ])->save();

        return $batch;
    }

    private function makeBulkDocument(int $shopId, int $batchId, array $attrs = []): HistoricalSalesDocument
    {
        $number     = $attrs['original_document_number'] ?? ('DOC-' . Str::random(8));
        $date       = $attrs['document_date'] ?? '2024-03-15';
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
            'source_system'                         => 'Import',
            'customer_snapshot'                     => ['name' => 'Ramesh Patel', 'mobile' => '9876543210'],
            'tax_mode'                               => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness'                       => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total'                            => 1000.00,
            'status'                                 => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint'                    => hash('sha256', (string) Str::uuid()),
        ], $attrs))->save();

        return $document;
    }

    // ------------------------------------------------------- 6 & 7. preview/draft never create

    public function test_preview_never_creates_a_customer_even_with_the_option_on(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500006',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.preview'), $payload)->assertOk();

        TenantContext::runFor($shop->id, function () {
            $this->assertSame(0, Customer::withoutTenant()->count());
        });
    }

    public function test_draft_save_never_creates_a_customer_even_with_the_option_on(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'draft',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500007',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertNull($document->customer_id);
            $this->assertSame(0, Customer::withoutTenant()->count());
        });
    }

    // ------------------------------------------------- 8, 9, 10. snapshot-only cases

    public function test_publish_with_the_option_off_stays_snapshot_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500008',
            'add_customer_on_publish'  => '0',
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
            $this->assertNull($document->customer_id);
            $this->assertSame(0, Customer::withoutTenant()->count());
        });
    }

    public function test_cash_or_walk_in_name_stays_snapshot_only_even_with_a_real_mobile(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Cash',
            'customer_mobile'          => '9876500009',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertNull($document->customer_id);
            $this->assertSame(0, Customer::withoutTenant()->count());
        });
    }

    public function test_missing_mobile_stays_snapshot_only_even_with_a_real_name(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertNull($document->customer_id);
            $this->assertSame(0, Customer::withoutTenant()->count());
        });
    }

    // ------------------------------------------------- 11 & 12. exact reuse, no overwrite

    public function test_exact_canonical_mobile_match_reuses_the_one_existing_customer(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $existing = TenantContext::runFor(
            $shop->id,
            fn () => $this->createCustomer($shop->id, ['first_name' => 'Existing', 'last_name' => 'Record', 'mobile' => '9876500011'])
        );

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500011',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($existing) {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame($existing->id, $document->customer_id);
            $this->assertSame(1, Customer::withoutTenant()->count(), 'reuse must not create a second row');
        });
    }

    public function test_reusing_an_existing_customer_does_not_overwrite_their_profile(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $existing = TenantContext::runFor(
            $shop->id,
            fn () => $this->createCustomer($shop->id, [
                'first_name' => 'Existing', 'last_name' => 'Record', 'mobile' => '9876500012', 'address' => 'Original address',
            ])
        );

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'A Totally Different Name',
            'customer_address'         => 'A totally different address off the old bill',
            'customer_mobile'          => '9876500012',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($existing) {
            $fresh = $existing->fresh();
            $this->assertSame('Existing', $fresh->first_name);
            $this->assertSame('Record', $fresh->last_name);
            $this->assertSame('Original address', $fresh->address);
        });
    }

    // ------------------------------------------------------------- 13. create + link

    public function test_no_match_plus_valid_identity_creates_and_links_exactly_one_customer(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500013',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $this->assertSame(1, Customer::withoutTenant()->count());
            $customer = Customer::withoutTenant()->firstOrFail();
            $this->assertSame('9876500013', $customer->mobile);
            $this->assertSame('Ramesh', $customer->first_name);
            $this->assertSame('Kumar', $customer->last_name);

            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame($customer->id, $document->customer_id);
        });
    }

    // -------------------------------------------------------- 14. rollback on failure

    public function test_a_high_opening_balance_overlap_rolls_back_the_whole_publish_including_the_customer_link(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $existing = TenantContext::runFor(
            $shop->id,
            fn () => $this->createCustomer($shop->id, ['mobile' => '9876500014'])
        );
        $this->makeOpeningBalance($shop->id, $existing->id, now()->toDateString(), 500.00);

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            // billPayload() already dates this today; the opening-balance cutoff
            // above is also today, so document_date is on/before the cutoff.
            'customer_mobile'          => '9876500014',
            'add_customer_on_publish'  => '1',
            // Explicit and unpaid — HIGH requires outstanding > 0, and the
            // evaluator only ever reads the snapshot, never derives it.
            'outstanding_amount'       => 1000,
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertSessionHas('error');

        TenantContext::runFor($shop->id, function () use ($existing) {
            $this->assertSame(0, HistoricalSalesDocument::query()->count(), 'the whole transaction must roll back, not just the link');
            $this->assertSame(1, Customer::withoutTenant()->count(), 'only the pre-existing customer may remain — no orphan created');
            $this->assertSame($existing->id, Customer::withoutTenant()->firstOrFail()->id, 'the surviving row must be the pre-existing one, untouched');
        });
    }

    // ------------------------------------------------------------- 15. ambiguous match

    public function test_an_ambiguous_mobile_match_blocks_publication_with_an_actionable_error(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->legacyMobileRow($shop->id, 'Legacy One', '+91 98765-00015');
        $this->legacyMobileRow($shop->id, 'Legacy Two', '098765-00015 ');

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500015',
            'add_customer_on_publish'  => '1',
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            'More than one existing customer',
            (string) session('error')
        );

        TenantContext::runFor($shop->id, function () {
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
            $this->assertSame(2, Customer::withoutTenant()->count(), 'both legacy rows must survive untouched');
        });
    }

    // --------------------------------------------------------------- 16. no side effects

    public function test_customer_creation_on_publish_triggers_no_notification_or_operational_write(): void
    {
        Notification::fake();
        Mail::fake();

        [$owner, $shop] = $this->createRetailerTenant();
        $before = collect(self::OPERATIONAL_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500016',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        $after = collect(self::OPERATIONAL_TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $this->assertSame($before, $after, 'creating a customer at publish touched a live operational table.');

        Notification::assertNothingSent();
        Mail::assertNothingSent();
    }

    // ---------------------------------------------------------- 18. bulk import excluded

    public function test_bulk_import_publish_never_creates_or_links_a_customer(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $existing = TenantContext::runFor(
            $shop->id,
            fn () => $this->createCustomer($shop->id, ['mobile' => '9876500018'])
        );

        $service = app(HistoricalDocumentLifecycleService::class);

        [$document, $batch] = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBulkBatch($shop->id);

            $document = $this->makeBulkDocument($shop->id, $batch->id, [
                // Matches $existing's mobile exactly — if bulk publish ever
                // grew the same auto-link behaviour, this is what would catch it.
                'customer_snapshot' => ['name' => 'Ramesh Kumar', 'mobile' => '9876500018'],
            ]);

            return [$document, $batch];
        });

        TenantContext::runFor($shop->id, function () use ($service, $batch, $owner) {
            $service->claimForPublishing($batch);
            $service->publishClaimed($batch, $owner->id);
        });

        TenantContext::runFor($shop->id, function () use ($document, $existing) {
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->fresh()->status);
            $this->assertNull($document->fresh()->customer_id);
            $this->assertSame(1, Customer::withoutTenant()->count(), 'bulk publish must never create a customer');
            $this->assertSame($existing->id, Customer::withoutTenant()->firstOrFail()->id);
        });
    }

    // -------------------------------------------------------- 19. immutable once published

    public function test_a_publish_time_customer_link_is_frozen_once_published(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500019',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
            $linkedId = $document->customer_id;
            $this->assertNotNull($linkedId, 'the publish must have created and linked a customer');

            $other = $this->createCustomer($shop->id);

            // Same guard R1 already relies on (HistoricalDocumentLifecycleService::linkCustomer):
            // once a document is not draft, its customer link is frozen. Proving it here pins
            // that the publish-time link created by applyCustomerOnPublish() is subject to the
            // exact same immutability rule as an explicitly operator-linked one.
            $this->expectException(LogicException::class);
            app(HistoricalDocumentLifecycleService::class)->linkCustomer($document, $other->id);
        });
    }

    // ------------------------------------------------------------------------- 20. PAN

    public function test_a_valid_pan_is_copied_to_a_newly_created_customer(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500020',
            'customer_pan'             => 'ABCDE1234F',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $customer = Customer::withoutTenant()->firstOrFail();
            $this->assertSame('ABCDE1234F', $customer->pan);
        });
    }

    public function test_a_malformed_pan_is_silently_not_copied(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500021',
            'customer_pan'             => 'NOT-A-REAL-PAN',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $customer = Customer::withoutTenant()->firstOrFail();
            $this->assertNull($customer->pan);
        });
    }

    public function test_pan_is_never_copied_onto_a_reused_existing_customer(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $existing = TenantContext::runFor(
            $shop->id,
            fn () => $this->createCustomer($shop->id, ['mobile' => '9876500022', 'pan' => null])
        );

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500022',
            'customer_pan'             => 'ABCDE1234F',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($existing) {
            $this->assertNull($existing->fresh()->pan, 'a reused customer profile must never be overwritten from the snapshot');
        });
    }

    // --------------------------------------------------- 5 (gap). archived rejected at link

    public function test_linking_an_archived_customer_id_directly_is_rejected(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        TenantContext::runFor($shop->id, function () use ($shop, $service) {
            $customer = $this->createCustomer($shop->id);
            $customer->forceFill(['is_active' => false])->save();

            $batch    = $this->makeBulkBatch($shop->id);
            $document = $this->makeBulkDocument($shop->id, $batch->id);

            $this->expectException(LogicException::class);
            $service->linkCustomer($document, $customer->id);
        });
    }

    // ------------------------------------------------- Audit D1. blank name guard

    /**
     * Audit finding D1 (HISTORICAL-CUSTOMER-PUBLICATION-AUDIT.md §3): a bill
     * with a real mobile but NO name at all passed the generic-name gate
     * (isGenericCustomerName('') === false) and created a live customer
     * literally named "Walk-in" — exactly the record the denylist exists to
     * stop, reached through a different door. A blank/whitespace-only name is
     * not a valid identity and must stay snapshot-only, same as Cash/Walk-in.
     */
    public function test_blank_customer_name_with_a_valid_mobile_stays_snapshot_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => '   ',
            'customer_mobile'          => '9876500023',
            'add_customer_on_publish'  => '1',
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertRedirect();

        TenantContext::runFor($shop->id, function () {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
            $this->assertNull($document->customer_id);
            $this->assertSame(0, Customer::withoutTenant()->count(), 'a blank name must never seed a placeholder "Walk-in" customer');
        });
    }

    // --------------------------------------------- Audit D2. archived-mobile collision

    /**
     * Audit finding D2: byMobile() is ->active()-only, so an archived
     * customer holding the canonical mobile is invisible to the lookup and
     * the create branch runs straight into the (shop_id, mobile) unique
     * index — a raw QueryException the controller reports as an unactionable
     * "try again". The correct behaviour is a clean, actionable refusal
     * BEFORE any insert is attempted, same shape as linkCustomer()'s own
     * archived-customer message.
     */
    public function test_an_archived_customer_holding_the_same_mobile_blocks_publication_with_an_actionable_message(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $archived = TenantContext::runFor($shop->id, function () use ($shop) {
            $customer = $this->createCustomer($shop->id, ['mobile' => '9876500024']);
            $customer->forceFill(['is_active' => false])->save();

            return $customer;
        });

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500024',
            'add_customer_on_publish'  => '1',
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertSessionHas('error');
        $this->assertStringContainsString('archived', strtolower((string) session('error')));

        TenantContext::runFor($shop->id, function () use ($archived) {
            $this->assertSame(0, HistoricalSalesDocument::query()->count(), 'the whole publish must roll back, not just skip the create');
            $this->assertSame(1, Customer::withoutTenant()->count(), 'no duplicate/second row may be created');
            $this->assertFalse($archived->fresh()->is_active, 'the archived customer must never be silently reactivated');
        });
    }

    /**
     * Same collision, but the archived customer belongs to ANOTHER shop —
     * confirms the pre-create archived-lookup stays shop-scoped and a
     * same-mobile-different-shop archived row is invisible (no cross-shop
     * leak, no false block, this shop's create branch proceeds normally).
     */
    public function test_an_archived_customer_in_another_shop_never_blocks_or_is_exposed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop]  = $this->createRetailerTenant();
        TenantContext::runFor($otherShop->id, function () use ($otherShop) {
            $customer = $this->createCustomer($otherShop->id, ['mobile' => '9876500025']);
            $customer->forceFill(['is_active' => false])->save();
        });

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Ramesh Kumar',
            'customer_mobile'          => '9876500025',
            'add_customer_on_publish'  => '1',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $payload)->assertRedirect();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $document = HistoricalSalesDocument::query()->latest('id')->firstOrFail();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $document->status);
            $this->assertNotNull($document->customer_id, 'this shop must get its own new customer, unaffected by another shop\'s archived row');
            $this->assertSame(1, Customer::withoutTenant()->where('shop_id', $shop->id)->count());
        });
    }

    // --------------------------------------------- Audit D3. new-customer rollback proof

    /**
     * Audit finding D3: the shipped rollback test (#14 above) only proves
     * that LINKING an existing customer unwinds with the transaction — the
     * Customer::create() row itself was never exercised under failure.
     * Contract point 11 names newly created customers explicitly.
     *
     * This swaps in a service double that delegates linkCustomer() to the
     * real implementation (so the real create-then-link sequence runs
     * unmodified) and then throws from publish() — a later, independent step
     * in the SAME publishManual() transaction. If the transaction boundary is
     * correct, the freshly created Customer row rolls back along with
     * everything else.
     */
    public function test_a_publish_failure_after_customer_creation_rolls_back_the_new_customer_too(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $real = app(HistoricalDocumentLifecycleService::class);
        $spy  = new class ($real) extends HistoricalDocumentLifecycleService {
            public function __construct(private readonly HistoricalDocumentLifecycleService $real)
            {
            }

            public function linkCustomer(HistoricalSalesDocument $document, ?int $customerId): HistoricalSalesDocument
            {
                // Delegate to the real implementation so Customer::create()
                // (already run by the caller) really does get linked before
                // the injected failure below — this must not fake success.
                return $this->real->linkCustomer($document, $customerId);
            }

            public function publish(HistoricalImportBatch $batch, ?int $actorId = null): HistoricalImportBatch
            {
                throw new \RuntimeException('injected post-creation publish failure');
            }
        };
        $this->app->instance(HistoricalDocumentLifecycleService::class, $spy);

        $payload = $this->billPayload([
            'intent'                   => 'publish',
            'customer_name'            => 'Fresh Rollback Customer',
            'customer_mobile'          => '9876500026',
            'add_customer_on_publish'  => '1',
        ]);

        $response = $this->actingAs($owner)->post(route('historical.manual.store'), $payload);
        $response->assertSessionHas('error');

        TenantContext::runFor($shop->id, function () {
            $this->assertSame(0, Customer::withoutTenant()->count(), 'the newly created customer row must roll back');
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
            $this->assertSame(0, HistoricalImportBatch::query()->count());
        });
    }
}
