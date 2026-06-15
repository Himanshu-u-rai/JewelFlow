<?php

namespace Tests\Feature\Material;

use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Reporting\KarigarService;
use App\Reporting\ReportPeriod;
use App\Services\JobOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Stage 4 — receipt with an operator-declared retained split. Proves retained
 * metal becomes the karigar's holding lot (movement-backed, reconciles), is
 * consumable later, closes the 4-sink identity (wastage excludes retained), and
 * is NOT reported as shrinkage loss.
 */
class JobOrderRetainedMetalTest extends TestCase
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

    /** 24k so net == fine (clean gram math). */
    private function vaultLot(float $fine = 100.0): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill([
            'shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold',
            'purity' => 24.00, 'fine_weight_total' => $fine, 'fine_weight_remaining' => $fine,
            'cost_per_fine_gram' => 5000.00,
        ]);
        $lot->save();
        return $lot;
    }

    private function issueVault(MetalLot $lot, float $fine): JobOrder
    {
        return TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
            'karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => 24, 'allowed_wastage_percent' => 5,
            'issuances' => [['metal_lot_id' => $lot->id, 'fine_weight' => $fine, 'gross_weight' => $fine, 'purity' => 24]],
        ], $this->shopId, $this->userId));
    }

    private function receive(JobOrder $job, float $itemFine, float $retained): void
    {
        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->receive($job, [
            'receipt_date' => now()->toDateString(),
            'items' => [['description' => 'Ring', 'pieces' => 1, 'gross_weight' => $itemFine, 'net_weight' => $itemFine, 'purity' => 24]],
            'retained_fine_weight' => $retained,
        ], $this->userId));
    }

    private function holdingLot(): ?MetalLot
    {
        return MetalLot::withoutGlobalScopes()
            ->where('shop_id', $this->shopId)->where('karigar_id', $this->karigarId)
            ->where('source', MetalLot::SOURCE_KARIGAR_HELD)->first();
    }

    private function movementsFor(int $jobId): \Illuminate\Support\Collection
    {
        return MetalMovement::withoutGlobalScopes()->where('reference_type', 'job_order')->where('reference_id', $jobId)->get();
    }

    public function test_retained_metal_credits_holding_lot_and_closes_the_4_sink_identity(): void
    {
        $vault = $this->vaultLot(100);
        $job = $this->issueVault($vault, 10);

        $this->receive($job, itemFine: 8, retained: 2); // 8 items + 2 retained = 10 issued, 0 wastage

        $job = $job->fresh();
        $this->assertSame(JobOrder::STATUS_COMPLETED, $job->status);
        $this->assertEqualsWithDelta(8.0, (float) $job->returned_fine_weight, 0.0001);
        $this->assertEqualsWithDelta(2.0, (float) $job->retained_returned_fine_weight, 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $job->actual_wastage_fine, 0.0001, 'retained is not wastage');

        // Holding lot created + credited, backed by a karigar_retained movement.
        $holding = $this->holdingLot();
        $this->assertNotNull($holding);
        $this->assertEqualsWithDelta(2.0, (float) $holding->fine_weight_remaining, 0.0001);
        $this->assertSame(1, $this->movementsFor($job->id)->where('type', 'karigar_retained')->count());

        // The holding lot reconciles via its ledger (stored == Σ to_lot − Σ from_lot).
        $ledger = (float) MetalMovement::withoutGlobalScopes()->where('to_lot_id', $holding->id)->sum('fine_weight')
            - (float) MetalMovement::withoutGlobalScopes()->where('from_lot_id', $holding->id)->sum('fine_weight');
        $this->assertEqualsWithDelta((float) $holding->fine_weight_remaining, $ledger, 0.0001, 'holding lot reconciles');
    }

    public function test_retained_metal_is_consumable_by_a_later_karigar_balance_job(): void
    {
        $vault = $this->vaultLot(100);
        $job = $this->issueVault($vault, 10);
        $this->receive($job, itemFine: 8, retained: 2);

        // A later repair draws 1g from the karigar's held balance — vault untouched.
        $vaultBefore = (float) $vault->fresh()->fine_weight_remaining;
        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
            'karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => 24, 'job_type' => 'repair', 'allowed_wastage_percent' => 0,
            'sources' => [['source_type' => 'karigar_held', 'fine_weight' => 1, 'gross_weight' => 1, 'purity' => 24]],
        ], $this->shopId, $this->userId));

        $this->assertEqualsWithDelta(1.0, (float) $this->holdingLot()->fine_weight_remaining, 0.0001, 'held drawn 2 → 1');
        $this->assertEqualsWithDelta($vaultBefore, (float) $vault->fresh()->fine_weight_remaining, 0.0001, 'vault untouched');
    }

    public function test_retained_plus_returned_cannot_exceed_issued(): void
    {
        $vault = $this->vaultLot(100);
        $job = $this->issueVault($vault, 10);

        $this->expectException(LogicException::class);
        $this->receive($job, itemFine: 8, retained: 5); // 8 + 5 = 13 > 10 issued
    }

    public function test_retained_metal_is_not_reported_as_shrinkage(): void
    {
        $vault = $this->vaultLot(100);
        $job = $this->issueVault($vault, 10);
        $this->receive($job, itemFine: 8, retained: 2);

        $shr = TenantContext::runFor($this->shopId, fn () => app(KarigarService::class)->shrinkage(
            $this->shopId,
            ReportPeriod::month((int) now()->year, (int) now()->month),
        ));

        $this->assertEqualsWithDelta(2.0, $shr->totalRetained, 0.0001, 'retained surfaced separately');
        $this->assertEqualsWithDelta(0.0, $shr->totalUnaccounted, 0.0001, 'retained is NOT unaccounted loss');
        $this->assertEqualsWithDelta(0.0, $shr->totalWastage, 0.0001);
    }
}
