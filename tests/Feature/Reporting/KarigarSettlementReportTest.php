<?php

namespace Tests\Feature\Reporting;

use App\Reporting\KarigarService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Karigar settlement report (Phase 2 M4, #13). Locks per-karigar grams
 * (issued/received/wastage → outstanding from open job orders) and money
 * (invoiced − paid → outstanding payable).
 */
class KarigarSettlementReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function karigar(int $shopId, string $name): int
    {
        return (int) DB::table('karigars')->insertGetId([
            'shop_id' => $shopId, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function jobOrder(int $shopId, int $karigarId, string $status, float $issued, float $returned, float $wastage, float $leftover = 0): void
    {
        DB::table('job_orders')->insert([
            'shop_id' => $shopId, 'karigar_id' => $karigarId,
            'job_order_number' => 'JO-' . fake()->unique()->numerify('######'),
            'metal_type' => 'gold', 'purity' => 22, 'issue_date' => now()->subDays(10)->toDateString(),
            'status' => $status, 'issued_fine_weight' => $issued, 'returned_fine_weight' => $returned,
            'actual_wastage_fine' => $wastage, 'leftover_returned_fine_weight' => $leftover,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function karigarInvoice(int $shopId, int $karigarId, float $total, float $paid): void
    {
        DB::table('karigar_invoices')->insert([
            'shop_id' => $shopId, 'karigar_id' => $karigarId,
            'karigar_invoice_number' => 'KI-' . fake()->unique()->numerify('######'),
            'karigar_invoice_date' => now()->toDateString(),
            'total_after_tax' => $total, 'amount_paid' => $paid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_settlement_grams_and_money(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $k1 = $this->karigar($shop->id, 'Ramesh');
        $k2 = $this->karigar($shop->id, 'Suresh');

        // Ramesh: open job — issued 50, returned 45, wastage 2, leftover 1 → outstanding 2.
        $this->jobOrder($shop->id, $k1, 'issued', 50, 45, 2, 1);
        // A completed job is excluded from the open-grams balance.
        $this->jobOrder($shop->id, $k1, 'completed', 100, 98, 2, 0);
        // Ramesh money: invoiced 5000, paid 3000 → payable 2000.
        $this->karigarInvoice($shop->id, $k1, 5000, 3000);

        // Suresh: open job issued 30, returned 30 → outstanding 0; fully-paid invoice.
        $this->jobOrder($shop->id, $k2, 'issued', 30, 30, 0, 0);
        $this->karigarInvoice($shop->id, $k2, 4000, 4000);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(KarigarService::class)->settlement($shop->id)
        );

        $this->assertSame(2, $data->karigarCount);
        $ramesh = $data->rows->firstWhere('karigar_name', 'Ramesh');
        $this->assertEqualsWithDelta(50.0, $ramesh->issued_fine, 0.001, 'open job only');
        $this->assertEqualsWithDelta(2.0, $ramesh->outstanding_fine, 0.001, '50-45-1-2');
        $this->assertEqualsWithDelta(2000.0, $ramesh->outstanding_payable, 0.01);

        $this->assertEqualsWithDelta(2.0, $data->totalOutstandingFine, 0.001, 'Ramesh 2 + Suresh 0');
        $this->assertEqualsWithDelta(9000.0, $data->totalInvoiced, 0.01);
        $this->assertEqualsWithDelta(7000.0, $data->totalPaid, 0.01);
        $this->assertEqualsWithDelta(2000.0, $data->totalOutstandingPayable, 0.01);
        $this->assertEqualsWithDelta($data->totalOutstandingPayable, (float) $data->rows->sum('outstanding_payable'), 0.01);
    }
}
