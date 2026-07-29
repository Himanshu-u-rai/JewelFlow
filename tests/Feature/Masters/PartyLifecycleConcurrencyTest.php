<?php

namespace Tests\Feature\Masters;

use App\Models\Customer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MASTERS PART 3 — proof that the archive check is not a TOCTOU hole.
 *
 * Validation alone (layer 1) reads is_active and then lets the request go on to
 * write; between those two moments another user can archive the party. Layer 2,
 * Customer::lockActiveOrFail(), closes that window by re-reading the row FOR
 * UPDATE inside the same transaction as the write, so the archiver and the new
 * commitment are forced into a real order by PostgreSQL rather than by luck.
 *
 * This class deliberately does NOT use RefreshDatabase: its wrapping transaction
 * would make every "connection" share one transaction, and a lock can never
 * conflict with itself. DatabaseTruncation gives real commits on two real
 * connections, which is the only way to observe the lock at all.
 */
class PartyLifecycleConcurrencyTest extends TestCase
{
    use DatabaseTruncation, CreatesTestTenant;

    /**
     * DatabaseTruncation COMMITS a truncate of every table it does not except,
     * unlike RefreshDatabase which only rolls back its own transaction. These
     * three tables are seeded ONCE by migrations (verified: they are the only
     * non-`migrations` tables with rows after a fresh migrate) and every
     * RefreshDatabase test in the suite silently assumes they are still there —
     * createRetailerTenant() syncs an owner against the global `permissions`
     * catalog, so wiping it would 403 every following test. Except them so this
     * test leaves the reference data exactly as it found it.
     */
    protected $exceptTables = ['permissions', 'platform_products', 'platform_settings'];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();

        // A second, independent session against the same database — the "other
        // user" whose click races ours.
        config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
        DB::purge('pgsql_rival');
    }

    protected function tearDown(): void
    {
        // DatabaseTruncation cleans in the NEXT test's setUp, never after this
        // class's FINAL test — so without this, the last test's committed rows
        // (a whole tenant, and crucially the ACTIVE super admin that
        // createRetailerTenant() makes) leak into whatever runs next in the
        // same process. RefreshDatabase tests skip migrate:fresh once
        // RefreshDatabaseState::$migrated is true, so they inherit that extra
        // super admin — and PlatformBoundaryHardeningTest's "last super admin
        // cannot be deleted" finds its admin is not the last one. Truncate
        // after every test so this class leaves the database exactly as a
        // fresh migrate left it (the reference tables in $exceptTables stay).
        if (RefreshDatabaseState::$migrated && $this->app) {
            $this->truncateDatabaseTables();
        }

        parent::tearDown();
    }

    private function rival()
    {
        return DB::connection('pgsql_rival');
    }

    public function test_lock_active_or_fail_refuses_to_run_outside_a_transaction(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        // A lock taken outside a transaction is released immediately, which
        // would look like protection while providing none. Fail loudly instead.
        $this->expectException(LogicException::class);

        Customer::lockActiveOrFail((int) $shop->id, (int) $customer->id, 'customer_id');
    }

    public function test_a_commitment_in_flight_holds_the_row_against_an_archiver(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        DB::beginTransaction();

        try {
            Customer::lockActiveOrFail((int) $shop->id, (int) $customer->id, 'customer_id');

            // NOWAIT turns "would block" into an immediate error, so the test
            // proves the lock exists without waiting on a timeout.
            $blocked = false;
            try {
                $this->rival()->select('select id from customers where id = ? for update nowait', [$customer->id]);
            } catch (QueryException $e) {
                // 55P03 = lock_not_available.
                $blocked = $e->getCode() === '55P03';
            }

            $this->assertTrue($blocked, 'The archiver was able to lock a customer while a commitment was being written against it.');
        } finally {
            DB::rollBack();
        }
    }

    public function test_an_archive_in_flight_holds_the_row_against_a_new_commitment(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        // The archiver gets there first and is still mid-transaction.
        $this->rival()->beginTransaction();
        $this->rival()->select('select id from customers where id = ? for update', [$customer->id]);

        DB::statement("set lock_timeout = '250ms'");

        try {
            $waited = false;
            try {
                DB::transaction(fn () => Customer::lockActiveOrFail((int) $shop->id, (int) $customer->id, 'customer_id'));
            } catch (QueryException $e) {
                // Same 55P03 class: the statement was cancelled waiting for the
                // archiver's lock. Waiting is the point — the commitment cannot
                // read a stale is_active and race past it.
                $waited = $e->getCode() === '55P03';
            }

            $this->assertTrue($waited, 'A new commitment read the customer without waiting for the in-flight archive.');
        } finally {
            DB::statement('set lock_timeout = 0');
            $this->rival()->rollBack();
        }
    }

    public function test_the_archiver_winning_the_race_aborts_the_commitment_with_no_partial_write(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        // The archive commits first; the commitment starts afterwards and must
        // see the new state, not the one validation saw.
        $this->rival()->update('update customers set is_active = false where id = ?', [$customer->id]);

        $rejected = null;

        try {
            DB::transaction(function () use ($shop, $customer) {
                Customer::lockActiveOrFail((int) $shop->id, (int) $customer->id, 'customer_id');

                $this->fail('A commitment was allowed against a customer archived after validation passed.');
            });
        } catch (ValidationException $e) {
            $rejected = $e;
        }

        $this->assertNotNull($rejected);
        $this->assertSame(
            Customer::archivedMessage(),
            $rejected->validator->errors()->first('customer_id'),
        );
    }
}
