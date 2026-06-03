<?php

namespace Tests\Feature\Reporting;

use App\Reporting\LedgerService;
use App\Reporting\RepairService;
use App\Reporting\SalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M5: the four legacy reports (cash / daily metal / metal-exchange / repairs)
 * were moved off their controllers onto Reporting services with no behaviour
 * change. These tests pin the extracted aggregations to hand-computed numbers
 * so any future drift is caught. ReportScreensRenderTest separately proves the
 * thin controllers still render.
 */
class LegacyExtractionParityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_cash_day_groups_by_type_and_mode(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->cash($shop->id, 'in', 1000, 'cash', '2026-03-05 10:00:00');
        $this->cash($shop->id, 'in', 500, 'upi', '2026-03-05 11:00:00');
        $this->cash($shop->id, 'out', 300, 'cash', '2026-03-05 12:00:00');
        $this->cash($shop->id, 'in', 999, 'cash', '2026-03-06 10:00:00'); // other day — excluded

        $data = TenantContext::runFor($shop->id, fn () =>
            app(LedgerService::class)->cashDay($shop->id, '2026-03-05')
        );

        $byType = $data->rows->pluck('total', 'type');
        $this->assertEqualsWithDelta(1500, (float) $byType['in'], 0.001);
        $this->assertEqualsWithDelta(300, (float) $byType['out'], 0.001);
        $this->assertEqualsWithDelta(1300, (float) $data->modeBreakdown['cash'], 0.001); // 1000 in + 300 out
        $this->assertEqualsWithDelta(500, (float) $data->modeBreakdown['upi'], 0.001);
    }

    public function test_metal_movement_day_groups_by_type(): void
    {
        [, $shop] = $this->createRetailerTenant();

        $this->movement($shop->id, 'purchase', 50.5, '2026-03-05 10:00:00');
        $this->movement($shop->id, 'purchase', 10.0, '2026-03-05 11:00:00');
        $this->movement($shop->id, 'job_issue', 20.0, '2026-03-05 12:00:00');
        $this->movement($shop->id, 'purchase', 99.0, '2026-03-06 10:00:00'); // other day — excluded

        $rows = TenantContext::runFor($shop->id, fn () =>
            app(LedgerService::class)->metalMovementDay($shop->id, '2026-03-05')
        );

        $byType = $rows->pluck('total', 'type');
        $this->assertEqualsWithDelta(60.5, (float) $byType['purchase'], 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $byType['job_issue'], 0.001);
    }

    public function test_metal_exchange_summarises_by_metal(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $invId = (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shop->id, 'customer_id' => $customer->id,
            'invoice_number' => 'INV-MEX-1', 'status' => 'draft', 'gold_rate' => 7200,
            'subtotal' => 0, 'gst' => 0, 'discount' => 0, 'total' => 0,
            'created_at' => '2026-03-10 10:00:00', 'updated_at' => '2026-03-10 10:00:00',
        ]);

        $this->payment($shop->id, $invId, 'old_gold', 10.0, 9.16, 60000, '2026-03-10 10:00:00');
        $this->payment($shop->id, $invId, 'old_gold', 5.0, 4.58, 30000, '2026-03-10 11:00:00');
        $this->payment($shop->id, $invId, 'old_silver', 100.0, 92.0, 7000, '2026-03-10 12:00:00');

        $data = TenantContext::runFor($shop->id, fn () =>
            app(SalesService::class)->metalExchange($shop->id, '2026-03-01', '2026-03-31')
        );

        $this->assertEqualsWithDelta(15.0, (float) $data->goldSummary['gross'], 0.001);
        $this->assertEqualsWithDelta(13.74, (float) $data->goldSummary['fine'], 0.001);
        $this->assertEqualsWithDelta(90000, (float) $data->goldSummary['value'], 0.001);
        $this->assertSame(2, $data->goldSummary['count']);
        $this->assertEqualsWithDelta(100.0, (float) $data->silverSummary['gross'], 0.001);
        $this->assertSame(1, $data->silverSummary['count']);
    }

    public function test_repair_summary_totals(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $this->repair($shop->id, $customer->id, 'delivered', 8.0, 7.5, 1200, '2026-03-05 10:00:00');
        $this->repair($shop->id, $customer->id, 'in_progress', 4.0, 0, 800, '2026-03-06 10:00:00');

        $data = TenantContext::runFor($shop->id, fn () =>
            app(RepairService::class)->summary($shop->id, null, null, null)
        );

        $this->assertEqualsWithDelta(12.0, (float) $data->totals->total_issued, 0.001);
        $this->assertEqualsWithDelta(7.5, (float) $data->totals->total_returned, 0.001);
        // Only delivered repairs contribute cash.
        $this->assertEqualsWithDelta(1200, (float) $data->totals->total_cash, 0.001);
        $this->assertSame(2, $data->repairs->total());
    }

    // --- seed helpers ---

    private function cash(int $shopId, string $type, float $amount, string $mode, string $at): void
    {
        DB::table('cash_transactions')->insert([
            'shop_id' => $shopId, 'type' => $type, 'amount' => $amount,
            'payment_mode' => $mode, 'source_type' => 'test',
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function movement(int $shopId, string $type, float $fine, string $at): void
    {
        DB::table('metal_movements')->insert([
            'shop_id' => $shopId, 'type' => $type, 'fine_weight' => $fine,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function payment(int $shopId, int $invoiceId, string $mode, float $gross, float $fine, float $amount, string $at): void
    {
        DB::table('invoice_payments')->insert([
            'shop_id' => $shopId, 'invoice_id' => $invoiceId, 'mode' => $mode,
            'metal_gross_weight' => $gross, 'metal_fine_weight' => $fine, 'amount' => $amount,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    private function repair(int $shopId, int $customerId, string $status, float $issued, float $returned, float $cost, string $at): void
    {
        DB::table('repairs')->insert([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'repair_number' => random_int(100000, 999999), 'gross_weight' => $issued,
            'item_description' => 'test item', 'status' => $status,
            'gold_issued_fine' => $issued, 'gold_returned_fine' => $returned, 'final_cost' => $cost,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }
}
