<?php

namespace Tests\Feature\FinancialYear;

use App\Models\Shop;
use App\Services\FiscalYearSnapshotService;
use App\Services\Reporting\Reports\ClosingStockDataset;
use App\Services\Reporting\Reports\StockRegisterWeightDataset;
use App\Services\Reporting\Reports\TradingAccountDataset;
use App\Services\StockAsAt;
use App\Services\StockHistory;
use App\Support\FinancialYear;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FinancialYear\Reports\BuildsYearReports;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The movement ledger is exact only from the day it was deployed (owner-approved 2026-11-16). Anything older was
 * rebuilt from created_at/updated_at, so snapshots and year reports that rest on it must say "reconstructed".
 * `stock_history_starts.exact_from` is the one stored instant per shop (IST wall clock); no row = exact from creation.
 */
class ReconstructedHistoryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, BuildsYearReports;

    private $owner;
    private Shop $shop;
    private FinancialYear $fy;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        [$this->owner, $this->shop] = $this->createRetailerTenant();
        $this->fy = FinancialYear::fromLabel('2025-26');
        $this->today();
    }

    private function exactFrom(int $shopId, string $ist): void
    {
        DB::table('stock_history_starts')->updateOrInsert(['shop_id' => $shopId], ['exact_from' => $ist, 'created_at' => now()]);
    }

    private function take(string $kind = 'automatic', ?int $shopId = null): object
    {
        return app(FiscalYearSnapshotService::class)->take($shopId ?? $this->shop->id, $this->fy, $kind);
    }

    // --- the stored instant -------------------------------------------------------------------------------------

    public function test_a_shop_with_no_row_is_exact_from_its_creation(): void
    {
        $this->assertNull(StockHistory::exactFrom($this->shop->id));
        $this->assertTrue(StockAsAt::isExact($this->shop->id, '2020-03-31'));
        $this->assertSame('exact', StockHistory::basisForDate($this->shop->id, '2026-03-31'));
    }

    public function test_registering_existing_shops_stamps_now_once_and_never_moves_it(): void
    {
        $this->assertSame(0, DB::table('stock_history_starts')->where('shop_id', $this->shop->id)->count());
        StockHistory::registerExistingShops();
        $first = DB::table('stock_history_starts')->where('shop_id', $this->shop->id)->first();
        $this->assertNotNull($first);
        $this->assertSame('2026-11-16', substr((string) $first->exact_from, 0, 10)); // today, IST

        $this->at('2026-12-25 09:00:00');
        StockHistory::registerExistingShops();
        $this->assertSame((string) $first->exact_from, (string) DB::table('stock_history_starts')->where('shop_id', $this->shop->id)->value('exact_from'));
    }

    public function test_registering_uses_an_earlier_exact_movement_if_one_exists(): void
    {
        $item = $this->goldItem($this->shop->id, 'EARLY-1', 100);
        $this->rawMovement($this->shop->id, $item->id, 'status_change', 'in_stock', 'in_stock', '2026-05-05 08:00:00',
            ['created_at' => '2026-05-05 08:00:00']);
        // a backfilled row, however early, never counts as exact history
        $this->rawMovement($this->shop->id, $item->id, 'intake', null, 'in_stock', '2020-01-01 00:00:00',
            ['created_at' => '2026-01-01 00:00:00', 'is_backfilled' => DB::raw('true')]);

        StockHistory::registerExistingShops();
        $this->assertSame('2026-05-05 08:00:00', substr((string) DB::table('stock_history_starts')->where('shop_id', $this->shop->id)->value('exact_from'), 0, 19));
    }

    // --- StockAsAt::isExact, boundary -----------------------------------------------------------------------------

    public function test_is_exact_is_false_before_and_true_after_the_instant(): void
    {
        $this->exactFrom($this->shop->id, '2026-11-10 10:00:00');
        $this->assertFalse(StockAsAt::isExact($this->shop->id, '2026-03-31'));
        $this->assertFalse(StockAsAt::isExact($this->shop->id, '2026-11-09'));
        $this->assertTrue(StockAsAt::isExact($this->shop->id, '2026-11-10')); // end of that day is after 10:00
        $this->assertTrue(StockAsAt::isExact($this->shop->id, '2026-11-16'));
    }

    public function test_boundary_exact_from_at_2359_59_on_31_march_is_exact_but_1_april_midnight_is_not(): void
    {
        $this->exactFrom($this->shop->id, '2026-03-31 23:59:59');
        $this->assertTrue(StockAsAt::isExact($this->shop->id, '2026-03-31'));
        $this->assertSame('exact', StockHistory::basisForYear($this->shop->id, $this->fy));

        $this->exactFrom($this->shop->id, '2026-04-01 00:00:00');
        $this->assertFalse(StockAsAt::isExact($this->shop->id, '2026-03-31'));
        $this->assertSame('reconstructed', StockHistory::basisForYear($this->shop->id, $this->fy));

        $this->exactFrom($this->shop->id, '2026-03-31 23:59:58');
        $this->assertSame('exact', StockHistory::basisForYear($this->shop->id, $this->fy));
    }

    // --- snapshots ------------------------------------------------------------------------------------------------

    public function test_year_ending_before_exact_from_is_taken_but_marked_reconstructed(): void
    {
        $this->exactFrom($this->shop->id, '2026-11-16 00:00:00');
        $snap = $this->take('automatic');
        $this->assertSame('reconstructed', $snap->basis);
        $this->assertSame('reconstructed', DB::table('fiscal_year_snapshots')->where('id', $snap->id)->value('basis'));
    }

    public function test_year_ending_after_exact_from_is_exact(): void
    {
        $this->exactFrom($this->shop->id, '2025-04-01 00:00:00');
        $this->assertSame('exact', $this->take('automatic')->basis);
    }

    public function test_a_shop_without_a_row_gets_an_exact_snapshot(): void
    {
        $this->assertSame('exact', $this->take('automatic')->basis);
    }

    public function test_closing_snapshots_carry_the_basis_too(): void
    {
        $this->exactFrom($this->shop->id, '2026-11-16 00:00:00');
        $this->take('automatic');
        $closing = $this->take('closing');
        $this->assertSame(2, (int) $closing->version);
        $this->assertSame('reconstructed', $closing->basis);
    }

    public function test_two_shops_with_different_exact_from_are_independent(): void
    {
        [, $other] = $this->createRetailerTenant();
        $this->exactFrom($this->shop->id, '2026-11-16 00:00:00');
        $this->exactFrom($other->id, '2025-01-01 00:00:00');

        $this->assertSame('reconstructed', $this->take('automatic')->basis);
        $this->assertSame('exact', $this->take('automatic', $other->id)->basis);
    }

    public function test_the_command_still_takes_the_snapshot_and_marks_it(): void
    {
        $this->at('2026-04-02 00:15:00');
        $this->exactFrom($this->shop->id, '2026-04-02 00:00:00');
        $this->artisan('books:snapshot-year-end')->assertSuccessful();

        $row = DB::table('fiscal_year_snapshots')->where('shop_id', $this->shop->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('automatic', $row->kind);
        $this->assertSame('reconstructed', $row->basis);
    }

    public function test_a_snapshot_row_with_no_stored_basis_is_judged_from_the_instant(): void
    {
        $svc = app(FiscalYearSnapshotService::class);
        $this->exactFrom($this->shop->id, '2026-11-16 00:00:00');
        $snap = $this->take('automatic');

        $legacy = (object) ['shop_id' => $this->shop->id, 'as_at_date' => '2026-03-31', 'basis' => null];
        $this->assertSame('reconstructed', $svc->basisOf($legacy));
        $this->exactFrom($this->shop->id, '2025-01-01 00:00:00');
        $this->assertSame('exact', $svc->basisOf($legacy));
        $this->assertSame('reconstructed', $svc->basisOf($snap)); // a stored basis wins over a later change of mind
    }

    public function test_only_exact_or_reconstructed_can_be_stored(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('fiscal_year_snapshots')->insert([
            'shop_id' => $this->shop->id, 'fiscal_year' => '2025-26', 'version' => 9, 'kind' => 'closing',
            'as_at_date' => '2026-03-31', 'taken_at' => now(), 'totals' => '{}', 'basis' => 'guess',
        ]);
    }

    public function test_snapshots_still_cannot_be_updated_or_deleted(): void
    {
        $snap = $this->take('automatic');
        foreach ([
            fn () => DB::table('fiscal_year_snapshots')->where('id', $snap->id)->update(['basis' => 'exact']),
            fn () => DB::table('fiscal_year_snapshots')->where('id', $snap->id)->delete(),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('A snapshot row was changed.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('append', strtolower($e->getMessage()));
            }
        }
    }

    // --- changesSince ---------------------------------------------------------------------------------------------

    public function test_changes_since_ignores_backfilled_movements(): void
    {
        $this->at('2025-06-01 10:00:00');
        $item = $this->goldItem($this->shop->id, 'BF-1', 100);
        $this->today();
        $snap = $this->take('closing');

        foreach ([true, false] as $backfilled) {
            DB::table('item_movements')->insert([
                'shop_id' => $this->shop->id, 'item_id' => $item->id, 'movement_type' => 'status_change',
                'from_status' => 'in_stock', 'to_status' => 'in_stock', 'gross_weight' => 5, 'net_metal_weight' => 5,
                'purity' => 22, 'metal_type' => 'gold', 'occurred_at' => '2026-02-01 10:00:00',
                'created_at' => '2026-12-01 10:00:00', 'is_backfilled' => $backfilled ? DB::raw('true') : DB::raw('false'),
            ]);
        }
        $this->at('2026-12-02 10:00:00');
        $late = app(FiscalYearSnapshotService::class)->changesSince($snap)['late_movements'];
        $this->assertCount(1, $late);
        $this->assertFalse((bool) $late[0]['is_backfilled']);
    }

    // --- the page -------------------------------------------------------------------------------------------------

    private function page(): string
    {
        return TenantContext::runFor($this->shop->id, fn () => $this->actingAs($this->owner)
            ->get(route('books.year-end', ['fy' => '2025-26']))->assertOk()->getContent());
    }

    public function test_the_year_end_page_warns_when_the_snapshot_is_reconstructed(): void
    {
        $this->exactFrom($this->shop->id, '2026-11-10 10:00:00');
        $this->take('automatic');
        $html = $this->page();
        $this->assertStringContainsString('Reconstructed', $html);
        $this->assertStringContainsString('10 Nov 2026', $html);
        $this->assertStringContainsString('Check them against your physical stock count', $html);
    }

    public function test_the_page_warns_even_before_any_snapshot_exists_and_not_when_exact(): void
    {
        $this->exactFrom($this->shop->id, '2026-11-10 10:00:00');
        $this->assertStringContainsString('Reconstructed', $this->page());

        $this->exactFrom($this->shop->id, '2025-01-01 00:00:00');
        $this->take('automatic');
        $this->assertStringNotContainsString('Reconstructed', $this->page());
    }

    // --- reports --------------------------------------------------------------------------------------------------

    public function test_all_three_stock_reports_carry_the_note_when_the_year_is_before_exact_from(): void
    {
        $this->exactFrom($this->shop->id, '2026-11-10 10:00:00');
        foreach ([ClosingStockDataset::KEY, StockRegisterWeightDataset::KEY, TradingAccountDataset::KEY] as $key) {
            $note = $this->runReport($key, $this->shop->id)->meta->filtersApplied['Stock history'] ?? null;
            $this->assertNotNull($note, $key);
            $this->assertStringContainsString('Reconstructed', $note, $key);
            $this->assertStringContainsString('10 Nov 2026', $note, $key);
        }
    }

    public function test_reports_have_no_note_when_the_history_is_exact(): void
    {
        $this->exactFrom($this->shop->id, '2025-01-01 00:00:00');
        foreach ([ClosingStockDataset::KEY, StockRegisterWeightDataset::KEY, TradingAccountDataset::KEY] as $key) {
            $this->assertArrayNotHasKey('Stock history', $this->runReport($key, $this->shop->id)->meta->filtersApplied, $key);
        }
    }

    public function test_trading_account_notes_when_only_the_opening_year_is_reconstructed(): void
    {
        // Exact from mid-year 2025-26: 2025-26 closing is exact? No: 31 Mar 2026 is after, so exact. The opening
        // (31 Mar 2025) is before, so reconstructed.
        $this->exactFrom($this->shop->id, '2025-10-01 00:00:00');
        $this->assertArrayHasKey('Stock history', $this->runReport(TradingAccountDataset::KEY, $this->shop->id)->meta->filtersApplied);
        $this->assertArrayNotHasKey('Stock history', $this->runReport(ClosingStockDataset::KEY, $this->shop->id)->meta->filtersApplied);
    }
}
