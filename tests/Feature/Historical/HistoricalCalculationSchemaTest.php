<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalImportBatch;
use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesLine;
use App\Models\Historical\HistoricalSalesPayment;
use App\Models\ShopPaymentMethod;
use App\Support\Historical\HistoricalDocumentIdentity;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Batch 3 foundation — schema, constraint and immutability coverage for the
 * calculation-contract columns (historical_sales_lines/documents) and the new
 * historical_sales_payments table, per HISTORICAL-BATCH-3-UX-CONTRACT-V2 §11/§14.
 */
class HistoricalCalculationSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ---------------------------------------------------------------- helpers

    private function makeBatch(int $shopId): HistoricalImportBatch
    {
        $batch = new HistoricalImportBatch();
        $batch->forceFill([
            'shop_id'       => $shopId,
            'label'         => 'FY 2023-24',
            'source_system' => 'Tally',
            'status'        => HistoricalImportBatch::STATUS_DRAFT,
        ])->save();

        return $batch;
    }

    private function makeDocument(int $shopId, int $batchId, array $attrs = []): HistoricalSalesDocument
    {
        $number     = $attrs['original_document_number'] ?? 'INV/2023-24/0045';
        $date       = $attrs['document_date'] ?? '2023-11-04';
        $total      = (float) ($attrs['grand_total'] ?? 25000.00);
        $fy         = HistoricalDocumentIdentity::financialYearFor(new \DateTimeImmutable($date));
        $normalized = HistoricalDocumentIdentity::normalizeNumber($number);

        $fingerprint = $attrs['content_fingerprint'] ?? HistoricalDocumentIdentity::fingerprint(
            shopId: $shopId,
            documentType: HistoricalSalesDocument::TYPE_SALE_INVOICE,
            financialYear: $fy,
            documentSeries: null,
            normalizedNumber: $normalized,
            documentDate: $date,
            grandTotal: $total,
            customerName: 'Ramesh Patel',
            customerMobile: '9876543210',
            duplicateOverrideKey: null,
        );

        $document = new HistoricalSalesDocument();
        $document->forceFill(array_merge([
            'shop_id'                            => $shopId,
            'historical_import_batch_id'         => $batchId,
            'historical_reference'               => (string) Str::uuid(),
            'original_document_number'           => $number,
            'original_document_number_normalized' => $normalized,
            'document_type'                       => HistoricalSalesDocument::TYPE_SALE_INVOICE,
            'document_date'                       => $date,
            'financial_year'                      => $fy,
            'source_system'                       => 'Tally',
            'customer_snapshot'                   => ['name' => 'Ramesh Patel', 'mobile' => '9876543210'],
            'tax_mode'                            => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'tax_completeness'                    => HistoricalSalesDocument::TAX_UNKNOWN,
            'grand_total'                         => $total,
            'status'                              => HistoricalSalesDocument::STATUS_DRAFT,
            'content_fingerprint'                 => $fingerprint,
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

    private function makePaymentMethod(int $shopId, array $attrs = []): ShopPaymentMethod
    {
        $method = new ShopPaymentMethod();
        $method->forceFill(array_merge([
            'shop_id'    => $shopId,
            'type'       => ShopPaymentMethod::TYPE_BANK,
            'name'       => 'HDFC Current',
            'is_active'  => true,
            'sort_order' => 1,
        ], $attrs));
        $method->save();

        return $method;
    }

    private function makePayment(HistoricalSalesDocument $document, array $attrs = []): HistoricalSalesPayment
    {
        $payment = new HistoricalSalesPayment();
        $merged = array_merge([
            'shop_id'                      => $document->shop_id,
            'historical_sales_document_id' => $document->getKey(),
            'mode'                         => HistoricalSalesPayment::MODE_CASH,
            'amount'                       => 1000.00,
        ], $attrs);
        $payment->forceFill($merged);
        $payment->save();

        return $payment;
    }

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

    // ------------------------------------------------------------- new columns

    public function test_new_calculation_columns_exist_on_lines_and_documents(): void
    {
        $this->assertTrue(Schema::hasColumns('historical_sales_lines', [
            'line_metal_type', 'billable_weight_basis', 'billable_weight',
            'hallmark_charge', 'rhodium_charge', 'other_charge',
            'wastage_basis', 'wastage_value',
            'line_discount_type', 'line_discount_value', 'calculation_state',
        ]));

        $this->assertTrue(Schema::hasColumns('historical_sales_documents', [
            'bill_discount_type', 'bill_discount_value',
            'tax_split_type', 'cgst_amount', 'sgst_amount', 'igst_amount', 'cess_amount',
            'offer_label_snapshot', 'offer_discount_snapshot',
            'advance_credit_amount', 'calculation_state',
        ]));

        $this->assertTrue(Schema::hasTable('historical_sales_payments'));
    }

    public function test_billable_weight_basis_check_rejects_unrecognised_value(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            $this->assertQueryFails(fn () => $this->makeLine($doc, ['billable_weight_basis' => 'weird']));
        });
    }

    public function test_wastage_basis_check_rejects_unrecognised_value(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            $this->assertQueryFails(fn () => $this->makeLine($doc, ['wastage_basis' => 'weird']));
        });
    }

    public function test_tax_split_type_check_rejects_unrecognised_value(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);

            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'A-1',
                'tax_split_type'           => 'weird',
            ]));
        });
    }

    // -------------------------------------------------------- settlement CHECK

    public function test_settlement_check_holds_with_advance_credit_absorbing_overpayment(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);

            // grand_total 25000, paid 26000 => advance_credit 1000, outstanding 0.
            $doc = $this->makeDocument($shop->id, $batch->id, [
                'original_document_number'    => 'A-2',
                'grand_total'                 => 25000.00,
                'paid_amount_snapshot'        => 26000.00,
                'outstanding_amount_snapshot' => 0.00,
                'advance_credit_amount'       => 1000.00,
            ]);

            $this->assertSame('1000.00', (string) $doc->fresh()->advance_credit_amount);
        });
    }

    public function test_settlement_check_rejects_advance_credit_that_does_not_absorb_the_overpayment(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);

            // paid_total (26000) + outstanding (0) must equal grand_total (25000) +
            // advance_credit; claiming advance_credit=0 here breaks the identity.
            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number'    => 'A-3',
                'grand_total'                 => 25000.00,
                'paid_amount_snapshot'        => 26000.00,
                'outstanding_amount_snapshot' => 0.00,
                'advance_credit_amount'       => 0.00,
            ]));
        });
    }

    public function test_negative_advance_credit_is_rejected_by_non_negative_check(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);

            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number' => 'A-4',
                'advance_credit_amount'    => -1.00,
            ]));
        });
    }

    public function test_outstanding_can_never_be_negative(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);

            $this->assertQueryFails(fn () => $this->makeDocument($shop->id, $batch->id, [
                'original_document_number'    => 'A-5',
                'outstanding_amount_snapshot' => -1.00,
            ]));
        });
    }

    // ---------------------------------------------------------- payments table

    public function test_payment_row_belongs_to_document_and_is_cascade_deleted_with_it(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch   = $this->makeBatch($shop->id);
            $doc     = $this->makeDocument($shop->id, $batch->id);
            $payment = $this->makePayment($doc);

            $doc->status = HistoricalSalesDocument::STATUS_DRAFT; // still draft, deletable
            $doc->delete();

            $this->assertNull(HistoricalSalesPayment::withoutTenant()->find($payment->id));
        });
    }

    public function test_payment_mode_check_rejects_unrecognised_mode(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            $this->assertQueryFails(fn () => $this->makePayment($doc, ['mode' => 'crypto']));
        });
    }

    public function test_payment_amount_non_negative_check(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            $this->assertQueryFails(fn () => $this->makePayment($doc, ['amount' => -1.00]));
        });
    }

    public function test_payment_row_rejects_a_payment_method_from_another_shop(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $methodB = TenantContext::runFor($shopB->id, fn () => $this->makePaymentMethod($shopB->id));

        TenantContext::runFor($shopA->id, function () use ($shopA, $methodB): void {
            $batch = $this->makeBatch($shopA->id);
            $doc   = $this->makeDocument($shopA->id, $batch->id);

            $this->expectException(InvalidArgumentException::class);
            $this->makePayment($doc, ['shop_payment_method_id' => $methodB->id]);
        });
    }

    public function test_deleting_a_live_payment_method_nulls_the_reference_but_snapshot_survives(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $method = $this->makePaymentMethod($shop->id);
            $batch  = $this->makeBatch($shop->id);
            $doc    = $this->makeDocument($shop->id, $batch->id);

            $payment = $this->makePayment($doc, [
                'shop_payment_method_id'  => $method->id,
                'account_label_snapshot'  => 'HDFC Current (****1234)',
            ]);

            $method->delete();
            $payment->refresh();

            $this->assertNull($payment->shop_payment_method_id);
            $this->assertSame('HDFC Current (****1234)', $payment->account_label_snapshot);
            $this->assertSame(
                'HDFC Current (****1234) (No longer active)',
                $payment->displayAccountLabel()
            );
        });
    }

    public function test_cross_shop_payments_never_leak(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $payment = TenantContext::runFor($shopB->id, function () use ($shopB) {
            $batch = $this->makeBatch($shopB->id);
            $doc   = $this->makeDocument($shopB->id, $batch->id);

            return $this->makePayment($doc);
        });

        // Load-bearing proof #1: the row genuinely exists. Without this, a
        // vacuous "0 rows visible" result below could just as easily mean
        // "nothing was ever written" as "the leak was correctly blocked".
        $this->assertSame(
            1,
            HistoricalSalesPayment::withoutTenant()->whereKey($payment->id)->count()
        );

        // Load-bearing proof #2: shop A, INSIDE its own tenant context, sees
        // zero of shop B's rows. This is the actual cross-shop-leak assertion.
        TenantContext::runFor($shopA->id, function (): void {
            $this->assertSame(0, HistoricalSalesPayment::query()->count());
        });

        // NOT load-bearing. Outside any tenant context, BelongsToShop's
        // `WHERE 1=0` global scope makes every count() zero unconditionally —
        // this passes even if cross-shop leakage were completely broken, which
        // is exactly why the original version of this test was a false green
        // (its only cross-shop assertion was this line). Kept purely as a
        // sanity check that the "no context => see nothing" half of the scope
        // is itself wired up; the real proof is the block above.
        $this->assertSame(0, HistoricalSalesPayment::query()->count());
    }

    public function test_creating_a_payment_touches_no_live_ledger_table(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $before = [
            'invoice_payments'         => DB::table('invoice_payments')->count(),
            'cash_transactions'        => DB::table('cash_transactions')->count(),
        ];

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);
            $this->makePayment($doc);
        });

        $this->assertSame($before['invoice_payments'], DB::table('invoice_payments')->count());
        $this->assertSame($before['cash_transactions'], DB::table('cash_transactions')->count());
    }

    // -------------------------------------------------------------- immutability

    public function test_publishing_freezes_new_calculation_columns_on_the_document(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, [
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot modify: bill_discount_value');
            $doc->forceFill(['bill_discount_value' => 500.00])->save();
        });
    }

    public function test_publishing_freezes_new_calculation_columns_on_the_line(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, [
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);
            $line = $this->makeLine($doc);

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot modify: wastage_value');
            $line->forceFill(['wastage_value' => 100.00])->save();
        });
    }

    public function test_direct_sql_update_of_a_new_column_on_a_published_document_is_rejected_by_the_trigger(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, [
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $this->assertQueryFails(fn () => DB::table('historical_sales_documents')
                ->where('id', $doc->id)
                ->update(['cgst_amount' => 999.00]));
        });
    }

    // ---------------------------------------------------- payment immutability (D1)

    public function test_eloquent_update_of_a_payment_is_rejected_once_the_document_is_published(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch   = $this->makeBatch($shop->id);
            $doc     = $this->makeDocument($shop->id, $batch->id);
            $payment = $this->makePayment($doc);

            $doc->forceFill([
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ])->save();

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot be modified or deleted once the parent document is published');
            $payment->amount = 2000.00;
            $payment->save();
        });
    }

    public function test_eloquent_delete_of_a_payment_is_rejected_once_the_document_is_published(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch   = $this->makeBatch($shop->id);
            $doc     = $this->makeDocument($shop->id, $batch->id);
            $payment = $this->makePayment($doc);

            $doc->forceFill([
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ])->save();

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot be modified or deleted once the parent document is published');
            $payment->delete();
        });
    }

    public function test_raw_sql_update_of_a_payment_is_rejected_by_the_trigger_once_the_document_is_published(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch   = $this->makeBatch($shop->id);
            $doc     = $this->makeDocument($shop->id, $batch->id);
            $payment = $this->makePayment($doc);

            $doc->forceFill([
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ])->save();

            // Bypasses the Eloquent `saving` guard entirely — only the DB
            // trigger `historical_sales_payments_guard()` stands here.
            $this->assertQueryFails(fn () => DB::table('historical_sales_payments')
                ->where('id', $payment->id)
                ->update(['amount' => 2000.00]));
        });
    }

    public function test_raw_sql_delete_of_a_payment_is_rejected_by_the_trigger_once_the_document_is_published(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch   = $this->makeBatch($shop->id);
            $doc     = $this->makeDocument($shop->id, $batch->id);
            $payment = $this->makePayment($doc);

            $doc->forceFill([
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ])->save();

            $this->assertQueryFails(fn () => DB::table('historical_sales_payments')
                ->where('id', $payment->id)
                ->delete());
        });
    }

    public function test_eloquent_insert_of_a_payment_into_an_already_published_document_is_rejected(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, [
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('cannot be inserted once the parent document is published');
            $this->makePayment($doc);
        });
    }

    public function test_raw_sql_insert_of_a_payment_into_an_already_published_document_is_rejected_by_the_trigger(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id, [
                'status'       => HistoricalSalesDocument::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            // `was_linked_to_payment_method` is deliberately the DB-safe
            // string '0', not the PHP bool `false`: this is a raw query
            // builder write bypassing Eloquent entirely, so the app-wide
            // `eloquent.saving: *` boolean normalizer (AppServiceProvider)
            // never sees it — a real PHP bool here is independently rejected
            // by Postgres (`...boolean but expression is of type integer`)
            // regardless of what the trigger does, which would make this
            // assertQueryFails() pass for the wrong reason. Every raw insert
            // below in this file follows the same convention.
            $this->assertQueryFails(fn () => DB::table('historical_sales_payments')->insert([
                'shop_id'                      => $shop->id,
                'historical_sales_document_id' => $doc->id,
                'mode'                         => HistoricalSalesPayment::MODE_CASH,
                'amount'                       => 1000.00,
                'account_label_snapshot'       => 'Cash',
                'was_linked_to_payment_method' => '0',
                'created_at'                   => now(),
                'updated_at'                   => now(),
            ]));
        });
    }

    public function test_trigger_forbids_reassigning_a_payment_to_a_different_document(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch   = $this->makeBatch($shop->id);
            $docOne  = $this->makeDocument($shop->id, $batch->id, ['original_document_number' => 'A-6']);
            $docTwo  = $this->makeDocument($shop->id, $batch->id, ['original_document_number' => 'A-7']);
            $payment = $this->makePayment($docOne);

            // Both documents are still draft — this exercises the
            // reassignment-specific branch of the trigger independently of
            // the published-status branch (the composite FK from migration 3
            // would happily allow this same-shop reassignment; only the new
            // trigger's explicit check rejects it).
            $this->assertQueryFails(fn () => DB::table('historical_sales_payments')
                ->where('id', $payment->id)
                ->update(['historical_sales_document_id' => $docTwo->id]));
        });
    }

    public function test_composite_foreign_key_rejects_a_payment_whose_shop_id_does_not_match_its_document(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $docA = TenantContext::runFor($shopA->id, function () use ($shopA) {
            $batch = $this->makeBatch($shopA->id);

            return $this->makeDocument($shopA->id, $batch->id);
        });

        // No new trigger logic guards this — the pre-existing composite FK
        // `historical_payments_document_shop_foreign` (untouched migration 3)
        // already has no matching (id, shop_id) tuple for this combination.
        $this->assertQueryFails(fn () => DB::table('historical_sales_payments')->insert([
            'shop_id'                      => $shopB->id,
            'historical_sales_document_id' => $docA->id,
            'mode'                         => HistoricalSalesPayment::MODE_CASH,
            'amount'                       => 1000.00,
            'account_label_snapshot'       => 'Cash',
            'was_linked_to_payment_method' => '0',
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]));
    }

    public function test_trigger_rejects_a_cross_shop_payment_method_reference_inserted_via_raw_sql(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();

        $methodB = TenantContext::runFor($shopB->id, fn () => $this->makePaymentMethod($shopB->id));

        $docA = TenantContext::runFor($shopA->id, function () use ($shopA) {
            $batch = $this->makeBatch($shopA->id);

            return $this->makeDocument($shopA->id, $batch->id);
        });

        // Bypasses HistoricalSalesPayment::assertPaymentMethodBelongsToOwnShop()
        // entirely (raw query builder, no Eloquent guard) — only the DB
        // trigger stands between this write and a cross-shop account
        // reference.
        $this->assertQueryFails(fn () => DB::table('historical_sales_payments')->insert([
            'shop_id'                      => $shopA->id,
            'historical_sales_document_id' => $docA->id,
            'shop_payment_method_id'       => $methodB->id,
            'mode'                         => HistoricalSalesPayment::MODE_BANK,
            'amount'                       => 1000.00,
            'account_label_snapshot'       => 'HDFC Current',
            'was_linked_to_payment_method' => '1',
            'created_at'                   => now(),
            'updated_at'                   => now(),
        ]));
    }

    // ------------------------------------------------ account label snapshot (D3)

    public function test_db_rejects_a_null_account_label_snapshot_inserted_via_raw_sql(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            $this->assertQueryFails(fn () => DB::table('historical_sales_payments')->insert([
                'shop_id'                      => $shop->id,
                'historical_sales_document_id' => $doc->id,
                'mode'                         => HistoricalSalesPayment::MODE_CASH,
                'amount'                       => 1000.00,
                'account_label_snapshot'       => null,
                'was_linked_to_payment_method' => '0',
                'created_at'                   => now(),
                'updated_at'                   => now(),
            ]));
        });
    }

    public function test_db_rejects_a_blank_account_label_snapshot_inserted_via_raw_sql(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            $this->assertQueryFails(fn () => DB::table('historical_sales_payments')->insert([
                'shop_id'                      => $shop->id,
                'historical_sales_document_id' => $doc->id,
                'mode'                         => HistoricalSalesPayment::MODE_CASH,
                'amount'                       => 1000.00,
                'account_label_snapshot'       => '   ',
                'was_linked_to_payment_method' => '0',
                'created_at'                   => now(),
                'updated_at'                   => now(),
            ]));
        });
    }

    public function test_model_guard_rejects_a_blank_account_label_snapshot_via_eloquent(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch  = $this->makeBatch($shop->id);
            $doc    = $this->makeDocument($shop->id, $batch->id);
            $method = $this->makePaymentMethod($shop->id);

            // A linked payment method must supply its own real label —
            // normalizeAccountLabelSnapshot() deliberately does not
            // substitute a mode-derived fallback for a linked row, so a
            // blank caller-supplied snapshot must be rejected here, at the
            // app layer, before it ever reaches the DB constraint.
            $this->expectException(LogicException::class);
            $this->expectExceptionMessage('non-blank account_label_snapshot');
            $this->makePayment($doc, [
                'shop_payment_method_id' => $method->id,
                'account_label_snapshot' => '  ',
            ]);
        });
    }

    public function test_accountless_payment_gets_a_mode_derived_label_automatically(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            $payment = $this->makePayment($doc, [
                'mode'                   => HistoricalSalesPayment::MODE_OLD_GOLD,
                'account_label_snapshot' => null,
            ]);

            $this->assertSame('Old gold', $payment->fresh()->account_label_snapshot);
            $this->assertFalse($payment->was_linked_to_payment_method);
        });
    }

    /**
     * The real defect behind this session's debug investigation: creating a
     * payment WITH a live `shop_payment_method_id` and WITHOUT the caller
     * separately passing `was_linked_to_payment_method` must still derive
     * `true` at INSERT time — not silently keep the DB column default
     * (`false`). This is exercised end-to-end (not just the marker column)
     * in `test_deleting_a_live_payment_method_nulls_the_reference_but_snapshot_survives`
     * below; this test isolates the derivation itself.
     */
    public function test_was_linked_to_payment_method_is_derived_true_when_a_method_is_linked_at_creation(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch  = $this->makeBatch($shop->id);
            $doc    = $this->makeDocument($shop->id, $batch->id);
            $method = $this->makePaymentMethod($shop->id);

            $payment = $this->makePayment($doc, [
                'shop_payment_method_id' => $method->id,
                'account_label_snapshot' => 'HDFC Current (****1234)',
            ]);

            $this->assertTrue($payment->was_linked_to_payment_method);
            $this->assertTrue($payment->fresh()->was_linked_to_payment_method);
        });
    }

    /**
     * An explicitly caller-supplied value (whichever direction) must win over
     * the derive-if-absent default — the derivation only fills the attribute
     * in when the caller left it unset, it never overwrites.
     */
    public function test_was_linked_to_payment_method_explicit_value_is_never_overridden_by_derivation(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            // Explicit true with no linked method at all.
            $linkless = $this->makePayment($doc, ['was_linked_to_payment_method' => true]);
            $this->assertTrue($linkless->fresh()->was_linked_to_payment_method);

            // Explicit false, also with no linked method — the ordinary case,
            // proven not to be disturbed by the derivation.
            $unlinked = $this->makePayment($doc, ['was_linked_to_payment_method' => false]);
            $this->assertFalse($unlinked->fresh()->was_linked_to_payment_method);
        });
    }

    public function test_a_never_linked_custom_payment_never_shows_no_longer_active(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch = $this->makeBatch($shop->id);
            $doc   = $this->makeDocument($shop->id, $batch->id);

            // Genuinely accountless from the start — no shop_payment_method_id
            // was ever set on this row. Before the D3 fix, displayAccountLabel()
            // treated "no active method" as sufficient for the "(No longer
            // active)" suffix, mislabelling a row that was never linked to
            // anything in the first place.
            $payment = $this->makePayment($doc, [
                'mode'                   => HistoricalSalesPayment::MODE_CASH,
                'account_label_snapshot' => null,
            ]);

            $this->assertFalse($payment->was_linked_to_payment_method);
            $this->assertSame('Cash', $payment->displayAccountLabel());
        });
    }

    /**
     * Foundation-reaudit §7 CONCERN, closed here. The pre-fix
     * `deriveWasLinkedToPaymentMethod()` was write-once, keyed off "has this
     * attribute EVER been assigned before" rather than the payment's actual
     * link state: a payment created accountless (marker correctly `false`)
     * and later attached to a real `ShopPaymentMethod` WHILE STILL DRAFT never
     * got the marker flipped, because by the second `save()` the attribute
     * was already present in `getAttributes()` from the first save, so the
     * derive-if-absent guard silently skipped re-deriving even though
     * `shop_payment_method_id` had just become non-null. `displayAccountLabel()`
     * would then never show "(No longer active)" for such a row after the
     * method was later deleted, despite it carrying a real preserved account
     * snapshot. The fix makes the marker MONOTONIC: any `saving` with a
     * non-null `shop_payment_method_id` forces the marker `true`
     * unconditionally, regardless of what was persisted before.
     */
    public function test_marker_is_forced_true_when_a_method_is_attached_to_a_previously_accountless_draft_row(): void
    {
        [, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, function () use ($shop): void {
            $batch  = $this->makeBatch($shop->id);
            $doc    = $this->makeDocument($shop->id, $batch->id);
            $method = $this->makePaymentMethod($shop->id);

            // Step 1 — genuinely accountless at creation.
            $payment = $this->makePayment($doc, [
                'mode'                   => HistoricalSalesPayment::MODE_CASH,
                'account_label_snapshot' => null,
            ]);
            $this->assertFalse($payment->fresh()->was_linked_to_payment_method);

            // Step 2 — attached to a real method while the document is still a
            // draft. The pre-fix code left the marker `false` here.
            $payment->shop_payment_method_id = $method->id;
            $payment->account_label_snapshot = $method->name;
            $payment->save();

            $this->assertTrue(
                $payment->fresh()->was_linked_to_payment_method,
                'The marker must flip to true the moment a real method is attached, not stay latched at its first-write value.'
            );

            // Step 3 — the method is later deleted (FK ON DELETE SET NULL).
            $method->delete();
            $payment->refresh();

            $this->assertNull($payment->shop_payment_method_id);
            $this->assertTrue(
                $payment->was_linked_to_payment_method,
                'Deleting the method must not un-link history — the marker must stay true.'
            );
            $this->assertSame(
                $method->name . ' (No longer active)',
                $payment->displayAccountLabel()
            );
        });
    }
}
