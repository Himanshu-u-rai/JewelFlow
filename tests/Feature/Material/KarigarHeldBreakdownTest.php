<?php

namespace Tests\Feature\Material;

use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Services\BullionVaultService;
use App\Services\JobOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * BullionVaultService::karigarHeldBreakdown — the single source of truth for the
 * per-karigar "gold held" figure shown on the karigar profile, the karigar list,
 * and the job form. Proves the reusable / in_jobs / total split is correct and
 * that different purities stay in separate buckets.
 */
class KarigarHeldBreakdownTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private int $shopId;
    private int $karigarId;
    private int $userId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$user, $shop] = $this->createManufacturerTenant();
        $this->shopId = $shop->id;
        $this->userId = $user->id;
        $this->karigarId = (int) DB::table('karigars')->insertGetId([
            'shop_id' => $this->shopId, 'name' => 'Ramesh', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function vaultLot(float $fine, float $purity = 24.00): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill([
            'shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold',
            'purity' => $purity, 'fine_weight_total' => $fine, 'fine_weight_remaining' => $fine,
            'cost_per_fine_gram' => 5000.00,
        ]);
        $lot->save();
        return $lot;
    }

    private function issueVault(MetalLot $lot, float $fine, float $purity = 24): JobOrder
    {
        return TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
            'karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => $purity, 'allowed_wastage_percent' => 5,
            'issuances' => [['metal_lot_id' => $lot->id, 'fine_weight' => $fine, 'gross_weight' => $fine, 'purity' => $purity]],
        ], $this->shopId, $this->userId));
    }

    private function receive(JobOrder $job, float $itemFine, float $retained, float $purity = 24): void
    {
        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->receive($job, [
            'receipt_date' => now()->toDateString(),
            'items' => [['description' => 'Ring', 'pieces' => 1, 'gross_weight' => $itemFine, 'net_weight' => $itemFine, 'purity' => $purity]],
            'retained_fine_weight' => $retained,
        ], $this->userId));
    }

    private function breakdown(): \Illuminate\Support\Collection
    {
        return TenantContext::runFor(
            $this->shopId,
            fn () => app(BullionVaultService::class)->karigarHeldBreakdown($this->shopId, $this->karigarId),
        );
    }

    public function test_fresh_karigar_holds_nothing(): void
    {
        $this->assertTrue($this->breakdown()->isEmpty());
    }

    public function test_reusable_leftover_is_reported_per_purity(): void
    {
        // Two completed 24k jobs: issue 15 → 11 items + 4 retained; issue 22 → 16
        // items + 6 retained. The two leftovers (4g, 6g) pool into one 24k bucket.
        $job1 = $this->issueVault($this->vaultLot(15), 15);
        $this->receive($job1, itemFine: 11, retained: 4);
        $job2 = $this->issueVault($this->vaultLot(22), 22);
        $this->receive($job2, itemFine: 16, retained: 6);

        $rows = $this->breakdown();
        $this->assertCount(1, $rows, 'same purity merges into one bucket');
        $row = $rows->first();
        $this->assertEqualsWithDelta(24.0, $row['purity'], 0.001);
        $this->assertEqualsWithDelta(10.0, $row['reusable'], 0.0001, 'leftovers pooled, lot identity forgotten');
        $this->assertEqualsWithDelta(0.0, $row['in_jobs'], 0.0001, 'both jobs completed');
        $this->assertEqualsWithDelta(10.0, $row['total'], 0.0001);
    }

    public function test_open_job_gold_shows_as_in_jobs_not_reusable(): void
    {
        // Issue 50g, nothing received yet → all 50g is "in unfinished jobs".
        $this->issueVault($this->vaultLot(50), 50);

        $row = $this->breakdown()->first();
        $this->assertEqualsWithDelta(0.0, $row['reusable'], 0.0001);
        $this->assertEqualsWithDelta(50.0, $row['in_jobs'], 0.0001);
        $this->assertEqualsWithDelta(50.0, $row['total'], 0.0001);
    }

    public function test_retained_on_open_job_counted_once_in_reusable(): void
    {
        // Issue 100, receive 70 items + 10 retained, wastage 20 > 5% ⇒ stays open.
        $job = $this->issueVault($this->vaultLot(100), 100);
        $this->receive($job, itemFine: 70, retained: 10);

        $this->assertSame(JobOrder::STATUS_PARTIAL_RETURN, $job->fresh()->status);

        $row = $this->breakdown()->first();
        $this->assertEqualsWithDelta(10.0, $row['reusable'], 0.0001, 'retained sits in the held bucket');
        $this->assertEqualsWithDelta(0.0, $row['in_jobs'], 0.0001, 'retained NOT double-counted as in-jobs');
        $this->assertEqualsWithDelta(10.0, $row['total'], 0.0001);
    }

    public function test_two_purities_stay_in_separate_buckets(): void
    {
        // 24k job: issue 10 → 6 items (net 6 @24k = 6g fine) + 4 retained = 10. ✓
        $j1 = $this->issueVault($this->vaultLot(10, 24.00), 10, 24);
        $this->receive($j1, itemFine: 6, retained: 4, purity: 24);

        // 22k job: issue 10 → item net 4 @22k = 3.667g fine + 3 retained = 6.667 ≤ 10. ✓
        $j2 = $this->issueVault($this->vaultLot(10, 22.00), 10, 22);
        $this->receive($j2, itemFine: 4, retained: 3, purity: 22);

        $rows = $this->breakdown();
        $this->assertCount(2, $rows, 'different purities are separate buckets');
        $this->assertEqualsWithDelta(4.0, $rows->firstWhere('purity', 24.0)['reusable'], 0.0001);
        $this->assertEqualsWithDelta(3.0, $rows->firstWhere('purity', 22.0)['reusable'], 0.0001);
    }
}
