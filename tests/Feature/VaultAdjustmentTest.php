<?php

namespace Tests\Feature;

use App\Models\MetalMovement;
use App\Services\BullionVaultService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M6 (audit A3): there was no sanctioned in-app path to correct a
 * physical-vs-system vault variance (the Recovery runbook assumed one existed).
 * adjustLot() appends a single 'vault_adjustment' MetalMovement (never edits the
 * past), moves the lot's remaining fine weight, requires a reason, and refuses to
 * drive the balance negative.
 */
class VaultAdjustmentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_route_and_gate_exist(): void
    {
        $this->assertTrue(Route::has('vault.lots.adjust'));
        $route = Route::getRoutes()->getByName('vault.lots.adjust');
        $this->assertContains('can:vault.manage', $route->gatherMiddleware());
    }

    public function test_positive_adjustment_increases_remaining_and_records_movement(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id, 100.0);

        $mv = TenantContext::runFor($shop->id, fn () =>
            app(BullionVaultService::class)->adjustLot($lot, 5.0, 'physical count higher', $owner->id)
        );

        $this->assertSame('vault_adjustment', $mv->type);
        $this->assertEquals((int) $lot->id, (int) $mv->to_lot_id);
        $this->assertNull($mv->from_lot_id);
        $this->assertEquals(105.0, (float) $lot->fresh()->fine_weight_remaining);
        $this->assertDatabaseHas('audit_logs', [
            'shop_id' => $shop->id, 'action' => 'vault_adjusted', 'model_id' => $lot->id,
        ]);
    }

    public function test_negative_adjustment_decreases_remaining(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id, 100.0);

        $mv = TenantContext::runFor($shop->id, fn () =>
            app(BullionVaultService::class)->adjustLot($lot, -8.0, 'physical count lower', $owner->id)
        );

        $this->assertEquals((int) $lot->id, (int) $mv->from_lot_id);
        $this->assertNull($mv->to_lot_id);
        $this->assertEquals(92.0, (float) $lot->fresh()->fine_weight_remaining);
    }

    public function test_cannot_drive_balance_negative(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id, 10.0);

        $threw = false;
        try {
            TenantContext::runFor($shop->id, fn () =>
                app(BullionVaultService::class)->adjustLot($lot, -50.0, 'too much', $owner->id)
            );
        } catch (\LogicException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'must refuse to make the lot negative');
        $this->assertEquals(10.0, (float) $lot->fresh()->fine_weight_remaining, 'balance unchanged on rejection');
    }

    public function test_reason_is_required(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id, 10.0);

        $threw = false;
        try {
            TenantContext::runFor($shop->id, fn () =>
                app(BullionVaultService::class)->adjustLot($lot, 1.0, '   ', $owner->id)
            );
        } catch (\LogicException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'a blank reason must be rejected');
    }
}
