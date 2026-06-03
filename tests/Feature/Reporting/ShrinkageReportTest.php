<?php

namespace Tests\Feature\Reporting;

use App\Reporting\KarigarService;
use App\Reporting\ReportPeriod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Metal shrinkage / loss-variance (#15). Over COMPLETED jobs in the period,
 * reconciles issued grams vs items-back + leftover + declared wastage, and
 * surfaces the residual "unaccounted" gram variance. Reads job_orders only.
 */
class ShrinkageReportTest extends TestCase
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
            'shop_id' => $shopId, 'name' => $name,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function job(int $shopId, int $karigarId, array $attrs): void
    {
        DB::table('job_orders')->insert(array_merge([
            'shop_id' => $shopId, 'karigar_id' => $karigarId,
            'job_order_number' => 'JO-' . uniqid(),
            'metal_type' => 'gold', 'purity' => 22,
            'issue_date' => '2026-03-01',
            'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    public function test_unaccounted_variance_is_residual_after_items_leftover_wastage(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $k = $this->karigar($shop->id, 'Ramesh');

        // In-period, completed. unaccounted = 100 - 90 - 5 - 4 = 1
        $this->job($shop->id, $k, [
            'completed_at' => '2026-03-10',
            'issued_fine_weight' => 100, 'returned_fine_weight' => 90,
            'leftover_returned_fine_weight' => 5, 'actual_wastage_fine' => 4,
        ]);
        // In-period, completed, clean. unaccounted = 50 - 45 - 2 - 3 = 0
        $this->job($shop->id, $k, [
            'completed_at' => '2026-03-20',
            'issued_fine_weight' => 50, 'returned_fine_weight' => 45,
            'leftover_returned_fine_weight' => 2, 'actual_wastage_fine' => 3,
        ]);
        // Out-of-period — excluded.
        $this->job($shop->id, $k, [
            'completed_at' => '2026-01-05',
            'issued_fine_weight' => 999, 'returned_fine_weight' => 0,
            'leftover_returned_fine_weight' => 0, 'actual_wastage_fine' => 0,
        ]);
        // Still open (not completed) — excluded.
        $this->job($shop->id, $k, [
            'status' => 'issued', 'completed_at' => null,
            'issued_fine_weight' => 777,
        ]);

        $data = TenantContext::runFor($shop->id, fn () =>
            app(KarigarService::class)->shrinkage($shop->id, ReportPeriod::month(2026, 3))
        );

        $this->assertSame(2, $data->jobCount, 'only in-period completed jobs');
        $this->assertEqualsWithDelta(150.0, $data->totalIssued, 0.001);
        $this->assertEqualsWithDelta(135.0, $data->totalReturned, 0.001);
        $this->assertEqualsWithDelta(7.0, $data->totalWastage, 0.001);
        $this->assertEqualsWithDelta(1.0, $data->totalUnaccounted, 0.001, 'residual unaccounted grams');

        // SHR-1 identity: unaccounted == issued − items − leftover − wastage.
        $recomputed = $data->totalIssued - $data->totalReturned - $data->totalLeftover - $data->totalWastage;
        $this->assertEqualsWithDelta($recomputed, $data->totalUnaccounted, 0.001);

        // Wastage % of issued = 7 / 150.
        $this->assertEqualsWithDelta(round(7 / 150 * 100, 2), $data->wastagePct, 0.01);
    }

    public function test_empty_when_no_completed_jobs(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $data = TenantContext::runFor($shop->id, fn () =>
            app(KarigarService::class)->shrinkage($shop->id, ReportPeriod::month(2026, 3))
        );
        $this->assertSame(0, $data->jobCount);
        $this->assertTrue($data->rows->isEmpty());
        $this->assertSame(0.0, $data->wastagePct);
    }
}
