<?php

namespace Tests\Feature\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Services\Historical\HistoricalImportService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A local schema gap (a pending Batch 3 migration) surfaced a raw
 * `SQLSTATE[42703] ... calculation_state ...` query exception straight into the
 * flashed `error` toast on /historical/manual — along with SQL text and
 * bindings, which can carry customer PII. preview()/store() must never repeat
 * this: any Throwable that is not a deliberate business message must be
 * logged (App\Http\Controllers\Historical\HistoricalManualEntryController's
 * existing report()/Log::error() convention) and answered with a fixed, safe
 * sentence — exactly like storeAndPublish()'s generic-fault branch already did
 * before this fix.
 */
class HistoricalManualExceptionDisclosureTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array<string, mixed> a bill that is valid apart from the fault under test. */
    private function manualPayload(array $override = []): array
    {
        return array_merge([
            'original_document_number' => 'LEAK-CHECK-0001',
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'customer_name' => 'Asha Traders',
            'grand_total' => 18000,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
        ], $override);
    }

    /** A stand-in for the real fault: a genuine QueryException carrying SQL + bindings, same shape as the reported SQLSTATE[42703]. */
    private function fakeDatabaseFault(): QueryException
    {
        return new QueryException(
            'pgsql',
            'insert into "historical_sales_documents" ("calculation_state", "customer_snapshot") values (?, ?)',
            ['{}', '{"name":"Asha Traders","mobile":"9876543210"}'],
            new \PDOException('SQLSTATE[42703]: Undefined column: column "calculation_state" does not exist')
        );
    }

    public function test_preview_never_flashes_a_raw_database_exception(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->mock(HistoricalImportService::class, function ($mock) {
            $mock->shouldReceive('previewManual')->once()->andThrow($this->fakeDatabaseFault());
        });

        Log::spy();

        $response = $this->actingAs($owner)
            ->from(route('historical.manual.create'))
            ->post(route('historical.manual.preview'), $this->manualPayload());

        $response->assertRedirect(route('historical.manual.create'));

        $error = session('error');
        $this->assertNotEmpty($error);
        $this->assertStringNotContainsString('SQLSTATE', $error);
        $this->assertStringNotContainsString('calculation_state', $error);
        $this->assertStringNotContainsString('9876543210', $error);
        $this->assertStringNotContainsString('insert into', $error);
        $this->assertSame('This bill could not be calculated. Check the entered values and try again.', $error);

        // Old input is still recoverable — the operator does not retype the bill.
        $response->assertSessionHasInput('original_document_number', 'LEAK-CHECK-0001');
        $response->assertSessionHasInput('customer_name', 'Asha Traders');

        Log::shouldHaveReceived('error')->once();
    }

    public function test_store_never_flashes_a_raw_database_exception(): void
    {
        [$owner] = $this->createRetailerTenant();

        $this->mock(HistoricalImportService::class, function ($mock) {
            $mock->shouldReceive('storeManual')->once()->andThrow($this->fakeDatabaseFault());
        });

        Log::spy();

        $response = $this->actingAs($owner)
            ->from(route('historical.manual.preview'))
            ->post(route('historical.manual.store'), $this->manualPayload());

        $response->assertRedirect(route('historical.manual.create'));

        $error = session('error');
        $this->assertNotEmpty($error);
        $this->assertStringNotContainsString('SQLSTATE', $error);
        $this->assertStringNotContainsString('calculation_state', $error);
        $this->assertStringNotContainsString('9876543210', $error);
        $this->assertStringNotContainsString('insert into', $error);
        $this->assertSame(
            'This bill could not be saved and nothing was recorded. Try again, or contact support if the problem continues.',
            $error
        );

        $response->assertSessionHasInput('original_document_number', 'LEAK-CHECK-0001');
        $response->assertSessionHasInput('customer_name', 'Asha Traders');

        Log::shouldHaveReceived('error')->once();
    }

    /**
     * Fixing the disclosure must not touch the normal validation path — a
     * missing required field is still a specific, actionable field error, not
     * the new generic sentence.
     */
    public function test_a_normal_validation_failure_still_gets_a_specific_actionable_error(): void
    {
        [$owner] = $this->createRetailerTenant();

        $response = $this->actingAs($owner)
            ->from(route('historical.manual.create'))
            ->post(route('historical.manual.store'), $this->manualPayload(['grand_total' => null]));

        $response->assertRedirect(route('historical.manual.create'));
        $response->assertSessionHasErrors('grand_total');
        $this->assertNotSame(
            'This bill could not be saved and nothing was recorded. Try again, or contact support if the problem continues.',
            session('error')
        );
    }

    /**
     * The real save handler, exercised directly (bypassing the FormRequest so a
     * genuinely nonexistent customer_id reaches the service, the same way a
     * DB-level fault would mid-transaction) — proves storeManual()'s
     * DB::transaction still leaves no partial document/line/payment behind
     * when a fault strikes after persistDraft() but before the method returns.
     */
    public function test_a_fault_mid_transaction_leaves_no_partial_records(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $before = [
            'historical_sales_documents' => DB::table('historical_sales_documents')->count(),
            'historical_sales_lines' => DB::table('historical_sales_lines')->count(),
            'historical_sales_payments' => DB::table('historical_sales_payments')->count(),
        ];

        $service = app(HistoricalImportService::class);

        try {
            $service->storeManual(
                $shop,
                [
                    'original_document_number' => 'MID-TX-FAULT-0001',
                    'document_date' => '2023-06-15',
                    'source_system' => 'Manual',
                    'customer_name' => 'Asha Traders',
                    'grand_total' => 18000,
                    'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
                ],
                [],
                (int) $owner->id,
                // No customer with this id exists for this shop — linkCustomer()
                // throws LogicException *inside* the same DB::transaction that
                // just persisted the document/lines, forcing a real rollback.
                ['customer_id' => 999999999],
                []
            );
            $this->fail('Expected storeManual() to throw when linking a nonexistent customer.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('customer', strtolower($e->getMessage()));
        }

        $after = [
            'historical_sales_documents' => DB::table('historical_sales_documents')->count(),
            'historical_sales_lines' => DB::table('historical_sales_lines')->count(),
            'historical_sales_payments' => DB::table('historical_sales_payments')->count(),
        ];

        $this->assertSame($before, $after, 'A mid-transaction fault left partial historical rows behind.');
    }
}
