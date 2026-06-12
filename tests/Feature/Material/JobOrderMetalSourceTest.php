<?php

namespace Tests\Feature\Material;

use App\Models\CustomerGoldTransaction;
use App\Models\JobOrder;
use App\Models\JobOrderSource;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Services\JobOrderService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Stage 3 — issuance with a metal-source SET. Proves each source mode moves
 * metal correctly and the invariants hold:
 *   - legacy vault path unchanged (back-compat)
 *   - vault / karigar_held / customer_advance / none / mixed legs
 *   - CUST-1 (pool == Σ per-customer ledger), CUST-2 (own-balance guard)
 *   - EMPTY-1 (none = zero movements / zero balance effect)
 *   - over-consumption rejected
 */
class JobOrderMetalSourceTest extends TestCase
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
            'shop_id' => $this->shopId, 'name' => 'Ramesh',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function issue(array $data): JobOrder
    {
        return TenantContext::runFor($this->shopId, fn () => app(JobOrderService::class)->issue(
            array_merge(['karigar_id' => $this->karigarId, 'metal_type' => 'gold', 'purity' => 22, 'allowed_wastage_percent' => 0], $data),
            $this->shopId,
            $this->userId,
        ));
    }

    private function vaultLot(float $fine = 100.0): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill([
            'shop_id' => $this->shopId, 'source' => 'purchase', 'metal_type' => 'gold',
            'purity' => 22.00, 'fine_weight_total' => $fine, 'fine_weight_remaining' => $fine,
            'cost_per_fine_gram' => 5000.00,
        ]);
        $lot->save();
        return $lot;
    }

    private function holdingLot(float $fine, float $purity = 22.00): MetalLot
    {
        $lot = new MetalLot();
        $lot->forceFill([
            'shop_id' => $this->shopId, 'karigar_id' => $this->karigarId,
            'source' => MetalLot::SOURCE_KARIGAR_HELD, 'metal_type' => 'gold',
            'purity' => $purity, 'fine_weight_total' => $fine, 'fine_weight_remaining' => $fine,
            'cost_per_fine_gram' => 0,
        ]);
        $lot->save();
        return $lot;
    }

    /** Mimic CustomerGoldController deposit: increment the pooled lot + ledger row. */
    private function depositCustomerGold(int $customerId, float $fine): MetalLot
    {
        $pool = MetalLot::withoutGlobalScopes()->where('shop_id', $this->shopId)->where('source', 'customer_advance')->first();
        if (! $pool) {
            $pool = new MetalLot();
            $pool->forceFill(['shop_id' => $this->shopId, 'source' => 'customer_advance', 'purity' => 24, 'fine_weight_total' => 0, 'fine_weight_remaining' => 0]);
            $pool->save();
        }
        $pool->fine_weight_remaining = (float) $pool->fine_weight_remaining + $fine;
        $pool->fine_weight_total = (float) $pool->fine_weight_total + $fine;
        $pool->save();

        CustomerGoldTransaction::record([
            'shop_id' => $this->shopId, 'customer_id' => $customerId,
            'fine_gold' => $fine, 'type' => 'advance',
        ]);
        return $pool->fresh();
    }

    private function movementsFor(int $jobId): \Illuminate\Support\Collection
    {
        return MetalMovement::withoutGlobalScopes()->where('reference_type', 'job_order')->where('reference_id', $jobId)->get();
    }

    public function test_legacy_vault_issuance_is_unchanged_and_records_a_vault_source_leg(): void
    {
        $lot = $this->vaultLot(100);

        // Legacy input: no 'sources' key, just 'issuances'.
        $job = $this->issue(['issuances' => [['metal_lot_id' => $lot->id, 'fine_weight' => 30, 'gross_weight' => 33, 'purity' => 22]]]);

        $this->assertEqualsWithDelta(30.0, (float) $job->issued_fine_weight, 0.0001);
        $this->assertEqualsWithDelta(70.0, (float) $lot->fresh()->fine_weight_remaining, 0.0001, 'vault lot debited');
        $this->assertSame(1, $this->movementsFor($job->id)->where('type', 'job_issue')->count());
        $this->assertSame(1, $job->issuances->count());
        $this->assertSame(1, $job->sources->where('source_type', 'vault')->count());
        $this->assertSame('vault', $job->metal_source);
    }

    public function test_labor_only_job_moves_no_metal(): void
    {
        $lot = $this->vaultLot(100);
        $movesBefore = MetalMovement::withoutGlobalScopes()->count();

        $job = $this->issue(['job_type' => 'repair', 'sources' => []]); // EMPTY-1

        $this->assertEqualsWithDelta(0.0, (float) $job->issued_fine_weight, 0.0001);
        $this->assertSame(0, $this->movementsFor($job->id)->count(), 'no MetalMovement for labor-only');
        $this->assertSame($movesBefore, MetalMovement::withoutGlobalScopes()->count(), 'global movement count unchanged');
        $this->assertSame(0, $job->issuances->count());
        $this->assertSame(0, $job->sources->count());
        $this->assertEqualsWithDelta(100.0, (float) $lot->fresh()->fine_weight_remaining, 0.0001, 'vault untouched');
        $this->assertSame('none', $job->metal_source);
    }

    public function test_karigar_balance_leg_draws_from_holding_lot_only(): void
    {
        $vault = $this->vaultLot(100);
        $holding = $this->holdingLot(10);

        $job = $this->issue(['sources' => [['source_type' => 'karigar_held', 'fine_weight' => 3, 'gross_weight' => 3, 'purity' => 22]]]);

        $this->assertEqualsWithDelta(7.0, (float) $holding->fresh()->fine_weight_remaining, 0.0001, 'holding lot drawn down');
        $this->assertEqualsWithDelta(100.0, (float) $vault->fresh()->fine_weight_remaining, 0.0001, 'vault untouched');
        $this->assertSame(1, $this->movementsFor($job->id)->where('type', 'job_issue')->count());
        $this->assertSame('karigar_held', $job->metal_source);
        // with-karigar tally still includes the remaining held metal
        $withKarigar = TenantContext::runFor($this->shopId, fn () => app(\App\Services\BullionVaultService::class)->withKarigarFine($this->shopId, $this->karigarId));
        $this->assertGreaterThan(0.0, $withKarigar);
    }

    public function test_customer_supplied_leg_syncs_pool_and_ledger(): void
    {
        $customer = $this->createCustomer($this->shopId);
        $pool = $this->depositCustomerGold($customer->id, 20); // pool=20, ledger{C:20}

        $job = $this->issue(['sources' => [['source_type' => 'customer_advance', 'customer_id' => $customer->id, 'fine_weight' => 8, 'gross_weight' => 8, 'purity' => 22]]]);

        // CUST-1: pool decremented AND per-customer ledger decremented together.
        $this->assertEqualsWithDelta(12.0, (float) $pool->fresh()->fine_weight_remaining, 0.0001, 'pool drawn down');
        $ledger = (float) CustomerGoldTransaction::withoutGlobalScopes()->where('customer_id', $customer->id)->sum('fine_gold');
        $this->assertEqualsWithDelta(12.0, $ledger, 0.0001, 'customer ledger == pool');
        $this->assertSame(1, CustomerGoldTransaction::withoutGlobalScopes()->where('customer_id', $customer->id)->where('type', 'job_consumed')->count());
        // No job_issue MetalMovement for customer metal (movement-less by design).
        $this->assertSame(0, $this->movementsFor($job->id)->count());
        $this->assertSame('customer_advance', $job->metal_source);
    }

    public function test_customer_cannot_spend_another_customers_gold(): void
    {
        $a = $this->createCustomer($this->shopId, ['first_name' => 'A']);
        $b = $this->createCustomer($this->shopId, ['first_name' => 'B']);
        $this->depositCustomerGold($a->id, 30); // pool=30, but it's A's

        // B has zero balance — even though the pool holds 30 (A's), B cannot draw.
        $this->expectException(LogicException::class);
        $this->issue(['sources' => [['source_type' => 'customer_advance', 'customer_id' => $b->id, 'fine_weight' => 5, 'gross_weight' => 5, 'purity' => 22]]]);
    }

    public function test_mixed_vault_plus_customer_sources(): void
    {
        $vault = $this->vaultLot(100);
        $customer = $this->createCustomer($this->shopId);
        $pool = $this->depositCustomerGold($customer->id, 20);

        $job = $this->issue(['sources' => [
            ['source_type' => 'vault', 'metal_lot_id' => $vault->id, 'fine_weight' => 6, 'gross_weight' => 6, 'purity' => 22],
            ['source_type' => 'customer_advance', 'customer_id' => $customer->id, 'fine_weight' => 4, 'gross_weight' => 4, 'purity' => 22],
        ]]);

        $this->assertEqualsWithDelta(10.0, (float) $job->issued_fine_weight, 0.0001);
        $this->assertEqualsWithDelta(94.0, (float) $vault->fresh()->fine_weight_remaining, 0.0001);
        $this->assertEqualsWithDelta(16.0, (float) $pool->fresh()->fine_weight_remaining, 0.0001);
        $this->assertSame(1, $this->movementsFor($job->id)->where('type', 'job_issue')->count(), 'only the vault leg emits a movement');
        $this->assertSame('mixed', $job->metal_source);
    }

    public function test_over_consumption_is_rejected(): void
    {
        $holding = $this->holdingLot(2);
        $this->expectException(LogicException::class);
        // Try to draw 5g from a 2g holding lot.
        $this->issue(['sources' => [['source_type' => 'karigar_held', 'fine_weight' => 5, 'gross_weight' => 5, 'purity' => 22]]]);
    }

    public function test_vault_over_consumption_is_rejected(): void
    {
        $lot = $this->vaultLot(3);
        $this->expectException(LogicException::class);
        $this->issue(['sources' => [['source_type' => 'vault', 'metal_lot_id' => $lot->id, 'fine_weight' => 10, 'gross_weight' => 10, 'purity' => 22]]]);
    }
}
