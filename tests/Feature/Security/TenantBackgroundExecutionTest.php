<?php

namespace Tests\Feature\Security;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §7e, background execution. A scheduled command runs in a console process
 * with no tenant context, where every BelongsToShop query fails closed
 * (`1 = 0`). A command that iterates shops must therefore enter each shop's
 * context itself; one that does not silently does nothing.
 *
 * Commands run exactly as the scheduler runs them: no TenantContext set.
 */
class TenantBackgroundExecutionTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** A customer holding $points from one earn transaction that expires at $expiresAt. */
    private function pointsHolder(int $shopId, int $points, string $expiresAt): array
    {
        $customer = $this->createCustomer($shopId);
        DB::table('customers')->where('id', $customer->id)->update(['loyalty_points' => $points]);
        $txn = DB::table('loyalty_transactions')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customer->id, 'type' => 'earn', 'points' => $points,
            'description' => 'fixture', 'balance_after' => $points, 'expires_at' => $expiresAt,
            'expired' => DB::raw('false'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$customer->id, $txn];
    }

    private function points(int $customerId): int
    {
        return (int) DB::table('customers')->where('id', $customerId)->value('loyalty_points');
    }

    /**
     * S3-16. `loyalty:expire` (scheduled daily) used to call the expiry with
     * no tenant context, so every LoyaltyTransaction query was `... AND 1 = 0`:
     * nothing expired and the command reported success. Entering each shop's
     * context then hit the append-only trigger (Art. IX.A #9) on the old
     * `expired` update. Expiry is now append-only and starts only at
     * loyalty.expiry_active_from (LoyaltyExpiryTest); unset, as here, the run
     * must still reach each shop's ledger — and report it — while writing
     * nothing.
     */
    public function test_s3_16_scheduled_loyalty_expiry_reaches_each_shop_and_writes_nothing_until_activated(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        [$customerA, $txnA] = $this->pointsHolder($shopA->id, 100, now()->subDay()->toDateTimeString());
        [$customerB, $txnB] = $this->pointsHolder($shopB->id, 70, now()->subDay()->toDateTimeString());
        config(['loyalty.expiry_active_from' => null]);
        $this->assertNull(TenantContext::get(), 'run as the scheduler does: no tenant context');

        $this->artisan('loyalty:expire')
            ->expectsOutputToContain("Shop #{$shopA->id}: nothing written; overdue, kept: 1 lot(s) / 100 pts")
            ->expectsOutputToContain("Shop #{$shopB->id}: nothing written; overdue, kept: 1 lot(s) / 70 pts")
            ->assertExitCode(0);

        $this->assertSame(100, $this->points($customerA));
        $this->assertSame(70, $this->points($customerB));
        $this->assertFalse((bool) DB::table('loyalty_transactions')->where('id', $txnA)->value('expired'));
        $this->assertFalse((bool) DB::table('loyalty_transactions')->where('id', $txnB)->value('expired'));
        $this->assertSame(0, DB::table('loyalty_transactions')->where('description', 'Points expired')->count(),
            'and nothing is written in either shop');
    }

    // ── Worker boundary (§7e hardening) ───────────────────────────────────
    //
    // TenantContext is cleared on the queue worker's Looping event, before
    // each job is fetched (AppServiceProvider). These tests pin what that
    // choice must NOT break: a job run synchronously inside a request, and
    // nested runFor. The reused worker itself is exercised by
    // tests/Concurrency/queue_worker_tenant_reuse.php.

    /**
     * A queued job run on the `sync` connection goes through SyncQueue, which
     * raises JobProcessing/JobProcessed exactly as a worker does — the path a
     * request takes when it dispatches with QUEUE_CONNECTION=sync. (A closure
     * passed to dispatch_sync() is instead called directly, with no queue
     * events, so it would test nothing here.)
     */
    private function runOnSyncQueue(\Closure $job): void
    {
        dispatch($job)->onConnection('sync');
    }

    /** Written by queued closures: they are serialized, so a by-reference capture is not. */
    public static ?int $seenInsideJob = null;

    public function test_a_job_run_on_the_sync_queue_keeps_its_callers_tenant_context(): void
    {
        self::$seenInsideJob = null;
        $processing = 0;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessing::class, function () use (&$processing) { $processing++; });
        TenantContext::set(101);

        $this->runOnSyncQueue(function () {
            TenantContext::runFor(202, function () { TenantBackgroundExecutionTest::$seenInsideJob = TenantContext::get(); });
        });

        $this->assertSame(1, $processing, 'the job went through the queue lifecycle');
        $this->assertSame(202, self::$seenInsideJob, 'the job ran under its own shop');
        $this->assertSame(101, TenantContext::get(), "the caller's context survives a synchronous job");
    }

    /**
     * Control for the choice of event: JobProcessing (Queue::before) fires for
     * a synchronous job too, so clearing there would have wiped the caller's
     * context. Registered here only; the application clears on Looping.
     */
    public function test_control_a_clear_on_queue_before_would_wipe_a_synchronous_callers_context(): void
    {
        \Illuminate\Support\Facades\Queue::before(fn () => TenantContext::clear());
        TenantContext::set(101);

        $this->runOnSyncQueue(function () {});

        $this->assertNull(TenantContext::get(), 'JobProcessing fired inside the caller and cleared its context');
    }

    public function test_nested_run_for_restores_each_level(): void
    {
        $trail = [];
        TenantContext::runFor(1, function () use (&$trail) {
            $trail[] = TenantContext::get();
            TenantContext::runFor(2, function () use (&$trail) { $trail[] = TenantContext::get(); });
            $trail[] = TenantContext::get();
            try {
                TenantContext::runFor(3, fn () => throw new \RuntimeException('inner failure'));
            } catch (\RuntimeException) {
                $trail[] = TenantContext::get();
            }
        });

        $this->assertSame([1, 2, 1, 1], $trail);
        $this->assertNull(TenantContext::get());
    }
}

