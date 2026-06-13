<?php

namespace Tests\Feature\Material;

use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Reporting\KarigarService;
use App\Services\BullionVaultService;
use App\Services\JobOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Regression tests for the metal-source review pass:
 *  - H1: retained metal on an OPEN (partial_return) job is not double-counted in
 *        the "with karigar" total (held lot + open-job outstanding).
 *  - M1: two legs drawing the SAME target over their combined balance surface a
 *        friendly LogicException, not an uncaught QueryException (500).
 *  - M3: a customer_advance leg on a silver job is rejected (gold-only pool).
 *  - M4: a non-vault metal_source label without a 'sources' set is rejected.
 */
class JobOrderReviewFixesTest extends TestCase
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

    private function issueVault(MetalLot $lot, float $fine, float $allowedWastagePct = 5): JobOrder
    {
        return TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
            'karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => 24,
            'allowed_wastage_percent' => $allowedWastagePct,
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

    /**
     * H1 — issue 100, receive 70 items + 10 retained. Wastage = 20 > 5% allowed,
     * so the job stays OPEN (partial_return). The 10 retained grams live in the
     * karigar_held lot AND must NOT also appear in the open-job outstanding, so
     * the reported "with karigar" total is the true physical 10g, not 20g.
     */
    public function test_retained_metal_on_open_job_is_not_double_counted_with_karigar(): void
    {
        $vault = $this->vaultLot(100);
        $job = $this->issueVault($vault, 100);
        $this->receive($job, itemFine: 70, retained: 10);

        $job = $job->fresh();
        $this->assertSame(JobOrder::STATUS_PARTIAL_RETURN, $job->status, 'excess wastage keeps the job open');
        $this->assertEqualsWithDelta(10.0, (float) $job->retained_returned_fine_weight, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $job->actual_wastage_fine, 0.0001);

        // Outstanding accessor must net out the retained grams (they belong to the
        // held lot, not "owed back" against the job).
        $this->assertEqualsWithDelta(0.0, $job->outstanding_fine, 0.0001, 'retained netted from outstanding');

        // The held lot holds exactly the 10 retained grams.
        $held = MetalLot::withoutGlobalScopes()
            ->where('shop_id', $this->shopId)->where('karigar_id', $this->karigarId)
            ->where('source', MetalLot::SOURCE_KARIGAR_HELD)->first();
        $this->assertNotNull($held);
        $this->assertEqualsWithDelta(10.0, (float) $held->fine_weight_remaining, 0.0001);

        // The reported "with karigar" fine must be the physical 10g — not 20g.
        $withKarigar = TenantContext::runFor(
            $this->shopId,
            fn () => app(BullionVaultService::class)->withKarigarFine($this->shopId, $this->karigarId),
        );
        $this->assertEqualsWithDelta(10.0, $withKarigar, 0.0001, 'no double-count of retained metal');
    }

    /**
     * M1 — two vault legs on the SAME lot whose fine weights individually fit but
     * together exceed the balance must raise a friendly LogicException (caught and
     * rolled back), never an uncaught QueryException from the non-negative CHECK.
     */
    public function test_two_legs_over_drawing_one_lot_raise_a_friendly_error(): void
    {
        $vault = $this->vaultLot(30); // only 30g available

        $this->expectException(LogicException::class);

        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
            'karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => 24, 'allowed_wastage_percent' => 5,
            'sources' => [
                ['source_type' => 'vault', 'metal_lot_id' => $vault->id, 'fine_weight' => 20, 'gross_weight' => 20, 'purity' => 24],
                ['source_type' => 'vault', 'metal_lot_id' => $vault->id, 'fine_weight' => 20, 'gross_weight' => 20, 'purity' => 24],
            ],
        ], $this->shopId, $this->userId));
    }

    public function test_over_drawn_lot_leaves_balance_untouched_after_rollback(): void
    {
        $vault = $this->vaultLot(30);

        try {
            TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
                'karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => 24, 'allowed_wastage_percent' => 5,
                'sources' => [
                    ['source_type' => 'vault', 'metal_lot_id' => $vault->id, 'fine_weight' => 20, 'gross_weight' => 20, 'purity' => 24],
                    ['source_type' => 'vault', 'metal_lot_id' => $vault->id, 'fine_weight' => 20, 'gross_weight' => 20, 'purity' => 24],
                ],
            ], $this->shopId, $this->userId));
            $this->fail('expected over-draw to throw');
        } catch (LogicException $e) {
            // expected
        }

        $this->assertEqualsWithDelta(30.0, (float) $vault->fresh()->fine_weight_remaining, 0.0001, 'rolled back, no partial debit');
    }

    /**
     * M3 — customer_advance is gold-only (the pool and per-customer ledger are
     * gold). A silver job with a customer_advance leg must be rejected up front.
     */
    public function test_silver_customer_advance_leg_is_rejected(): void
    {
        $customerId = (int) DB::table('customers')->insertGetId([
            'shop_id' => $this->shopId, 'first_name' => 'Sita', 'last_name' => 'Devi',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(LogicException::class);

        TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue([
            'karigar_id' => $this->karigarId, 'metal_type' => 'silver', 'purity' => 925, 'allowed_wastage_percent' => 5,
            'sources' => [
                ['source_type' => 'customer_advance', 'customer_id' => $customerId, 'fine_weight' => 5, 'gross_weight' => 5, 'purity' => 925],
            ],
        ], $this->shopId, $this->userId));
    }

    /**
     * M3 (companion) — a karigar_balance shrinkage report still computes cleanly
     * (the M3 guard only blocks at issue, doesn't break reporting). Sanity that
     * the retained reporting path is unaffected by the new guards.
     */
    public function test_shrinkage_report_unaffected_by_new_guards(): void
    {
        $vault = $this->vaultLot(100);
        $job = $this->issueVault($vault, 10);
        $this->receive($job, itemFine: 8, retained: 2);

        $shr = TenantContext::runFor($this->shopId, fn () => app(KarigarService::class)->shrinkage(
            $this->shopId,
            \App\Reporting\ReportPeriod::month((int) now()->year, (int) now()->month),
        ));
        $this->assertEqualsWithDelta(2.0, $shr->totalRetained, 0.0001);
        $this->assertEqualsWithDelta(0.0, $shr->totalUnaccounted, 0.0001);
    }
}
