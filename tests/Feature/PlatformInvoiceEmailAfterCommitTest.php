<?php

namespace Tests\Feature;

use App\Jobs\SendPlatformInvoiceEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * The platform-invoice email must never leave the building from inside an open
 * transaction.
 *
 * Two of this job's dispatch sites — ShopController::store() and
 * DhiranOnboardingController — run inside the shop-creation transaction. A bare
 * dispatch there hands a worker an invoice id that is not yet visible (worker
 * logs "invoice not found"), or, on the sync driver, sends the customer an
 * invoice email for a shop the very next statement rolls back.
 *
 * The guard lives on the job rather than at each call site, so these tests pin
 * the job's own behaviour instead of one controller's.
 *
 * Driver note: `sync`, not Queue::fake(). The deferral lives in the queue
 * drivers' push() (SyncQueue::push checks shouldDispatchAfterCommit); QueueFake
 * never goes through it, so faking the queue would make this pass on the broken
 * code. `database` is no good either — its jobs row is written on the same
 * connection, so a rollback erases the evidence whether the fix is there or not.
 */
class PlatformInvoiceEmailAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /** An id that cannot exist, so handle() takes its logged early-return path. */
    private const MISSING_INVOICE = 987654321;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
    }

    public function test_the_job_declares_itself_after_commit(): void
    {
        $this->assertTrue(
            (new SendPlatformInvoiceEmail(1))->afterCommit,
            'a bare dispatch() inside a transaction must not reach the queue',
        );
    }

    public function test_a_rolled_back_transaction_never_runs_the_job(): void
    {
        // handle() logs exactly this when it cannot find the invoice, which makes
        // "did the job run" observable without building an invoice fixture.
        Log::shouldReceive('warning')
            ->with('SendPlatformInvoiceEmail: invoice not found', Mockery::any())
            ->never();

        try {
            DB::transaction(function (): void {
                dispatch(new SendPlatformInvoiceEmail(self::MISSING_INVOICE));

                // Stands in for anything later in the shop-creation transaction
                // failing — a constraint, a validation, a dead API.
                throw new RuntimeException('shop creation failed after invoicing');
            });
        } catch (RuntimeException) {
            // expected
        }
    }

    public function test_the_job_still_runs_when_no_transaction_is_open(): void
    {
        // afterCommit must not quietly become "never sends". Outside a
        // transaction the deferral is a no-op and the job runs as before.
        Log::shouldReceive('warning')
            ->with('SendPlatformInvoiceEmail: invoice not found', Mockery::any())
            ->once();

        dispatch(new SendPlatformInvoiceEmail(self::MISSING_INVOICE));
    }
}
