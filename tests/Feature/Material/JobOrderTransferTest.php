<?php

namespace Tests\Feature\Material;

use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Services\BullionVaultService;
use App\Services\JobOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Stage 5 — karigar transfer (XFER-1). A transfer conserves total metal; only
 * per-karigar attribution changes. Two cases:
 *   - held-balance transfer: real lot→lot karigar_transfer movement
 *   - open-job reassign: null/null karigar_transfer movement + karigar_id move
 */
class JobOrderTransferTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private int $shopId;
    private int $karigarA;
    private int $karigarB;
    private int $userId;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        [$user, $shop] = $this->createManufacturerTenant();
        $this->shopId = $shop->id;
        $this->userId = $user->id;
        $this->karigarA = $this->karigar('A');
        $this->karigarB = $this->karigar('B');
    }

    private function karigar(string $name): int
    {
        return (int) DB::table('karigars')->insertGetId([
            'shop_id' => $this->shopId, 'name' => $name, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function holdingLot(int $karigarId, float $fine): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill([
            'shop_id' => $this->shopId, 'karigar_id' => $karigarId, 'source' => MetalLot::SOURCE_KARIGAR_HELD,
            'metal_type' => 'gold', 'purity' => 24.00, 'fine_weight_total' => $fine, 'fine_weight_remaining' => $fine, 'cost_per_fine_gram' => 0,
        ]);
        $lot->save();
        return $lot;
    }

    private function held(int $karigarId): float
    {
        return (float) MetalLot::withoutGlobalScopes()
            ->where('shop_id', $this->shopId)->where('karigar_id', $karigarId)
            ->where('source', MetalLot::SOURCE_KARIGAR_HELD)->sum('fine_weight_remaining');
    }

    private function withKarigar(int $karigarId): float
    {
        return TenantContext::runFor($this->shopId, fn () => app(BullionVaultService::class)->withKarigarFine($this->shopId, $karigarId));
    }

    public function test_held_balance_transfer_moves_metal_a_to_b_and_conserves_total(): void
    {
        $this->holdingLot($this->karigarA, 2.0);
        $totalBefore = $this->withKarigar($this->karigarA) + $this->withKarigar($this->karigarB);

        $movement = TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)
            ->transferHeldBalance($this->shopId, $this->karigarA, $this->karigarB, 'gold', 24, 1.0, $this->userId));

        $this->assertEqualsWithDelta(1.0, $this->held($this->karigarA), 0.0001, 'A drawn down');
        $this->assertEqualsWithDelta(1.0, $this->held($this->karigarB), 0.0001, 'B credited');
        $this->assertSame('karigar_transfer', $movement->type);
        $this->assertNotNull($movement->from_lot_id);
        $this->assertNotNull($movement->to_lot_id);

        // XFER-1: total with-karigar conserved.
        $totalAfter = $this->withKarigar($this->karigarA) + $this->withKarigar($this->karigarB);
        $this->assertEqualsWithDelta($totalBefore, $totalAfter, 0.0001, 'total metal conserved');
    }

    public function test_cannot_transfer_more_than_held(): void
    {
        $this->holdingLot($this->karigarA, 2.0);
        $this->expectException(LogicException::class);
        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)
            ->transferHeldBalance($this->shopId, $this->karigarA, $this->karigarB, 'gold', 24, 5.0, $this->userId));
    }

    public function test_open_job_reassign_moves_attribution_without_touching_the_vault(): void
    {
        // Vault job issued to A → A holds 10g outstanding.
        $vault = new MetalLot();
        $vault->forceFill(['shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold', 'purity' => 24.00, 'fine_weight_total' => 100, 'fine_weight_remaining' => 100, 'cost_per_fine_gram' => 5000]);
        $vault->save();

        $job = TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
            'karigar_id' => $this->karigarA, 'metal_type' => 'gold', 'purity' => 24, 'allowed_wastage_percent' => 0,
            'issuances' => [['metal_lot_id' => $vault->id, 'fine_weight' => 10, 'gross_weight' => 10, 'purity' => 24]],
        ], $this->shopId, $this->userId));

        $vaultBefore = (float) $vault->fresh()->fine_weight_remaining;
        $this->assertEqualsWithDelta(10.0, $this->withKarigar($this->karigarA), 0.0001);

        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->reassignOpenJob($job, $this->karigarB, $this->userId));

        $this->assertSame($this->karigarB, (int) $job->fresh()->karigar_id, 'job moved to B');
        $this->assertEqualsWithDelta(0.0, $this->withKarigar($this->karigarA), 0.0001, 'A no longer holds it');
        $this->assertEqualsWithDelta(10.0, $this->withKarigar($this->karigarB), 0.0001, 'B now holds it');
        $this->assertEqualsWithDelta($vaultBefore, (float) $vault->fresh()->fine_weight_remaining, 0.0001, 'vault untouched');

        // A null/null karigar_transfer movement records the handoff for audit.
        $xfer = MetalMovement::withoutGlobalScopes()->where('reference_type', 'job_order')->where('reference_id', $job->id)
            ->where('type', 'karigar_transfer')->first();
        $this->assertNotNull($xfer);
        $this->assertNull($xfer->from_lot_id);
        $this->assertNull($xfer->to_lot_id);
    }

    public function test_completed_job_cannot_be_reassigned(): void
    {
        $vault = new MetalLot();
        $vault->forceFill(['shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold', 'purity' => 24.00, 'fine_weight_total' => 100, 'fine_weight_remaining' => 100, 'cost_per_fine_gram' => 5000]);
        $vault->save();

        $job = TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
            'karigar_id' => $this->karigarA, 'metal_type' => 'gold', 'purity' => 24, 'allowed_wastage_percent' => 0,
            'issuances' => [['metal_lot_id' => $vault->id, 'fine_weight' => 10, 'gross_weight' => 10, 'purity' => 24]],
        ], $this->shopId, $this->userId));

        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->receive($job, [
            'receipt_date' => now()->toDateString(),
            'items' => [['description' => 'Ring', 'pieces' => 1, 'gross_weight' => 10, 'net_weight' => 10, 'purity' => 24]],
        ], $this->userId));

        $this->assertSame(JobOrder::STATUS_COMPLETED, $job->fresh()->status);
        $this->expectException(LogicException::class);
        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->reassignOpenJob($job->fresh(), $this->karigarB, $this->userId));
    }
}
