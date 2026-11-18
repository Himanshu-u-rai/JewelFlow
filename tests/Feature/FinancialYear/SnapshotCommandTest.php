<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Shop;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** books:snapshot-year-end — the automatic 1-April snapshot of the year that just closed (owner, 2026-11-16). */
class SnapshotCommandTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, SnapshotTestHelpers;

    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [, $this->shop] = $this->createRetailerTenant();
        $this->today();
    }

    private function lockDate(): ?string
    {
        $v = DB::table('shop_rules')->where('shop_id', $this->shop->id)->value('financial_lock_date');

        return $v ? substr((string) $v, 0, 10) : null;
    }

    private function snapshots(?int $shopId = null)
    {
        return DB::table('fiscal_year_snapshots')->where('shop_id', $shopId ?? $this->shop->id);
    }

    public function test_it_takes_one_automatic_snapshot_of_the_previous_year(): void
    {
        $this->at('2026-04-01 00:15:00');
        $this->artisan('books:snapshot-year-end')->assertSuccessful();

        $rows = $this->snapshots()->get();
        $this->assertCount(1, $rows);
        $this->assertSame('2025-26', $rows[0]->fiscal_year);
        $this->assertSame('automatic', $rows[0]->kind);
        $this->assertSame(1, (int) $rows[0]->version);
        $this->assertNull($rows[0]->taken_by);
    }

    public function test_rerunning_is_safe_and_changes_nothing(): void
    {
        $this->artisan('books:snapshot-year-end')->assertSuccessful();
        $first = $this->snapshots()->first();
        $this->artisan('books:snapshot-year-end')->assertSuccessful();
        $this->at('2026-09-01 00:15:00');
        $this->artisan('books:snapshot-year-end')->assertSuccessful();

        $this->assertSame(1, $this->snapshots()->count());
        $this->assertSame((int) $first->id, (int) $this->snapshots()->value('id'));
    }

    public function test_it_heals_if_the_server_missed_1_april(): void
    {
        // First run happens weeks late: the year is still the previous one until 31 March 2027.
        $this->at('2026-11-20 00:15:00');
        $this->artisan('books:snapshot-year-end')->assertSuccessful();

        $this->assertSame(['2025-26'], $this->snapshots()->pluck('fiscal_year')->all());
    }

    public function test_after_the_next_year_end_the_new_previous_year_gets_its_own_snapshot(): void
    {
        $this->artisan('books:snapshot-year-end')->assertSuccessful();
        $this->at('2027-04-01 00:15:00');
        $this->artisan('books:snapshot-year-end')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['2025-26', '2026-27'], $this->snapshots()->pluck('fiscal_year')->all());
    }

    public function test_previous_year_is_judged_in_india_at_the_changeover(): void
    {
        // 23:50 IST on 31 March 2027: still 2026-27, so the previous year is 2025-26, not the year ending tonight.
        $this->at('2027-03-31 23:50:00');
        $this->artisan('books:snapshot-year-end')->assertSuccessful();
        $this->assertSame(['2025-26'], $this->snapshots()->pluck('fiscal_year')->all());
    }

    public function test_it_skips_non_retail_shops(): void
    {
        $mfr = $this->createShop('manufacturer');

        $this->artisan('books:snapshot-year-end')->assertSuccessful();

        $this->assertSame(0, $this->snapshots($mfr->id)->count());
        $this->assertSame(1, $this->snapshots()->count());
    }

    public function test_it_never_touches_the_lock_date(): void
    {
        $this->assertNull($this->lockDate());
        $this->artisan('books:snapshot-year-end')->assertSuccessful();
        $this->assertNull($this->lockDate());

        DB::table('shop_rules')->updateOrInsert(['shop_id' => $this->shop->id], ['financial_lock_date' => '2026-01-31', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('2026-01-31', $this->lockDate(), 'fixture sanity');
        $this->at('2027-04-01 00:15:00');
        $this->artisan('books:snapshot-year-end')->assertSuccessful();
        $this->assertSame('2026-01-31', $this->lockDate());
    }

    public function test_one_shops_failure_does_not_stop_the_others(): void
    {
        [, $second] = $this->createRetailerTenant();
        // Make the first shop's snapshot fail: a pre-existing 'automatic' row would be a no-op, so break it with a bad shop edition instead
        // by removing nothing; the realistic failure is an exception inside one shop, simulated via a failing service binding.
        $real = app(\App\Services\FiscalYearSnapshotService::class);
        $bad = $this->shop->id;
        $this->app->bind(\App\Services\FiscalYearSnapshotService::class, fn () => new class($real, $bad) extends \App\Services\FiscalYearSnapshotService {
            public function __construct(private $real, private int $bad) {}
            public function take(int $shopId, \App\Support\FinancialYear $fy, string $kind, ?int $userId = null): object
            {
                if ($shopId === $this->bad) {
                    throw new \RuntimeException('boom');
                }

                return $this->real->take($shopId, $fy, $kind, $userId);
            }
        });

        $this->artisan('books:snapshot-year-end')->assertFailed();

        $this->assertSame(0, $this->snapshots()->count());
        $this->assertSame(1, $this->snapshots($second->id)->count());
    }

    public function test_it_is_scheduled_daily_at_0015_india_time(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command ?? '', 'books:snapshot-year-end'));

        $this->assertNotNull($event, 'books:snapshot-year-end is not scheduled');
        $this->assertSame('15 0 * * *', $event->expression);
        $this->assertSame('Asia/Kolkata', (string) $event->timezone);
    }
}
