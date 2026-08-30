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
        $payment->forceFill(array_merge([
            'shop_id'                      => $document->shop_id,
            'historical_sales_document_id' => $document->getKey(),
            'mode'                         => HistoricalSalesPayment::MODE_CASH,
            'amount'                       => 1000.00,
        ], $attrs));
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

        TenantContext::runFor($shopB->id, function () use ($shopB): void {
            $batch = $this->makeBatch($shopB->id);
            $doc   = $this->makeDocument($shopB->id, $batch->id);
            $this->makePayment($doc);
        });

        TenantContext::runFor($shopA->id, function (): void {
            $this->assertSame(0, HistoricalSalesPayment::query()->count());
        });

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
}
