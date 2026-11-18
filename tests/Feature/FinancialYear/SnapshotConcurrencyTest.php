<?php

namespace Tests\Feature\FinancialYear;

use App\Services\FiscalYearSnapshotService;
use App\Support\FinancialYear;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** Two owners press "take snapshot" at the same instant: two real sessions, distinct versions, one automatic. */
class SnapshotConcurrencyTest extends TestCase
{
    use DatabaseTruncation, CreatesTestTenant, SnapshotTestHelpers;

    protected $exceptTables = ['permissions', 'platform_products', 'platform_settings'];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        config(['database.connections.pgsql_rival' => config('database.connections.pgsql')]);
        DB::purge('pgsql_rival');
    }

    protected function tearDown(): void
    {
        if (RefreshDatabaseState::$migrated && $this->app) {
            $this->truncateDatabaseTables();
        }
        parent::tearDown();
    }

    private function asRival(callable $fn)
    {
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_rival');
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($original);
        }
    }

    private function expectHeldBack(callable $attempt): void
    {
        $this->asRival(function () use ($attempt) {
            DB::statement("SET lock_timeout = '300ms'");
            try {
                $attempt();
                $this->fail('The second session was not held back by the first.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('lock timeout', $e->getMessage());
            }
            DB::statement('SET lock_timeout = 0');
        });
    }

    public function test_second_session_waits_then_gets_the_next_version(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->today();
        $fy = FinancialYear::fromLabel('2025-26');
        $svc = app(FiscalYearSnapshotService::class);

        DB::beginTransaction();
        $first = $svc->take($shop->id, $fy, 'closing');
        $this->expectHeldBack(fn () => $svc->take($shop->id, $fy, 'closing'));
        DB::commit();
        $second = $this->asRival(fn () => $svc->take($shop->id, $fy, 'closing'));

        $this->assertSame([1, 2], [(int) $first->version, (int) $second->version]);
    }

    public function test_automatic_in_flight_blocks_the_other_session_which_then_returns_the_same_one(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $this->today();
        $fy = FinancialYear::fromLabel('2025-26');
        $svc = app(FiscalYearSnapshotService::class);

        DB::beginTransaction();
        $first = $svc->take($shop->id, $fy, 'automatic');
        $this->expectHeldBack(fn () => $svc->take($shop->id, $fy, 'automatic'));
        DB::commit();
        $again = $this->asRival(fn () => $svc->take($shop->id, $fy, 'automatic'));

        $this->assertSame((int) $first->id, (int) $again->id);
        $this->assertSame(1, DB::table('fiscal_year_snapshots')->count());
    }

    public function test_different_shops_do_not_block_each_other(): void
    {
        [, $a] = $this->createRetailerTenant();
        [, $b] = $this->createRetailerTenant();
        $this->today();
        $fy = FinancialYear::fromLabel('2025-26');
        $svc = app(FiscalYearSnapshotService::class);

        DB::beginTransaction();
        $svc->take($a->id, $fy, 'closing');
        $other = $this->asRival(function () use ($svc, $b, $fy) {
            DB::statement("SET lock_timeout = '300ms'");

            return $svc->take($b->id, $fy, 'closing');
        });
        DB::commit();

        $this->assertSame(1, (int) $other->version);
    }
}
