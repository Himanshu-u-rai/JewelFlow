<?php

namespace Tests\Feature\Material;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * vault:reconcile acknowledgement awareness.
 *
 * A known discrepancy that has been reviewed and accepted (--acknowledge) is
 * reported as "known" and no longer fails the run, while a NEW or CHANGED
 * discrepancy still fails. Read-only on the ledger — only run + acknowledgement
 * audit rows are written.
 */
class VaultDiscrepancyAcknowledgementTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function insertUnbalancedLot(int $shopId, float $stored): int
    {
        // A lot with a stored balance but no movements → ledger 0 → discrepancy.
        return (int) DB::table('metal_lots')->insertGetId([
            'shop_id' => $shopId,
            'metal_type' => 'gold',
            'source' => 'opening',
            'purity' => 22.0,
            'fine_weight_total' => $stored,
            'fine_weight_remaining' => $stored,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_acknowledged_discrepancy_stops_failing_reconcile(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->insertUnbalancedLot($shop->id, 100.0);

        // 1. Unacknowledged discrepancy fails the run.
        $this->assertSame(1, Artisan::call('vault:reconcile', ['--shop' => $shop->id]));

        // 2. Acknowledge it.
        $this->assertSame(0, Artisan::call('vault:reconcile', [
            '--shop' => $shop->id,
            '--acknowledge' => true,
            '--reason' => 'Known demo-data lot, accepted.',
        ]));
        $this->assertDatabaseHas('acknowledged_vault_discrepancies', ['shop_id' => $shop->id]);

        // 3. A later plain run treats it as known and passes.
        $this->assertSame(0, Artisan::call('vault:reconcile', ['--shop' => $shop->id]));
    }

    public function test_acknowledge_requires_reason(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->insertUnbalancedLot($shop->id, 50.0);

        $this->assertSame(1, Artisan::call('vault:reconcile', [
            '--shop' => $shop->id,
            '--acknowledge' => true,
        ]));
        $this->assertDatabaseMissing('acknowledged_vault_discrepancies', ['shop_id' => $shop->id]);
    }

    public function test_a_new_discrepancy_still_fails_after_prior_acknowledgement(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->insertUnbalancedLot($shop->id, 100.0);

        Artisan::call('vault:reconcile', ['--shop' => $shop->id, '--acknowledge' => true, '--reason' => 'first']);
        $this->assertSame(0, Artisan::call('vault:reconcile', ['--shop' => $shop->id]));

        // A DIFFERENT lot (different signature) is not covered by the prior ack.
        $this->insertUnbalancedLot($shop->id, 77.0);
        $this->assertSame(1, Artisan::call('vault:reconcile', ['--shop' => $shop->id]));
    }
}
