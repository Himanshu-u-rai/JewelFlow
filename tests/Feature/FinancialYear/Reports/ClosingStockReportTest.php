<?php

namespace Tests\Feature\FinancialYear\Reports;

use App\Models\Shop;
use App\Services\FiscalYearSnapshotService;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Reports\ClosingStockDataset;
use App\Support\FinancialYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** "Closing stock" for a financial year: each piece held on 31 March, valued at the lower of cost and closing rate. */
class ClosingStockReportTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, BuildsYearReports;

    private Shop $shop;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [, $this->shop] = $this->createRetailerTenant();
    }

    private function seedYear(): array
    {
        $this->at('2025-06-01 10:00:00');
        $a = $this->goldItem($this->shop->id, 'CS-A', 50000);
        $b = $this->goldItem($this->shop->id, 'CS-B', 20000, ['gross_weight' => 5, 'net_metal_weight' => 4.5]);
        $d = $this->goldItem($this->shop->id, 'CS-D', 80000); // market (57000) is below cost
        $this->today();
        $this->rate($this->shop->id, '2026-03-31', 'gold', 22, 6000);
        $this->rate($this->shop->id, '2026-04-02', 'gold', 22, 9999); // after year end: never used

        return [$a, $b, $d];
    }

    private function snapshot(string $kind = 'closing'): object
    {
        return app(FiscalYearSnapshotService::class)->take($this->shop->id, FinancialYear::fromLabel('2025-26'), $kind);
    }

    public function test_it_is_registered_for_the_hub_and_needs_the_money_switch(): void
    {
        $def = app(ReportRegistry::class)->definition(ClosingStockDataset::KEY);
        $this->assertSame('closing-stock', ClosingStockDataset::KEY);
        $this->assertSame('reports.money', $def->permissions->effectiveViewGate());
    }

    public function test_live_report_values_each_piece_at_the_lower_of_cost_and_market(): void
    {
        $this->seedYear();
        $ds = $this->runReport(ClosingStockDataset::KEY, $this->shop->id);
        $rows = $this->rowsBy($ds, 'items', 'barcode');

        $this->assertEqualsWithDelta(57000.0, $rows['CS-A']['value_at_rate'], 0.001);
        $this->assertEqualsWithDelta(50000.0, $rows['CS-A']['valuation'], 0.001);   // cost lower
        $this->assertEqualsWithDelta(20000.0, $rows['CS-B']['valuation'], 0.001);   // 27000 market, cost 20000
        $this->assertEqualsWithDelta(57000.0, $rows['CS-D']['valuation'], 0.001);   // market lower
        $this->assertEqualsWithDelta(6000.0, $rows['CS-A']['closing_rate'], 0.001);
        $this->assertSame('2026-03-31', substr((string) $rows['CS-A']['rate_date'], 0, 10));
        $this->assertSame('purchase', $rows['CS-A']['cost_source']);
        $this->assertEqualsWithDelta(50000.0, $rows['CS-A']['acquisition_cost'], 0.001);
        $this->assertStringContainsString('Live — no snapshot yet', $ds->meta->filtersApplied['Stock source']);
        $this->assertSame('2025-26', $ds->meta->filtersApplied['Financial year']);
    }

    public function test_totals_per_metal_and_purity(): void
    {
        $this->seedYear();
        $this->at('2025-06-02 10:00:00');
        $this->createItem($this->shop->id, null, [
            'barcode' => 'CS-S', 'metal_type' => 'silver', 'purity' => 92.5, 'gross_weight' => 100, 'net_metal_weight' => 100,
            'acquisition_cost' => 6000, 'acquisition_cost_source' => 'purchase',
        ]);
        $this->today();
        $this->rate($this->shop->id, '2026-03-28', 'silver', 92.5, 80); // 31 Mar has none: the last earlier rate applies

        $ds = $this->runReport(ClosingStockDataset::KEY, $this->shop->id);
        $by = $this->rowsBy($ds, 'by_metal', 'metal');
        $gold = $by['gold'];
        $this->assertSame(3, $gold['items']);
        $this->assertEqualsWithDelta(23.5, $gold['net_weight'], 0.0001);
        $this->assertEqualsWithDelta(25.0, $gold['gross_weight'], 0.0001);
        $this->assertEqualsWithDelta(127000.0, $gold['valuation'], 0.001);
        $this->assertEqualsWithDelta(141000.0, $gold['value_at_rate'], 0.001);
        $this->assertEqualsWithDelta(8000.0, $by['silver']['value_at_rate'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $by['silver']['valuation'], 0.001);

        $rows = $this->rowsBy($ds, 'items', 'barcode');
        $this->assertSame('2026-03-28', substr((string) $rows['CS-S']['rate_date'], 0, 10));
        $this->assertEqualsWithDelta(127000.0 + 6000.0, $ds->section('by_metal')->totals['valuation'], 0.001);
    }

    public function test_unknown_cost_is_excluded_from_the_valuation_and_counted_never_guessed(): void
    {
        $this->seedYear();
        $this->at('2025-06-03 10:00:00');
        $unknown = $this->goldItem($this->shop->id, 'CS-U', null);
        $this->today();
        $this->assertNull(DB::table('items')->where('id', $unknown->id)->value('acquisition_cost'));

        $ds = $this->runReport(ClosingStockDataset::KEY, $this->shop->id);
        $row = $this->rowsBy($ds, 'items', 'barcode')['CS-U'];
        $this->assertNull($row['valuation']);
        $this->assertSame('unknown', $row['cost_source']);
        $this->assertEqualsWithDelta(57000.0, $row['value_at_rate'], 0.001); // market is still shown
        $gold = $this->rowsBy($ds, 'by_metal', 'metal')['gold'];
        $this->assertSame(1, $gold['not_valued']);
        $this->assertSame(4, $gold['items']);
        $this->assertEqualsWithDelta(127000.0, $gold['valuation'], 0.001, 'the unknown piece adds nothing');
    }

    public function test_a_piece_with_no_rate_on_or_before_year_end_is_not_valued(): void
    {
        $this->at('2025-06-03 10:00:00');
        $this->goldItem($this->shop->id, 'CS-N', 10000);
        $this->today();
        $this->rate($this->shop->id, '2026-04-02', 'gold', 22, 9999); // only a rate after year end exists

        $ds = $this->runReport(ClosingStockDataset::KEY, $this->shop->id);
        $row = $this->rowsBy($ds, 'items', 'barcode')['CS-N'];
        $this->assertNull($row['closing_rate']);
        $this->assertNull($row['value_at_rate']);
        $this->assertNull($row['valuation']);
        $this->assertSame(1, $this->rowsBy($ds, 'by_metal', 'metal')['gold']['not_valued']);
    }

    public function test_year_boundary_in_indian_time_and_sold_before_or_after_year_end(): void
    {
        $this->at('2026-03-31 23:59:00');
        $this->goldItem($this->shop->id, 'CS-LATE', 1000);
        $this->at('2026-04-01 00:00:00');
        $this->goldItem($this->shop->id, 'CS-NEXT', 1000);
        $this->at('2025-05-01 10:00:00');
        $soldInYear = $this->goldItem($this->shop->id, 'CS-SOLD', 1000);
        $soldAfter = $this->goldItem($this->shop->id, 'CS-AFTER', 1000);
        $this->at('2026-02-01 10:00:00');
        $this->setStatus($soldInYear->id, 'sold');
        $this->at('2026-04-05 10:00:00');
        $this->setStatus($soldAfter->id, 'sold');
        $this->today();

        $barcodes = array_keys($this->rowsBy($this->runReport(ClosingStockDataset::KEY, $this->shop->id), 'items', 'barcode'));
        sort($barcodes);
        $this->assertSame(['CS-AFTER', 'CS-LATE'], $barcodes);
    }

    public function test_two_shops_are_isolated(): void
    {
        $this->seedYear();
        [, $other] = $this->createRetailerTenant();
        $this->at('2025-06-01 10:00:00');
        $this->goldItem($other->id, 'OTHER-1', 1234);
        $this->today();

        $mine = array_keys($this->rowsBy($this->runReport(ClosingStockDataset::KEY, $this->shop->id), 'items', 'barcode'));
        $theirs = array_keys($this->rowsBy($this->runReport(ClosingStockDataset::KEY, $other->id), 'items', 'barcode'));
        $this->assertNotContains('OTHER-1', $mine);
        $this->assertSame(['OTHER-1'], $theirs);
    }

    public function test_snapshot_and_live_give_the_same_numbers_when_nothing_changed(): void
    {
        $this->seedYear();
        $live = $this->runReport(ClosingStockDataset::KEY, $this->shop->id);
        $this->snapshot();
        $fromSnap = $this->runReport(ClosingStockDataset::KEY, $this->shop->id);

        $this->assertStringContainsString('From snapshot v1 taken', $fromSnap->meta->filtersApplied['Stock source']);
        $this->assertEquals($live->section('items')->rows, $fromSnap->section('items')->rows);
        $this->assertEquals($live->section('by_metal')->rows, $fromSnap->section('by_metal')->rows);
        $this->assertEquals($live->section('by_metal')->totals, $fromSnap->section('by_metal')->totals);
    }

    public function test_the_latest_snapshot_is_the_source_of_truth_even_when_the_books_moved_on(): void
    {
        [$a] = $this->seedYear();
        $this->snapshot();
        // A later back-dated sale of CS-A, inside the year.
        $this->rawMovement($this->shop->id, $a->id, 'sold', 'in_stock', 'sold', '2026-01-10 10:00:00');

        $ds = $this->runReport(ClosingStockDataset::KEY, $this->shop->id);
        $this->assertArrayHasKey('CS-A', $this->rowsBy($ds, 'items', 'barcode'), 'snapshot v1 is frozen');
        $this->assertStringContainsString('snapshot v1', $ds->meta->filtersApplied['Stock source']);

        $this->snapshot(); // closing snapshot v2 captures the correction
        $ds = $this->runReport(ClosingStockDataset::KEY, $this->shop->id);
        $this->assertArrayNotHasKey('CS-A', $this->rowsBy($ds, 'items', 'barcode'));
        $this->assertStringContainsString('snapshot v2', $ds->meta->filtersApplied['Stock source']);
    }

    public function test_cost_columns_need_the_sensitive_opt_in(): void
    {
        $this->seedYear();
        $without = $this->keysFor(ClosingStockDataset::KEY, sensitive: false);
        $with = $this->keysFor(ClosingStockDataset::KEY, sensitive: true);
        $this->assertNotContains('acquisition_cost', $without);
        $this->assertNotContains('cost_source', $without);
        $this->assertContains('acquisition_cost', $with);

        $ds = $this->runReport(ClosingStockDataset::KEY, $this->shop->id, sensitive: false);
        $this->assertArrayNotHasKey('acquisition_cost', $ds->section('items')->rows[0]);
        $this->assertArrayNotHasKey('cost_source', $ds->section('items')->rows[0]);
    }
}
