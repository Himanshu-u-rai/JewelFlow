<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ReconcileCapturedPayments;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Razorpay\Api\Errors\BadRequestError;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The two scheduled commands that failed in production's logs.
 *
 * subscription:reconcile-payments (every ten minutes) died with an unhandled
 * exception whenever the payment provider timed out or rate-limited the
 * listing call: three timeouts and one "Too many requests" in thirty days.
 *
 * platform:archive-audit-logs (monthly) has never completed and cannot.
 *
 * No provider is contacted here: the listing call is replaced.
 */
class ScheduledCommandFailuresTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** Replace the provider listing with a scripted sequence of outcomes. */
    private function listingReturns(array $outcomes): void
    {
        $command = new class extends ReconcileCapturedPayments
        {
            public static array $outcomes = [];

            protected function recentCapturedPayments(int $days): array
            {
                $next = array_shift(self::$outcomes);

                if ($next instanceof \Throwable) {
                    throw $next;
                }

                return $next ?? [];
            }
        };
        $command::$outcomes = $outcomes;

        $this->app->make(Kernel::class)->registerCommand($command);
    }

    private static function timeout(): \Throwable
    {
        return new \WpOrg\Requests\Exception('cURL error 28: Operation timed out after 60001 milliseconds with 0 bytes received', 'curlerror');
    }

    public function test_a_provider_timeout_defers_the_run_instead_of_failing_it(): void
    {
        $this->listingReturns([self::timeout()]);

        $this->artisan('subscription:reconcile-payments')
            ->expectsOutputToContain('deferred to the next run')
            ->assertExitCode(0);
    }

    public function test_a_provider_rate_limit_defers_the_run(): void
    {
        $this->listingReturns([new BadRequestError('Too many requests', 'BAD_REQUEST_ERROR', 429)]);

        $this->artisan('subscription:reconcile-payments')->assertExitCode(0);
    }

    public function test_an_hour_of_deferred_runs_fails_loudly(): void
    {
        $this->listingReturns(array_fill(0, 6, self::timeout()));

        foreach (range(1, 5) as $ignored) {
            $this->artisan('subscription:reconcile-payments')->assertExitCode(0);
        }

        // The sixth run in a row: an hour with no reconciliation is not noise.
        $this->artisan('subscription:reconcile-payments')->assertExitCode(1);
    }

    public function test_one_good_run_resets_the_count(): void
    {
        $this->listingReturns([...array_fill(0, 5, self::timeout()), [], self::timeout()]);

        foreach (range(1, 5) as $ignored) {
            $this->artisan('subscription:reconcile-payments')->assertExitCode(0);
        }
        $this->artisan('subscription:reconcile-payments')->expectsOutputToContain('Reconcile complete')->assertExitCode(0);
        $this->artisan('subscription:reconcile-payments')->assertExitCode(0);
    }

    public function test_a_refusal_that_is_not_transient_still_fails(): void
    {
        // Wrong credentials, for one: retrying will not help and must be seen.
        $this->listingReturns([new BadRequestError('Authentication failed', 'BAD_REQUEST_ERROR', 401)]);

        $this->expectException(BadRequestError::class);

        Artisan::call('subscription:reconcile-payments');
    }

    public function test_the_audit_log_cannot_be_emptied_into_an_archive(): void
    {
        $admin = $this->createPlatformAdmin();
        $id = DB::table('platform_audit_logs')->insertGetId([
            'actor_admin_id' => $admin->id,
            'action' => 'test.old_entry',
            'target_type' => 'test',
            'target_id' => 0,
            'created_at' => now()->subDays(120),
        ]);

        // Article IX.A, trigger 27: the platform audit log is append-only. An
        // archive that moves rows out would have to delete them.
        try {
            DB::table('platform_audit_logs')->where('id', $id)->delete();
            $this->fail('The append-only trigger did not refuse the delete.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('platform_audit_logs is append-only', $e->getMessage());
        }
    }

    public function test_the_archive_command_is_retired(): void
    {
        $scheduled = collect($this->app->make(Schedule::class)->events())->map->command->implode("\n");

        $this->assertStringNotContainsString('platform:archive-audit-logs', $scheduled);
        $this->assertArrayNotHasKey('platform:archive-audit-logs', Artisan::all());
    }
}
