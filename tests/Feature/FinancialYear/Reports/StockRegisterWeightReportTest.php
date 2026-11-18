<?php

namespace Tests\Feature\FinancialYear\Reports;

use App\Models\Shop;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\Reports\StockRegisterWeightDataset as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** Form 3CD clause 35: opening + received - sold - melted - other = closing, per metal and purity, in weight. */
class StockRegisterWeightReportTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant, BuildsYearReports;

    private Shop $shop;
    private array $ids = [];

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$user, $this->shop] = $this->createRetailerTenant();
        $this->user = $user;
    }

    private $user;

    private function purchaseId(): int
    {
        return (int) DB::table('stock_purchases')->insertGetId([
            'shop_id' => $this->shop->id, 'purchase_number' => 'SP-' . fake()->unique()->numerify('####'),
            'purchase_date' => '2025-05-01', 'status' => 'confirmed', 'entered_by_user_id' => $this->user->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Gold 22k, every piece 10 g gross / 9.5 g net. FY 2025-26:
     * opening 2 (O1 stays, O2 sold in year); received: 3 purchases (I1, I2, M1), 1 manufactured (X1), returned 1 (I1),
     * other 4 (I3, I4 at 23:59 on 31 Mar, S1, D1); sold 3 (O2, I1, S1); melted 1 (M1); deleted 1 (D1).
     * Closing = 2 + 9 - 5 = 6: O1, I1, I2, I3, I4, X1.
     */
    private function seedYear(): void
    {
        $s = $this->shop->id;
        $this->at('2025-03-20 10:00:00');
        $this->ids['O1'] = $this->goldItem($s, 'O1', 1000)->id;
        $this->at('2025-02-01 10:00:00');
        $this->ids['O2'] = $this->goldItem($s, 'O2', 1000)->id;

        $this->at('2025-05-01 10:00:00');
        $sp = $this->purchaseId();
        $this->ids['I1'] = $this->goldItem($s, 'I1', 1000, ['stock_purchase_id' => $sp])->id;
        $this->ids['I2'] = $this->goldItem($s, 'I2', 1000, ['stock_purchase_id' => $sp])->id;
        $this->at('2025-05-02 10:00:00');
        $this->ids['M1'] = $this->goldItem($s, 'M1', 1000, ['stock_purchase_id' => $sp])->id;
        $this->at('2025-05-03 10:00:00');
        $this->ids['S1'] = $this->goldItem($s, 'S1', 1000)->id;
        $this->at('2025-06-01 10:00:00');
        $this->ids['I3'] = $this->goldItem($s, 'I3', 1000)->id;
        $this->at('2026-03-31 23:59:00');
        $this->ids['I4'] = $this->goldItem($s, 'I4', 1000)->id;
        $this->at('2026-04-01 00:00:00');
        $this->ids['I5'] = $this->goldItem($s, 'I5', 1000)->id; // next year's

        $this->rawMovement($s, 9001, 'intake', null, 'in_stock', '2025-07-01 10:00:00', ['reference_type' => 'job_order', 'reference_id' => 1]); // X1
        $this->rawMovement($s, 9002, 'intake', null, 'in_stock', '2025-10-01 10:00:00'); // D1
        $this->rawMovement($s, 9002, 'deleted', 'in_stock', null, '2025-11-01 10:00:00');

        $this->at('2025-06-01 12:00:00');
        $this->setStatus($this->ids['S1'], 'sold');
        $this->at('2025-07-10 12:00:00');
        $this->setStatus($this->ids['O2'], 'sold');
        $this->at('2025-08-01 12:00:00');
        $this->setStatus($this->ids['I1'], 'sold');
        $this->at('2025-08-20 12:00:00');
        $this->setStatus($this->ids['I1'], 'in_stock');
        $this->at('2025-09-01 12:00:00');
        $this->setStatus($this->ids['M1'], 'melted');
        $this->today();
    }

    private function line(ReportDataset $ds, string $label, string $metal = 'gold', float $purity = 22.0): array
    {
        foreach ($ds->section('register')->rows as $r) {
            if ($r['metal'] === $metal && abs((float) $r['purity'] - $purity) < 0.001 && $r['particular'] === $label) {
                return $r;
            }
        }
        $this->fail("No line '{$label}' for {$metal} {$purity}");
    }

    private function counts(ReportDataset $ds, string $metal = 'gold', float $purity = 22.0): array
    {
        $out = [];
        foreach ([R::OPENING, R::PURCHASES, R::MANUFACTURED, R::RETURNED, R::OTHER_RECEIPTS, R::SOLD, R::MELTED, R::OTHER_OUT, R::CLOSING_COMPUTED, R::CLOSING_ACTUAL, R::DIFFERENCE] as $l) {
            $out[$l] = $this->line($ds, $l, $metal, $purity)['count'];
        }

        return $out;
    }

    public function test_it_is_registered_and_money_free(): void
    {
        $def = app(ReportRegistry::class)->definition(R::KEY);
        $this->assertSame('stock-register-weight', R::KEY);
        $this->assertSame('reports.view', $def->permissions->effectiveViewGate());
        foreach ($def->columns as $c) {
            $this->assertNotSame('money', $c->type->value, "{$c->key} would show rupees on a quantity report");
        }
    }

    public function test_the_register_reconciles_opening_plus_received_minus_outflows_to_closing(): void
    {
        $this->seedYear();
        $ds = $this->runReport(R::KEY, $this->shop->id);
        $c = $this->counts($ds);

        $this->assertSame([
            R::OPENING => 2, R::PURCHASES => 3, R::MANUFACTURED => 1, R::RETURNED => 1, R::OTHER_RECEIPTS => 4,
            R::SOLD => 3, R::MELTED => 1, R::OTHER_OUT => 1, R::CLOSING_COMPUTED => 6, R::CLOSING_ACTUAL => 6, R::DIFFERENCE => 0,
        ], $c);

        $gross = $this->line($ds, R::CLOSING_ACTUAL)['gross_weight'];
        $net = $this->line($ds, R::CLOSING_ACTUAL)['net_weight'];
        $this->assertEqualsWithDelta(60.0, $gross, 0.0001);
        $this->assertEqualsWithDelta(57.0, $net, 0.0001);
        $this->assertEqualsWithDelta(0.0, $this->line($ds, R::DIFFERENCE)['net_weight'], 0.0001);
        $this->assertEqualsWithDelta(28.5, $this->line($ds, R::SOLD)['net_weight'], 0.0001);
    }

    public function test_item_bought_before_the_year_and_sold_inside_it_is_opening_and_sold(): void
    {
        $this->seedYear();
        $ds = $this->runReport(R::KEY, $this->shop->id);
        $this->assertSame(2, $this->line($ds, R::OPENING)['count']); // O1 + O2
        $this->assertSame(3, $this->line($ds, R::SOLD)['count']);    // includes O2
    }

    public function test_the_last_minute_of_31_march_is_this_year_and_midnight_is_the_next(): void
    {
        $this->seedYear();
        $this->assertSame(4, $this->line($this->runReport(R::KEY, $this->shop->id), R::OTHER_RECEIPTS)['count']); // I4 in, I5 not
        $next = $this->runReport(R::KEY, $this->shop->id, '2026-27');
        $this->assertSame(6, $this->line($next, R::OPENING)['count'], 'opening of 2026-27 is the closing of 2025-26');
        $this->assertSame(1, $this->line($next, R::OTHER_RECEIPTS)['count']);  // I5
        $this->assertSame(0, $this->line($next, R::DIFFERENCE)['count']);
        $this->assertSame(7, $this->line($next, R::CLOSING_ACTUAL)['count']);
    }

    public function test_a_returned_piece_counts_as_sold_then_returned_to_stock(): void
    {
        $this->seedYear();
        $ds = $this->runReport(R::KEY, $this->shop->id);
        $this->assertSame(1, $this->line($ds, R::RETURNED)['count']);
        $this->assertSame(1, $this->line($ds, R::MELTED)['count']);
    }

    public function test_a_back_dated_movement_flows_through_closing_and_difference_stays_zero(): void
    {
        $this->seedYear();
        // A sale of I2 recorded late but dated 10 Jan 2026.
        $this->rawMovement($this->shop->id, $this->ids['I2'], 'sold', 'in_stock', 'sold', '2026-01-10 10:00:00');

        $ds = $this->runReport(R::KEY, $this->shop->id);
        $this->assertSame(4, $this->line($ds, R::SOLD)['count']);
        $this->assertSame(5, $this->line($ds, R::CLOSING_ACTUAL)['count']);
        $this->assertSame(5, $this->line($ds, R::CLOSING_COMPUTED)['count']);
        $this->assertSame(0, $this->line($ds, R::DIFFERENCE)['count']);
    }

    public function test_a_weight_that_changed_without_a_status_move_shows_as_shortage(): void
    {
        $this->seedYear();
        // O1 is recorded as 0.5 g lighter in a neutral (stock to stock) movement.
        $this->rawMovement($this->shop->id, $this->ids['O1'], 'status_change', 'in_stock', 'with_karigar', '2025-12-01 10:00:00', ['net_metal_weight' => 9.0, 'gross_weight' => 9.5]);

        $ds = $this->runReport(R::KEY, $this->shop->id);
        $diff = $this->line($ds, R::DIFFERENCE);
        $this->assertSame(0, $diff['count']);
        $this->assertEqualsWithDelta(-0.5, $diff['net_weight'], 0.0001);
        $this->assertEqualsWithDelta(-0.5, $diff['gross_weight'], 0.0001);
    }

    public function test_metals_and_purities_are_separate_groups(): void
    {
        $this->seedYear();
        $this->at('2025-06-02 10:00:00');
        $this->createItem($this->shop->id, null, ['barcode' => 'SV', 'metal_type' => 'silver', 'purity' => 92.5, 'gross_weight' => 100, 'net_metal_weight' => 99]);
        $this->createItem($this->shop->id, null, ['barcode' => 'G18', 'metal_type' => 'gold', 'purity' => 18, 'gross_weight' => 4, 'net_metal_weight' => 3.5]);
        $this->today();

        $ds = $this->runReport(R::KEY, $this->shop->id);
        $this->assertSame(1, $this->line($ds, R::CLOSING_ACTUAL, 'silver', 92.5)['count']);
        $this->assertEqualsWithDelta(99.0, $this->line($ds, R::CLOSING_ACTUAL, 'silver', 92.5)['net_weight'], 0.0001);
        $this->assertSame(1, $this->line($ds, R::CLOSING_ACTUAL, 'gold', 18.0)['count']);
        $this->assertSame(6, $this->line($ds, R::CLOSING_ACTUAL, 'gold', 22.0)['count']);
    }

    public function test_two_shops_are_isolated(): void
    {
        $this->seedYear();
        [, $other] = $this->createRetailerTenant();
        $this->at('2025-06-01 10:00:00');
        $this->goldItem($other->id, 'OTHER', 500);
        $this->today();

        $ds = $this->runReport(R::KEY, $other->id);
        $this->assertSame(1, $this->line($ds, R::CLOSING_ACTUAL)['count']);
        $this->assertSame(0, $this->line($ds, R::OPENING)['count']);
        $this->assertSame(6, $this->line($this->runReport(R::KEY, $this->shop->id), R::CLOSING_ACTUAL)['count']);
    }

    public function test_a_shop_with_no_stock_history_gets_an_empty_register_not_an_error(): void
    {
        $ds = $this->runReport(R::KEY, $this->shop->id);
        $this->assertSame([], $ds->section('register')->rows);
    }
}
