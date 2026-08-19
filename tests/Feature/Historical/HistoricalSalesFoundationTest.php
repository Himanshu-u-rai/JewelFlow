<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalImportProfile;
use App\Models\Historical\HistoricalImportRow;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Services\Historical\HistoricalDocumentLifecycleService;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\Historical\HistoricalLifecycle;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Historical Sales — Batch 1 safety boundary and canonical model.
 *
 * The whole point of the dedicated-table architecture is that historical records
 * cannot leak into the operational system. These tests exist to prove that
 * claim mechanically rather than by inspection, and to prove the database
 * constraints have teeth when Eloquent is bypassed entirely.
 */
class HistoricalSalesFoundationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    /**
     * Every operational table that a real sale touches. If importing history
     * changes ANY of these counts, the architecture has failed.
     */
    private const OPERATIONAL_TABLES = [
        'invoices',
        'invoice_items',
        'shop_counters',
        'invoice_number_events',
        'cash_transactions',
        'invoice_payments',
        'metal_movements',
        'customer_gold_transactions',
        'loyalty_transactions',
        'shop_notifications',
        'stock_purchases',
        'metal_lots',
        'items',
    ];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ------------------------------------------------------------------ helpers

    private function makeProfile(int $shopId, array $attrs = []): HistoricalImportProfile
    {
        $profile = new HistoricalImportProfile();
        $profile->forceFill(array_merge([
            'shop_id'       => $shopId,
            'name'          => 'Tally ' . Str::random(6),
            'source_system' => 'Tally',
            'layout_type'   => HistoricalImportProfile::LAYOUT_SINGLE_ROW_PER_LINE,
            'mapping'       => ['invoice_number' => 'A', 'date' => 'B'],
            'date_format'   => 'd/m/Y',
            'is_active'     => true,
        ], $attrs))->save();

        return $profile;
    }

    private function makeBatch(int $shopId, array $attrs = []): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch();
        $batch->forceFill(array_merge([
            'shop_id'       => $shopId,
            'label'         => 'FY 2023-24',
            'source_system' => 'Tally',
            'status'        => HistoricalImportBatch::STATUS_DRAFT,
        ], $attrs))->save();

        return $batch;
    }

    /**
     * Build a draft historical document. The fingerprint is computed the same way
     * the importer will compute it, so duplicate tests exercise the real rule.
     */
    private function makeDocument(int $shopId, int $batchId, array $attrs = []): HistoricalSalesDocument
    {
        $number = array_key_exists('original_document_number', $attrs)
            ? $attrs['original_document_number']
            : 'INV/2023-24/0045';
        $date         = $attrs['document_date'] ?? '2023-11-04';
        $series       = $attrs['document_series'] ?? null;
        $total        = (float) ($attrs['grand_total'] ?? 25000.00);
        $fy           = $attrs['financial_year']
            ?? HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable($date));
        $normalized   = HistoricalDocumentIdentity::normalizeNumber($number);
        $customerName = $attrs['__customer_name'] ?? 'Ramesh Patel';

        $fingerprint = $attrs['content_fingerprint'] ?? HistoricalDocumentIdentity::fingerprint(
            shopId: $shopId,
            documentType: HistoricalSalesDocument::TYPE_SALE_INVOICE,
            financialYear: $fy,
            documentSeries: $series,
            normalizedNumber: $normalized,
            documentDate: $date,
            grandTotal: $total,
            customerName: $customerName,
            customerMobile: $attrs['__customer_mobile'] ?? '9876543210',
            duplicateOverrideKey: $attrs['duplicate_override_key'] ?? null,
        );

        unset($attrs['__customer_name'], $attrs['__customer_mobile']);

        $document = new HistoricalSalesDocument();
        $document->forceFill(array_merge([
            'shop_id'                            => $shopId,
            'historical_import_batch_id'         => $batchId,
            'historical_reference'               => (string) Str::uuid(),
            'original_document_number'           => $number,
            'original_document_number_normalized' => $normalized,
            'document_series'                    => $series,
            'document_type'                      => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date'                      => $date,
            'financial_year'                     => $fy,
            'source_system'                      => 'Tally',
            'customer_snapshot'                  => ['name' => $customerName, 'mobile' => '9876543210'],
            'tax_mode'                           => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness'                   => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total'                        => $total,
            'status'                             => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint'                => $fingerprint,
        ], $attrs))->save();

        return $document;
    }

    private function makeLine(HistoricalSalesDocument $document, array $attrs = []): HistoricalSalesLine
    {
        $line = new HistoricalSalesLine();
        $line->forceFill(array_merge([
            'shop_id'                      => $document->shop_id,
            'historical_sales_document_id' => $document->getKey(),
            'line_number'                  => 1,
            'item_snapshot'                => ['description' => 'Gold Ring 22K'],
            'source_description'           => 'Gold Ring 22K',
            'quantity'                     => 1,
            'line_total'                   => 25000.00,
        ], $attrs))->save();

        return $line;
    }

    /** @return array<string,int> */
    private function operationalCounts(): array
    {
        $counts = [];
        foreach (self::OPERATIONAL_TABLES as $table) {
            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    // ---------------------------------------------------------------- 1. tenancy

    public function test_1_shop_a_cannot_see_shop_b_historical_data(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        TenantContext::runFor($shopB->id, function () use ($shopB) {
            $profile = $this->makeProfile($shopB->id);
            $batch   = $this->makeBatch($shopB->id, ['historical_import_profile_id' => $profile->id]);
            $doc     = $this->makeDocument($shopB->id, $batch->id);
            $this->makeLine($doc);
            (new HistoricalImportRow())->forceFill([
                'shop_id'                    => $shopB->id,
                'historical_import_batch_id' => $batch->id,
                'source_row_number'          => 1,
                'original_payload'           => ['a' => 'b'],
            ])->save();
        });

        TenantContext::runFor($shopA->id, function () {
            $this->assertSame(0, HistoricalImportProfile::query()->count(), 'profiles leaked');
            $this->assertSame(0, HistoricalImportBatch::query()->count(), 'batches leaked');
            $this->assertSame(0, HistoricalSalesDocument::query()->count(), 'documents leaked');
            $this->assertSame(0, HistoricalSalesLine::query()->count(), 'lines leaked');
            $this->assertSame(0, HistoricalImportRow::query()->count(), 'rows leaked');
        });

        // Fail closed: no tenant context at all must also yield nothing.
        $this->assertSame(0, HistoricalSalesDocument::query()->count());
    }

    public function test_2_cross_shop_parent_customer_item_and_revision_are_rejected(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $batchB = TenantContext::runFor($shopB->id, fn () => $this->makeBatch($shopB->id));
        $docB   = TenantContext::runFor($shopB->id, fn () => $this->makeDocument($shopB->id, $batchB->id));
        $customerB = $this->createCustomer($shopB->id);
        $itemB     = $this->createItem($shopB->id);

        $batchA = TenantContext::runFor($shopA->id, fn () => $this->makeBatch($shopA->id));

        TenantContext::runFor($shopA->id, function () use ($shopA, $batchA, $batchB, $docB, $customerB, $itemB) {
            // Parent batch in another shop.
            $this->assertQueryFails(fn () => $this->makeDocument($shopA->id, $batchB->id));

            // Customer in another shop.
            $this->assertQueryFails(
                fn () => $this->makeDocument($shopA->id, $batchA->id, ['customer_id' => $customerB->id])
            );

            // Revision link into another shop.
            $this->assertQueryFails(
                fn () => $this->makeDocument($shopA->id, $batchA->id, ['revises_document_id' => $docB->id])
            );

            // Item in another shop.
            $docA = $this->makeDocument($shopA->id, $batchA->id, ['original_document_number' => 'A-0007']);
            $this->assertQueryFails(fn () => $this->makeLine($docA, ['item_id' => $itemB->id]));
        });
    }

    // ------------------------------------------------- 3. no operational effects

    public function test_3_creating_historical_data_touches_no_operational_table(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $before = $this->operationalCounts();

        TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);
            $this->makeLine($doc);
            (new HistoricalImportRow())->forceFill([
                'shop_id'                    => $shop->id,
                'historical_import_batch_id' => $batch->id,
                'source_row_number'          => 1,
                'original_payload'           => ['x' => 1],
            ])->save();

            app(HistoricalDocumentLifecycleService::class)->publish($batch, $owner->id);
        });

        $this->assertSame($before, $this->operationalCounts(),
            'Historical import changed an operational table. The safety boundary is broken.');
    }

    // ------------------------------------------- 4-10. original number identity

    public function test_4_original_document_number_is_stored_byte_for_byte(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $numbers = [
            '0012',
            'INV/2023-24/0045',
            'INV-01',
            'INV/01',
            'GST-458/A',
            'RJ-JPR 2024 0018',
            'A.0007',
            'A-0007',
            'inv-lower/9',
            '०१२',           // Devanagari digits
            'बीजक/०१२',      // Devanagari word + digits
            '００１２',        // full-width digits
        ];

        TenantContext::runFor($shop->id, function () use ($shop, $numbers) {
            $batch = $this->makeBatch($shop->id);

            foreach ($numbers as $i => $number) {
                // A distinct series per row: this test is about STORAGE fidelity,
                // and `0012` / `００１２` deliberately share one normalized identity,
                // so they would otherwise collide on the duplicate index.
                $doc = $this->makeDocument($shop->id, $batch->id, [
                    'original_document_number' => $number,
                    'document_series'          => 'S' . $i,
                    'grand_total'              => 1000 + $i,
                ]);

                $stored = DB::table('historical_sales_documents')
                    ->where('id', $doc->id)->value('original_document_number');

                $this->assertSame($number, $stored, "Original number was rewritten: {$number}");
                $this->assertSame($number, $doc->fresh()->displayNumber());
            }
        });
    }

    public function test_5_normalization_never_alters_the_displayed_number(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, ['original_document_number' => 'INV/2023-24/0045']);

            // Punctuation SURVIVES normalization. The old rule produced
            // 'INV2023240045' and could not tell INV-01 from INV/01.
            $this->assertSame('INV/2023-24/0045', $doc->original_document_number_normalized);
            $this->assertSame('INV/2023-24/0045', $doc->displayNumber());
            $this->assertSame(HistoricalSalesDocument::NUMBER_LABEL, 'Original Invoice Number');
        });

        // Leading zeroes survive normalization: 0012 and 12 are different bills.
        $this->assertSame('0012', HistoricalDocumentIdentity::normalizeNumber('0012'));
        $this->assertNotSame(
            HistoricalDocumentIdentity::normalizeNumber('0012'),
            HistoricalDocumentIdentity::normalizeNumber('12')
        );
        // Case is the only thing that folds away in a re-typed variant.
        $this->assertSame(
            HistoricalDocumentIdentity::normalizeNumber('INV/2023-24/0045'),
            HistoricalDocumentIdentity::normalizeNumber('inv/2023-24/0045')
        );
    }

    /**
     * The defect this correction exists for.
     *
     * `preg_replace('/[^A-Z0-9]/', '', strtoupper($n))` turned INV-01 and INV/01
     * into one key, so importing a shop that runs both series meant the second
     * bill was refused as a duplicate of the first. Punctuation in a statutory
     * invoice number is not noise.
     */
    public function test_5a_punctuation_distinguishes_two_real_document_numbers(): void
    {
        $this->assertSame('INV-01', HistoricalDocumentIdentity::normalizeNumber('INV-01'));
        $this->assertSame('INV/01', HistoricalDocumentIdentity::normalizeNumber('INV/01'));
        $this->assertNotSame(
            HistoricalDocumentIdentity::normalizeNumber('INV-01'),
            HistoricalDocumentIdentity::normalizeNumber('INV/01')
        );
        $this->assertSame('A.0007', HistoricalDocumentIdentity::normalizeNumber('A.0007'));
        $this->assertNotSame(
            HistoricalDocumentIdentity::normalizeNumber('A.0007'),
            HistoricalDocumentIdentity::normalizeNumber('A-0007')
        );

        // And the database agrees: both survive under one shop/FY/type/series.
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            $a = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'INV-01',
                'grand_total'              => 1100.00,
            ]);
            $b = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'INV/01',
                'grand_total'              => 1200.00,
            ]);

            $this->assertNotSame($a->id, $b->id);
            $this->assertSame('INV-01', $a->fresh()->displayNumber());
            $this->assertSame('INV/01', $b->fresh()->displayNumber());
        });
    }

    /**
     * Non-ASCII scripts must produce a REAL identity, not null.
     *
     * The old rule returned null for `०१२` and `００１२`, which silently switched
     * number-based duplicate protection off for every shop that prints its bills
     * in Devanagari — exactly the shops this module exists for.
     */
    public function test_5b_non_ascii_numbers_produce_a_usable_identity(): void
    {
        $this->assertSame('०१२', HistoricalDocumentIdentity::normalizeNumber('०१२'));
        $this->assertSame('बीजक/०१२', HistoricalDocumentIdentity::normalizeNumber('बीजक/०१२'));
        $this->assertNotNull(HistoricalDocumentIdentity::normalizeNumber('०१२'));

        // Full-width forms are the one compatibility fold that matters for a
        // printed number: U+FF01-FF5E is ASCII at a fixed 0xFEE0 offset.
        $this->assertSame('0012', HistoricalDocumentIdentity::normalizeNumber('００１２'));
        $this->assertSame(
            HistoricalDocumentIdentity::normalizeNumber('0012'),
            HistoricalDocumentIdentity::normalizeNumber('００１２')
        );
        $this->assertSame('INV-01', HistoricalDocumentIdentity::normalizeNumber('ＩＮＶ－０１'));

        // Devanagari digits are NOT ASCII digits. They are a different printed
        // number and must never be folded onto 012.
        $this->assertNotSame(
            HistoricalDocumentIdentity::normalizeNumber('०१२'),
            HistoricalDocumentIdentity::normalizeNumber('012')
        );

        // Only genuinely empty input yields null.
        $this->assertNull(HistoricalDocumentIdentity::normalizeNumber(null));
        $this->assertNull(HistoricalDocumentIdentity::normalizeNumber(''));
        $this->assertNull(HistoricalDocumentIdentity::normalizeNumber("   \u{00A0}\u{200B}"));
    }

    /**
     * Invisible characters must not be able to mint a "new" invoice number, and
     * visually identical spacing/dashes must land on one key.
     */
    public function test_5c_invisible_and_equivalent_characters_normalize_predictably(): void
    {
        // Zero-width and other format characters are removed outright.
        foreach (["INV\u{200B}-01", "INV\u{200C}-01", "INV\u{FEFF}-01", "INV\u{00AD}-01"] as $sneaky) {
            $this->assertSame(
                'INV-01',
                HistoricalDocumentIdentity::normalizeNumber($sneaky),
                'A zero-width character created a second identity for INV-01.'
            );
        }

        // Every Unicode space collapses to one ordinary space; runs collapse; ends trim.
        foreach (["INV\u{00A0}01", "INV\u{3000}01", "INV \t 01", "  INV   01  "] as $spaced) {
            $this->assertSame('INV 01', HistoricalDocumentIdentity::normalizeNumber($spaced));
        }

        // Dash variants fold onto ASCII hyphen. A space is NOT a dash.
        foreach (["INV\u{2013}01", "INV\u{2014}01", "INV\u{2212}01", "INV\u{FF0D}01"] as $dashed) {
            $this->assertSame('INV-01', HistoricalDocumentIdentity::normalizeNumber($dashed));
        }
        $this->assertNotSame(
            HistoricalDocumentIdentity::normalizeNumber('INV-01'),
            HistoricalDocumentIdentity::normalizeNumber('INV 01')
        );

        // Case policy: mb_strtoupper, locale-INDEPENDENT. No Turkish dotted-i
        // special casing — U+0130 is already uppercase and is left alone rather
        // than being dropped the way the byte-wise strtoupper() dropped it.
        $this->assertSame('INV/2023-24/0045', HistoricalDocumentIdentity::normalizeNumber('inv/2023-24/0045'));
        $this->assertSame('İNV1', HistoricalDocumentIdentity::normalizeNumber('İNV1'));
        $this->assertSame('बीजक', HistoricalDocumentIdentity::normalizeNumber('बीजक'));
    }

    /**
     * The bypass proof, at the database, not just in the helper: a zero-width
     * space pasted into the second import must still hit the unique index.
     */
    public function test_5d_zero_width_characters_cannot_bypass_duplicate_detection(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $this->makeDocument($shop->id, $batch->id, ['original_document_number' => 'INV/2023-24/0045']);

            $batch2 = $this->makeBatch($shop->id, ['label' => 'second file']);
            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch2->id, [
                'original_document_number' => "INV/2023-\u{200B}24/00\u{FEFF}45",
                'grand_total'              => 88888.00,
            ]));
        });
    }

    /**
     * Series is a real distinction — and is itself case/padding insensitive, so
     * `A` and `a ` cannot be used to smuggle the same bill in twice.
     */
    public function test_5e_an_explicit_series_separates_the_same_number(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            $a = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => '0012',
                'document_series'          => 'A',
                'grand_total'              => 3100.00,
            ]);
            $b = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => '0012',
                'document_series'          => 'B',
                'grand_total'              => 3200.00,
            ]);
            $this->assertNotSame($a->id, $b->id);

            // But 'a ' is series A, not a third series.
            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => '0012',
                'document_series'          => 'a ',
                'grand_total'              => 3300.00,
            ]));
        });
    }

    public function test_6_duplicate_number_in_same_shop_year_type_series_is_rejected(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $this->makeDocument($shop->id, $batch->id, ['original_document_number' => 'INV/2023-24/0045']);

            // Same bill re-typed in a different case, with a different total and
            // a second batch: still the same statutory number.
            $batch2 = $this->makeBatch($shop->id, ['label' => 'second file']);
            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch2->id, [
                'original_document_number' => 'inv/2023-24/0045',
                'grand_total'              => 99999.00,
            ]));
        });
    }

    public function test_7_same_number_in_a_different_financial_year_is_allowed(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            $a = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => '0012',
                'document_date'            => '2023-11-04',
            ]);
            $b = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => '0012',
                'document_date'            => '2024-11-04',
            ]);

            $this->assertSame('2023-24', $a->financial_year);
            $this->assertSame('2024-25', $b->financial_year);
            $this->assertNotSame($a->id, $b->id);
        });
    }

    public function test_8_missing_number_is_stored_as_null_with_an_internal_reference_only(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, ['original_document_number' => null]);

            $this->assertNull($doc->original_document_number);
            $this->assertNull($doc->original_document_number_normalized);
            $this->assertFalse($doc->hasOriginalNumber());
            $this->assertNotEmpty($doc->historical_reference);

            // The internal reference is a database identifier, never the number.
            $this->assertSame(
                HistoricalSalesDocument::NUMBER_UNAVAILABLE_LABEL,
                $doc->displayNumber()
            );
            $this->assertStringNotContainsString($doc->historical_reference, $doc->displayNumber());
        });
    }

    public function test_9_duplicate_unnumbered_document_is_caught_by_content_fingerprint(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $this->makeDocument($shop->id, $batch->id, ['original_document_number' => null]);

            // Same cash bill, second file, no number to compare on.
            $batch2 = $this->makeBatch($shop->id, ['label' => 'second file']);
            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch2->id, [
                'original_document_number' => null,
            ]));
        });
    }

    public function test_10_changing_the_source_system_does_not_bypass_duplicate_detection(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $this->makeDocument($shop->id, $batch->id, ['original_document_number' => null, 'source_system' => 'Tally']);

            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => null,
                'source_system'            => 'Marg',
                'source_reference'         => 'marg-export-2.xlsx:88',
            ]));

            // Nor does provenance bypass the NUMBER identity index.
            $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'बीजक/०१२',
                'source_system'            => 'Tally',
                'grand_total'              => 4100.00,
            ]);
            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'बीजक/०१२',
                'source_system'            => 'Busy',
                'grand_total'              => 4200.00,
            ]));
        });

        // And directly: provenance is not part of the fingerprint input at all.
        $args = [
            'shopId'            => 1,
            'documentType'      => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'financialYear'     => '2023-24',
            'documentSeries'    => null,
            'normalizedNumber'  => null,
            'documentDate'      => '2023-11-04',
            'grandTotal'        => 25000.0,
            'customerName'      => 'Ramesh Patel',
            'customerMobile'    => '9876543210',
        ];
        $this->assertSame(
            HistoricalDocumentIdentity::fingerprint(...$args),
            HistoricalDocumentIdentity::fingerprint(...$args),
        );
        // The explicit operator override IS the only way to split them.
        $this->assertNotSame(
            HistoricalDocumentIdentity::fingerprint(...$args),
            HistoricalDocumentIdentity::fingerprint(...[...$args, 'duplicateOverrideKey' => 'operator-confirmed-1']),
        );
    }

    // --------------------------------------------------- 11-14. lifecycle rules

    public function test_11_draft_records_are_correctable_and_rollback_able(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);
            $this->makeLine($doc);
            (new HistoricalImportRow())->forceFill([
                'shop_id'                      => $shop->id,
                'historical_import_batch_id'   => $batch->id,
                'source_row_number'            => 1,
                'original_payload'             => ['x' => 1],
                'historical_sales_document_id' => $doc->id,
            ])->save();

            // Correctable while draft — including financial fields.
            $doc->forceFill(['grand_total' => 31000.00])->save();
            $this->assertSame('31000.00', $doc->fresh()->grand_total);

            app(HistoricalDocumentLifecycleService::class)->rollback($batch);

            $this->assertSame(0, HistoricalImportBatch::query()->count());
            $this->assertSame(0, HistoricalSalesDocument::query()->count());
            $this->assertSame(0, HistoricalSalesLine::query()->count());
            $this->assertSame(0, HistoricalImportRow::query()->count());
        });
    }

    public function test_12_published_records_cannot_be_hard_deleted(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);
            $line  = $this->makeLine($doc);

            app(HistoricalDocumentLifecycleService::class)->publish($batch, $owner->id);
            $doc->refresh();
            $batch->refresh();

            try {
                $doc->delete();
                $this->fail('Published document was deleted through Eloquent.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }

            try {
                $line->delete();
                $this->fail('Line of a published document was deleted through Eloquent.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }

            try {
                $batch->delete();
                $this->fail('Published batch was deleted through Eloquent.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }

            // Rollback must also refuse once published.
            try {
                app(HistoricalDocumentLifecycleService::class)->rollback($batch);
                $this->fail('Published batch was rolled back.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be rolled back', $e->getMessage());
            }

            $this->assertSame(1, HistoricalSalesDocument::query()->count());
        });
    }

    public function test_13_published_financial_and_snapshot_fields_cannot_be_edited(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);
            app(HistoricalDocumentLifecycleService::class)->publish($batch, $owner->id);
            $doc->refresh();

            foreach ([
                'grand_total'              => 99999.00,
                'original_document_number' => 'TAMPERED-1',
                'document_date'            => '2023-01-01',
                'customer_snapshot'        => ['name' => 'Someone Else'],
                'tax_completeness'         => HistoricalSalesDocument::TAX_COMPLETE,
            ] as $column => $value) {
                try {
                    $doc->forceFill([$column => $value])->save();
                    $this->fail("Published document allowed an edit to {$column}.");
                } catch (LogicException $e) {
                    $this->assertStringContainsString('immutable', $e->getMessage());
                }
                $doc->refresh();
            }

            // Even inside the lifecycle service's unlocked window, non-allow-listed
            // columns stay frozen: the gate decides WHO, the allow-list decides WHAT.
            HistoricalLifecycle::run(function () use ($doc) {
                try {
                    $doc->forceFill(['grand_total' => 1.00])->save();
                    $this->fail('Lifecycle unlock wrongly permitted a financial edit.');
                } catch (LogicException $e) {
                    $this->assertStringContainsString('immutable', $e->getMessage());
                }
            });
        });
    }

    public function test_14_void_and_supersede_respect_the_allow_list(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, ['original_document_number' => 'GST-458/A']);
            $service->publish($batch, $owner->id);
            $doc->refresh();

            // Direct status change outside the service is refused.
            try {
                $doc->forceFill(['status' => HistoricalSalesDocument::STATUS_VOID])->save();
                $this->fail('Published document was voided outside the lifecycle service.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('lifecycle service', $e->getMessage());
            }
            $doc->refresh();

            // A reason is mandatory.
            try {
                $service->void($doc, $owner->id, '   ');
                $this->fail('Void was accepted without a reason.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('reason', $e->getMessage());
            }

            $service->void($doc, $owner->id, 'Imported from the wrong source file.');
            $doc->refresh();
            $this->assertSame(HistoricalSalesDocument::STATUS_VOID, $doc->status);
            $this->assertNotNull($doc->voided_at);
            $this->assertSame($owner->id, $doc->voided_by);

            // Voiding frees the statutory number so the correct bill can be imported.
            $batch2 = $this->makeBatch($shop->id, ['label' => 'corrected']);
            $fixed  = $this->makeDocument($shop->id, $batch2->id, [
                'original_document_number' => 'GST-458/A',
                'grand_total'              => 44000.00,
            ]);
            $this->assertNotNull($fixed->id);

            // Supersede: the original stays as evidence and points forward.
            $batch3   = $this->makeBatch($shop->id, ['label' => 'revision']);
            $original = $this->makeDocument($shop->id, $batch3->id, ['original_document_number' => 'RJ-JPR 2024 0018']);
            $service->publish($batch3, $owner->id);
            $original->refresh();

            $batch4     = $this->makeBatch($shop->id, ['label' => 'revision-2']);
            $revision   = $this->makeDocument($shop->id, $batch4->id, [
                'original_document_number' => 'RJ-JPR 2024 0018',
                'grand_total'              => 51000.00,
                'document_date'            => '2024-06-02',
            ]);
            $service->supersede($original, $revision);

            $original->refresh();
            $revision->refresh();
            $this->assertSame(HistoricalSalesDocument::STATUS_SUPERSEDED, $original->status);
            $this->assertSame($revision->id, $original->superseded_by_document_id);
            $this->assertSame($original->id, $revision->revises_document_id);
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $revision->status);
        });
    }

    /**
     * A terminal document must never become somebody else's replacement.
     *
     * Both writes are individually legal transitions, so PostgreSQL cannot see the
     * problem: supersede(A, B) then supersede(B, A) closes a loop in
     * `superseded_by_document_id`, and walking forward to "the current version"
     * never terminates. The service is the only place that can refuse it.
     */
    public function test_14b_a_terminal_document_cannot_become_a_replacement(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $batchA = $this->makeBatch($shop->id);
            $a      = $this->makeDocument($shop->id, $batchA->id, ['original_document_number' => 'CYC-A']);
            $service->publish($batchA, $owner->id);
            $a->refresh();

            $batchB = $this->makeBatch($shop->id, ['label' => 'revision']);
            $b      = $this->makeDocument($shop->id, $batchB->id, [
                'original_document_number' => 'CYC-B',
                'grand_total'              => 26000.00,
            ]);

            $service->supersede($a, $b);
            $a->refresh();
            $b->refresh();

            $this->assertSame(HistoricalSalesDocument::STATUS_SUPERSEDED, $a->status);
            $this->assertSame($b->id, $a->superseded_by_document_id);

            // A is terminal now. Pointing B forward at it would close the loop.
            try {
                $service->supersede($b, $a);
                $this->fail('A superseded document was accepted as a replacement, creating a cycle.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot replace', $e->getMessage());
            }

            $a->refresh();
            $b->refresh();
            $this->assertNull($b->superseded_by_document_id, 'Forward pointer cycle was created.');
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $b->status);

            // The same rule holds for a voided replacement.
            $batchC = $this->makeBatch($shop->id, ['label' => 'voided']);
            $c      = $this->makeDocument($shop->id, $batchC->id, [
                'original_document_number' => 'CYC-C',
                'grand_total'              => 27000.00,
            ]);
            $service->publish($batchC, $owner->id);
            $c->refresh();
            $service->void($c, $owner->id, 'Imported in error.');
            $c->refresh();

            try {
                $service->supersede($b, $c);
                $this->fail('A voided document was accepted as a replacement.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot replace', $e->getMessage());
            }

            $b->refresh();
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $b->status);
            $this->assertNull($b->superseded_by_document_id);
        });
    }

    // ------------------------------------------------------ 15-17. data honesty

    public function test_15_unlinking_customer_and_item_preserves_the_snapshots(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $service = app(HistoricalDocumentLifecycleService::class);

        TenantContext::runFor($shop->id, function () use ($shop, $owner, $service) {
            $customer = $this->createCustomer($shop->id, ['first_name' => 'Current', 'last_name' => 'Name']);
            $item     = $this->createItem($shop->id);

            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, ['customer_id' => $customer->id]);
            $line  = $this->makeLine($doc, ['item_id' => $item->id]);

            $customerSnapshot = $doc->customer_snapshot;
            $itemSnapshot     = $line->item_snapshot;

            // Batch 3: customer linking is draft-only, so this exercise happens
            // before publish now — see the post-publish rejection below.
            $service->linkCustomer($doc, null);
            $service->linkItem($line, null);

            $doc->refresh();
            $line->refresh();

            $this->assertNull($doc->customer_id);
            $this->assertNull($line->item_id);
            $this->assertSame($customerSnapshot, $doc->customer_snapshot, 'customer snapshot lost on unlink');
            $this->assertSame($itemSnapshot, $line->item_snapshot, 'item snapshot lost on unlink');

            // Re-linking is equally non-destructive and moves no stock.
            $before = $this->operationalCounts();
            $service->linkCustomer($doc, $customer->id);
            $service->linkItem($line, $item->id);
            $this->assertSame($before, $this->operationalCounts());

            $service->publish($batch, $owner->id);
            $doc->refresh();
            $line->refresh();

            // Item linking is still advisory-editable post-publish (DB trigger
            // allows it); customer linking is not (Batch 3 draft-only guard).
            $service->linkItem($line, null);
            $line->refresh();
            $this->assertNull($line->item_id);
            $this->assertSame($itemSnapshot, $line->item_snapshot);

            $this->expectException(\LogicException::class);
            $service->linkCustomer($doc, null);
        });
    }

    public function test_16_unknown_tax_stays_unknown_and_is_never_silently_zeroed(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, [
                'tax_completeness' => HistoricalSalesDocument::TAX_UNKNOWN,
                'tax_mode'         => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            ]);

            $this->assertTrue($doc->taxIsUnknown());
            $this->assertNull($doc->tax_snapshot, 'unknown tax was materialised as data');
            $this->assertNull($doc->taxable_amount, 'unknown taxable amount was coerced to 0');

            // Unknown settlement is NULL, not zero — and the reconciliation check
            // does not fire when a half is unknown.
            $this->assertNull($doc->paid_amount_snapshot);
            $this->assertNull($doc->outstanding_amount_snapshot);

            // When BOTH halves are known, they must reconcile.
            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number'    => 'MISMATCH-1',
                'grand_total'                 => 1000.00,
                'paid_amount_snapshot'        => 400.00,
                'outstanding_amount_snapshot' => 100.00,
            ]));
        });
    }

    public function test_17_header_only_documents_are_supported(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, ['original_document_number' => 'HDR-1']);

            app(HistoricalDocumentLifecycleService::class)->publish($batch, $owner->id);
            $doc->refresh();

            $this->assertSame(0, $doc->lines()->count());
            $this->assertSame(HistoricalSalesDocument::STATUS_PUBLISHED, $doc->status);
            $this->assertSame('25000.00', $doc->grand_total);
        });
    }

    // --------------------------------------------- 18-20. operational isolation

    public function test_18_live_invoice_routes_cannot_bind_a_historical_document(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $doc = TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);

            return $this->makeDocument($shop->id, $batch->id);
        });

        // There is no invoice with this id: the tables are physically separate,
        // so {invoice} binding resolves to nothing.
        $this->assertSame(0, DB::table('invoices')->where('id', $doc->id)->count());

        $this->actingAs($owner)->get("/invoices/{$doc->id}")->assertNotFound();
        $this->actingAs($owner)->get("/invoices/{$doc->id}/edit")->assertNotFound();
    }

    public function test_19_no_live_invoice_observer_event_or_notification_fires(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $notificationsBefore = (int) DB::table('shop_notifications')->count();
        $auditBefore         = (int) DB::table('audit_logs')->count();

        TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);
            $this->makeLine($doc);
            app(HistoricalDocumentLifecycleService::class)->publish($batch, $owner->id);
        });

        $this->assertSame($notificationsBefore, (int) DB::table('shop_notifications')->count(),
            'A historical import produced an owner notification.');
        $this->assertSame($auditBefore, (int) DB::table('audit_logs')->count(),
            'A historical import wrote a sale audit entry.');

        // The historical models are structurally not Invoices.
        $this->assertFalse(is_a(HistoricalSalesDocument::class, \App\Models\Invoice::class, true));
        $this->assertFalse(is_a(HistoricalSalesLine::class, \App\Models\InvoiceItem::class, true));
        $this->assertSame(
            'historical_sales_documents',
            (new HistoricalSalesDocument())->getTable()
        );
    }

    public function test_20_database_constraints_have_teeth_when_eloquent_is_bypassed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        [$docId, $batchId, $lineId] = TenantContext::runFor($shop->id, function () use ($shop, $owner) {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);
            $line  = $this->makeLine($doc);
            app(HistoricalDocumentLifecycleService::class)->publish($batch, $owner->id);

            return [$doc->id, $batch->id, $line->id];
        });

        // Raw update of a published financial field — the ReturnService idiom.
        $this->assertQueryFails(fn () => DB::table('historical_sales_documents')
            ->where('id', $docId)->update(['grand_total' => 1.00]));

        // Raw delete of a published document, its line, and its batch.
        $this->assertQueryFails(fn () => DB::table('historical_sales_documents')->where('id', $docId)->delete());
        $this->assertQueryFails(fn () => DB::table('historical_sales_lines')->where('id', $lineId)->delete());
        $this->assertQueryFails(fn () => DB::table('historical_import_batches')->where('id', $batchId)->delete());

        // Illegal lifecycle transition.
        $this->assertQueryFails(fn () => DB::table('historical_sales_documents')
            ->where('id', $docId)->update(['status' => 'draft']));

        // Future-dated document (trigger, not CHECK — see the guards migration).
        $this->assertQueryFails(fn () => DB::table('historical_sales_documents')->insert([
            'shop_id'                    => $shop->id,
            'historical_import_batch_id' => $batchId,
            'historical_reference'       => (string) Str::uuid(),
            'document_date'              => now()->addYear()->toDateString(),
            'financial_year'             => '2027-28',
            'grand_total'                => 100,
            'content_fingerprint'        => str_repeat('f', 64),
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]));

        // Negative money on a completed sale.
        $this->assertQueryFails(fn () => DB::table('historical_sales_documents')->insert([
            'shop_id'                    => $shop->id,
            'historical_import_batch_id' => $batchId,
            'historical_reference'       => (string) Str::uuid(),
            'document_date'              => '2023-11-04',
            'financial_year'             => '2023-24',
            'grand_total'                => -1,
            'content_fingerprint'        => str_repeat('e', 64),
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]));

        // Cutover acknowledgement with no recorded reason.
        $this->assertQueryFails(fn () => DB::table('historical_sales_documents')->insert([
            'shop_id'                      => $shop->id,
            'historical_import_batch_id'   => $batchId,
            'historical_reference'         => (string) Str::uuid(),
            'document_date'                => '2023-11-04',
            'financial_year'               => '2023-24',
            'grand_total'                  => 100,
            'cutover_warning_acknowledged' => true,
            'content_fingerprint'          => str_repeat('d', 64),
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]));
    }

    /**
     * The number identity index must survive NULL semantics: with a NULL series,
     * PostgreSQL's default NULL-distinct behaviour would let the same invoice
     * number in twice. This is the regression that the COALESCE in the index
     * exists to prevent.
     */
    public function test_duplicate_detection_holds_when_series_is_null(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            $batch = $this->makeBatch($shop->id);
            $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'S-1',
                'document_series'          => null,
            ]);

            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'S-1',
                'document_series'          => null,
                'grand_total'              => 777.00,
            ]));

            // An explicitly mapped, genuinely different series is a different bill.
            $ok = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'S-1',
                'document_series'          => 'B',
                'grand_total'              => 777.00,
            ]);
            $this->assertNotNull($ok->id);
        });
    }

    /** No migration in this batch may add a column or index to the live sale tables. */
    public function test_live_sale_tables_are_untouched_by_this_batch(): void
    {
        $files = glob(database_path('migrations/2026_09_15_*.php')) ?: [];
        $this->assertNotEmpty($files, 'Batch 1 migrations not found.');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            foreach (['invoices', 'invoice_items', 'quick_bills'] as $table) {
                $this->assertStringNotContainsString("Schema::table('{$table}'", $source);
                $this->assertStringNotContainsString("ALTER TABLE {$table} ", $source);
            }
        }
    }

    // ------------------------------------------------------------------ asserter

    /**
     * Assert the DATABASE rejects an operation.
     *
     * The operation runs inside a nested transaction (a SAVEPOINT under
     * RefreshDatabase's outer transaction). PostgreSQL aborts the entire
     * transaction on any error — without the savepoint, the first expected
     * failure would poison every later statement in the same test and produce
     * fake failures downstream.
     */
    private function assertQueryFails(callable $operation): void
    {
        try {
            DB::transaction(static fn () => $operation());
        } catch (QueryException $e) {
            $this->assertTrue(true);

            return;
        }

        $this->fail('Expected the database to reject this operation, but it succeeded.');
    }
}
