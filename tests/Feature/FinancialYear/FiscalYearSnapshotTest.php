<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Shop;
use App\Services\FiscalYearSnapshotService;
use App\Support\FinancialYear;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** The year-end snapshot: what the shop held on 31 March, valued at lower of cost and closing rate (owner, 2026-11-16). */
class FiscalYearSnapshotTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, SnapshotTestHelpers;

    private Shop $shop;
    private FinancialYear $fy;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [, $this->shop] = $this->createRetailerTenant();
        $this->fy = FinancialYear::fromLabel('2025-26');
        $this->today();
    }

    private function svc(): FiscalYearSnapshotService
    {
        return app(FiscalYearSnapshotService::class);
    }

    private function snapItemIds(object $snapshot): array
    {
        return DB::table('fiscal_year_snapshot_items')->where('snapshot_id', $snapshot->id)->pluck('item_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    private function totals(object $snapshot): array
    {
        $t = $snapshot->totals;

        return is_string($t) ? json_decode($t, true) : (array) $t;
    }

    public function test_a_year_that_has_not_ended_is_refused_and_nothing_is_stored(): void
    {
        foreach (['2026-27', '2027-28'] as $label) {
            try {
                $this->svc()->take($this->shop->id, FinancialYear::fromLabel($label), 'closing');
                $this->fail("accepted unfinished year {$label}");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(0, DB::table('fiscal_year_snapshots')->count());
    }

    public function test_the_year_end_day_is_judged_in_india_not_utc(): void
    {
        // 23:30 IST on 31 March: the year is not over yet.
        $this->at('2026-03-31 23:30:00');
        try {
            $this->svc()->take($this->shop->id, $this->fy, 'closing');
            $this->fail('accepted the last evening of the year');
        } catch (InvalidArgumentException) {
        }
        // 00:30 IST on 1 April is still 31 March in UTC: in India the year has ended.
        $this->at('2026-04-01 00:30:00');
        $this->assertSame('2025-26', $this->svc()->take($this->shop->id, $this->fy, 'closing')->fiscal_year);
    }

    public function test_bad_kind_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc()->take($this->shop->id, $this->fy, 'whatever');
    }

    public function test_items_match_stock_at_31_march(): void
    {
        $this->at('2026-02-01 10:00:00');
        $soldOnApril1 = $this->createItem($this->shop->id);
        $soldOnMarch31 = $this->createItem($this->shop->id);
        $this->at('2026-03-31 11:00:00');
        $this->setStatus($soldOnMarch31->id, 'sold');
        $this->at('2026-04-01 09:00:00');
        $this->setStatus($soldOnApril1->id, 'sold');
        $addedOnApril1 = $this->createItem($this->shop->id);
        $this->today();

        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');

        $this->assertSame([$soldOnApril1->id], $this->snapItemIds($snap));
        $this->assertNotContains($addedOnApril1->id, $this->snapItemIds($snap));
        $this->assertSame('2026-03-31', substr((string) $snap->as_at_date, 0, 10));
        $this->assertSame(1, (int) $snap->version);
    }

    public function test_another_shops_stock_never_leaks_in(): void
    {
        [, $other] = $this->createRetailerTenant();
        $this->at('2026-02-01 10:00:00');
        $mine = $this->createItem($this->shop->id);
        $this->createItem($other->id);
        $this->today();

        $this->assertSame([$mine->id], $this->snapItemIds($this->svc()->take($this->shop->id, $this->fy, 'closing')));
    }

    public function test_valuation_is_the_lower_of_cost_and_closing_rate_and_null_when_cost_unknown(): void
    {
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22.0, 6600);
        $this->at('2026-02-01 10:00:00');
        $cheap = $this->createItem($this->shop->id, null, ['acquisition_cost' => 40000, 'acquisition_cost_source' => 'entered']);
        $dear = $this->createItem($this->shop->id, null, ['acquisition_cost' => 70000, 'acquisition_cost_source' => 'purchase']);
        $unknown = $this->createItem($this->shop->id, null, ['acquisition_cost' => null]);
        $noRate = $this->createItem($this->shop->id, null, ['metal_type' => 'silver', 'purity' => 925, 'acquisition_cost' => 900, 'acquisition_cost_source' => 'entered']);
        $this->today();

        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $rows = DB::table('fiscal_year_snapshot_items')->where('snapshot_id', $snap->id)->get()->keyBy('item_id');

        $this->assertEqualsWithDelta(62700.0, (float) $rows[$cheap->id]->value_at_closing_rate, 0.001);
        $this->assertEqualsWithDelta(6600.0, (float) $rows[$cheap->id]->closing_rate_per_gram, 0.001);
        $this->assertEqualsWithDelta(40000.0, (float) $rows[$cheap->id]->valuation, 0.001, 'cost is lower');
        $this->assertEqualsWithDelta(62700.0, (float) $rows[$dear->id]->valuation, 0.001, 'market is lower');
        $this->assertNull($rows[$unknown->id]->valuation, 'unknown cost is never guessed');
        $this->assertSame('unknown', $rows[$unknown->id]->acquisition_cost_source);
        $this->assertEqualsWithDelta(62700.0, (float) $rows[$unknown->id]->value_at_closing_rate, 0.001, 'market value still recorded');
        $this->assertNull($rows[$noRate->id]->closing_rate_per_gram, 'no silver rate ever entered, none guessed');
        $this->assertNull($rows[$noRate->id]->valuation);
    }

    public function test_the_rate_used_is_the_one_on_31_march_not_another_day(): void
    {
        $this->rate($this->shop->id, '2026-03-30', 'gold', 22.0, 1);
        $this->rate($this->shop->id, '2026-04-01', 'gold', 22.0, 99999);
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22.0, 6600);
        $this->at('2026-02-01 10:00:00');
        $item = $this->createItem($this->shop->id, null, ['acquisition_cost' => 1000000, 'acquisition_cost_source' => 'entered']);
        $this->today();

        $row = DB::table('fiscal_year_snapshot_items')->where('snapshot_id', $this->svc()->take($this->shop->id, $this->fy, 'closing')->id)->where('item_id', $item->id)->first();
        $this->assertEqualsWithDelta(6600.0, (float) $row->closing_rate_per_gram, 0.001);
    }

    public function test_with_no_rate_on_31_march_the_last_rate_in_effect_is_used_and_its_date_recorded(): void
    {
        // Shops skip entering rates on holidays; the year-end must still be valued, at the rate then in force.
        $this->rate($this->shop->id, '2026-03-28', 'gold', 22.0, 6400);
        $this->rate($this->shop->id, '2026-03-29', 'gold', 22.0, 6500);
        $this->rate($this->shop->id, '2026-04-01', 'gold', 22.0, 99999); // after year-end: never used
        $this->at('2026-02-01 10:00:00');
        $item = $this->createItem($this->shop->id, null, ['acquisition_cost' => 1000000, 'acquisition_cost_source' => 'entered']);
        $this->today();

        $row = DB::table('fiscal_year_snapshot_items')->where('snapshot_id', $this->svc()->take($this->shop->id, $this->fy, 'closing')->id)->where('item_id', $item->id)->first();

        $this->assertEqualsWithDelta(6500.0, (float) $row->closing_rate_per_gram, 0.001);
        $this->assertSame('2026-03-29', substr((string) $row->closing_rate_date, 0, 10));
    }

    public function test_totals_group_by_metal_and_purity_and_count_unknown_costs(): void
    {
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22.0, 6600);
        $this->at('2026-02-01 10:00:00');
        $this->createItem($this->shop->id, null, ['acquisition_cost' => 40000, 'acquisition_cost_source' => 'entered']);
        $this->createItem($this->shop->id, null, ['acquisition_cost' => 70000, 'acquisition_cost_source' => 'entered']);
        $this->createItem($this->shop->id, null, ['acquisition_cost' => null]);
        $this->createItem($this->shop->id, null, ['metal_type' => 'silver', 'purity' => 925, 'gross_weight' => 100, 'net_metal_weight' => 90, 'acquisition_cost' => 5000, 'acquisition_cost_source' => 'entered']);
        $this->today();

        $t = $this->totals($this->svc()->take($this->shop->id, $this->fy, 'closing'));

        $this->assertSame(4, $t['item_count']);
        $this->assertSame(1, $t['unknown_cost_count']);
        $this->assertEqualsWithDelta(115000.0, (float) $t['total_acquisition_cost'], 0.001);
        $this->assertEqualsWithDelta(40000.0 + 62700.0, (float) $t['total_valuation'], 0.001);
        $gold = collect($t['by_metal'])->firstWhere('metal_type', 'gold');
        $silver = collect($t['by_metal'])->firstWhere('metal_type', 'silver');
        $this->assertSame(3, $gold['item_count']);
        $this->assertEqualsWithDelta(30.0, (float) $gold['gross_weight'], 0.001);
        $this->assertEqualsWithDelta(28.5, (float) $gold['net_metal_weight'], 0.001);
        $this->assertSame(1, $silver['item_count']);
        $this->assertEqualsWithDelta(90.0, (float) $silver['net_metal_weight'], 0.001);
        $this->assertArrayHasKey('balances', $t, 'the balances slot is always present, null until BalancesAsAt exists');
    }

    public function test_automatic_is_taken_once_and_a_second_call_returns_the_existing_one(): void
    {
        $a = $this->svc()->take($this->shop->id, $this->fy, 'automatic');
        $b = $this->svc()->take($this->shop->id, $this->fy, 'automatic');

        $this->assertSame((int) $a->id, (int) $b->id);
        $this->assertSame(1, DB::table('fiscal_year_snapshots')->count());
    }

    public function test_closing_versions_increment_and_do_not_disturb_automatic(): void
    {
        $auto = $this->svc()->take($this->shop->id, $this->fy, 'automatic');
        $c1 = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $c2 = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $other = $this->svc()->take($this->shop->id, $this->fy->previous(), 'closing');

        $this->assertSame([1, 2, 3, 1], [(int) $auto->version, (int) $c1->version, (int) $c2->version, (int) $other->version]);
        $this->assertSame((int) $c2->id, (int) $this->svc()->latest($this->shop->id, $this->fy)->id);
        $this->assertNull($this->svc()->latest($this->shop->id, FinancialYear::fromLabel('2020-21')));
        $this->assertNull($this->svc()->latest($this->shop->id + 999, $this->fy));
    }

    public function test_snapshot_is_a_frozen_copy_later_changes_do_not_alter_it(): void
    {
        $this->at('2026-02-01 10:00:00');
        $item = $this->createItem($this->shop->id);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');
        $this->setStatus($item->id, 'sold');

        $this->assertSame([$item->id], $this->snapItemIds($snap));
    }

    public function test_balances_come_from_balances_as_at_when_that_service_exists(): void
    {
        $t = $this->totals($this->svc()->take($this->shop->id, $this->fy, 'closing'));
        if (class_exists(\App\Services\BalancesAsAt::class)) {
            $this->assertNotNull($t['balances']);
        } else {
            $this->assertNull($t['balances']);
        }
    }

    // ------------------------------------------------------------ append-only

    public function test_snapshot_rows_cannot_be_updated_or_deleted(): void
    {
        $this->at('2026-02-01 10:00:00');
        $this->createItem($this->shop->id);
        $this->today();
        $snap = $this->svc()->take($this->shop->id, $this->fy, 'closing');

        $attempts = [
            'UPDATE fiscal_year_snapshots SET version = 9 WHERE id = ?',
            'DELETE FROM fiscal_year_snapshots WHERE id = ?',
        ];
        foreach ($attempts as $sql) {
            DB::statement('SAVEPOINT a');
            try {
                DB::statement($sql, [$snap->id]);
                $this->fail("allowed: {$sql}");
            } catch (QueryException) {
                DB::statement('ROLLBACK TO SAVEPOINT a');
            }
        }
        foreach (['UPDATE fiscal_year_snapshot_items SET valuation = 1 WHERE snapshot_id = ?', 'DELETE FROM fiscal_year_snapshot_items WHERE snapshot_id = ?'] as $sql) {
            DB::statement('SAVEPOINT a');
            try {
                DB::statement($sql, [$snap->id]);
                $this->fail("allowed: {$sql}");
            } catch (QueryException) {
                DB::statement('ROLLBACK TO SAVEPOINT a');
            }
        }
        $this->assertSame(1, DB::table('fiscal_year_snapshots')->count());
        $this->assertSame(1, DB::table('fiscal_year_snapshot_items')->count());
    }

    public function test_triggers_are_installed_by_exact_name(): void
    {
        $found = collect(DB::select("SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = 'public'"))->pluck('trigger_name');
        $this->assertContains('fiscal_year_snapshots_append_only_trigger', $found);
        $this->assertContains('fiscal_year_snapshot_items_append_only_trigger', $found);
    }

    public function test_check_constraints_refuse_a_bad_kind_and_duplicate_versions(): void
    {
        $row = ['shop_id' => $this->shop->id, 'fiscal_year' => '2025-26', 'version' => 1, 'kind' => 'automatic',
            'as_at_date' => '2026-03-31', 'taken_at' => now(), 'totals' => '{}', 'created_at' => now()];
        DB::table('fiscal_year_snapshots')->insert($row);

        foreach ([['kind' => 'bogus', 'version' => 2], ['version' => 1]] as $change) {
            DB::statement('SAVEPOINT c');
            try {
                DB::table('fiscal_year_snapshots')->insert(array_merge($row, $change));
                $this->fail('accepted ' . json_encode($change));
            } catch (QueryException) {
                DB::statement('ROLLBACK TO SAVEPOINT c');
            }
        }
        $this->assertSame(1, DB::table('fiscal_year_snapshots')->count());
    }
}
