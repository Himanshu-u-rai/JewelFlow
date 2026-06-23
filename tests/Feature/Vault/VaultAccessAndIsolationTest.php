<?php

namespace Tests\Feature\Vault;

use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\BullionVaultService;
use App\Services\MetalRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Bullion Vault / Metal Ledger (Module 11). Complements VaultAdjustmentTest
 * (adjust math + negative guard), VaultDiscrepancyAcknowledgementTest and the
 * reconciliation/ledger report suites with the untested surface: the storeLot
 * HTTP create + validation, cross-shop lot view/adjust isolation, the
 * vault.view / vault.manage gates, the retailer-edition gate, and the
 * append-only MetalMovement ledger.
 */
class VaultAccessAndIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function userWithPerms(Shop $shop, array $perms, string $mobile): User
    {
        return TenantContext::runFor($shop->id, function () use ($shop, $perms, $mobile) {
            $role = (new Role())->forceFill(['name' => 'r' . $mobile, 'display_name' => 'R', 'shop_id' => $shop->id]);
            $role->save();
            $role->permissions()->sync(Permission::whereIn('name', $perms)->pluck('id'));

            return User::create([
                'name' => 'U' . $mobile, 'mobile_number' => $mobile, 'password' => Hash::make('password'),
                'realm' => 'erp', 'is_active' => true, 'shop_id' => $shop->id, 'role_id' => $role->id,
            ]);
        });
    }

    // ── storeLot: HTTP create + validation (fine weight via authority) ─────

    public function test_store_lot_creates_shop_scoped_lot_via_authority(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/vault/lots', [
                'metal_type' => 'gold', 'source' => 'purchase',
                'gross_weight' => 10.0, 'purity' => 22.0,
            ]))->assertRedirect();

        $lot = MetalLot::withoutGlobalScopes()->where('shop_id', $shop->id)->where('source', 'purchase')->latest('id')->first();
        $this->assertNotNull($lot, 'lot created');
        $expectedFine = round(MetalRegistry::fineWeight('gold', 10.0, 22.0), 6);
        $this->assertEqualsWithDelta($expectedFine, (float) $lot->fine_weight_total, 0.0001, 'fine weight = MetalRegistry authority');
    }

    public function test_store_lot_rejects_out_of_range_purity(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/vault/lots', [
                'metal_type' => 'gold', 'source' => 'purchase',
                'gross_weight' => 10.0, 'purity' => 30.0, // > 24k
            ]))->assertSessionHasErrors('purity');
    }

    public function test_store_lot_rejects_non_accounting_metal(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP . '/vault/lots', [
                'metal_type' => 'platinum', 'source' => 'purchase',
                'gross_weight' => 10.0, 'purity' => 95.0,
            ]))->assertSessionHasErrors('metal_type');
    }

    // ── Tenant isolation ───────────────────────────────────────────────────

    public function test_cannot_view_another_shops_lot(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $lotB = $this->createMetalLot($shopB->id, 50.0);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->get(self::ERP . '/vault/lots/' . $lotB->id));

        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop lot must not be viewable');
    }

    public function test_cannot_adjust_another_shops_lot(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $lotB = $this->createMetalLot($shopB->id, 50.0);

        [$ownerA, $shopA] = $this->createRetailerTenant();
        $res = TenantContext::runFor($shopA->id, fn () => $this->actingAs($ownerA)
            ->post(self::ERP . '/vault/lots/' . $lotB->id . '/adjust', [
                'direction' => 'remove', 'fine_weight' => 5.0, 'reason' => 'cross-shop attempt',
            ]));

        $this->assertContains($res->getStatusCode(), [403, 404], 'cross-shop adjust must be blocked');
        // The lot balance is untouched and no movement was written.
        $this->assertEqualsWithDelta(50.0, (float) $lotB->fresh()->fine_weight_remaining, 0.0001);
        $this->assertSame(0, MetalMovement::withoutGlobalScopes()->where('shop_id', $shopB->id)->count());
    }

    public function test_vault_index_and_ledger_render(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->createMetalLot($shop->id, 100.0);

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/vault'))->assertOk();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->get(self::ERP . '/vault/ledger'))->assertOk();
    }

    // ── Permission & edition gating ────────────────────────────────────────

    public function test_user_without_vault_view_is_denied(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $noPerm = $this->userWithPerms($shop, ['sales.pos'], '9816600010'); // no vault.*

        TenantContext::runFor($shop->id, fn () => $this->actingAs($noPerm)
            ->get(self::ERP . '/vault'))->assertForbidden();
    }

    public function test_user_without_vault_manage_cannot_open_create_lot(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $viewer = $this->userWithPerms($shop, ['vault.view'], '9816600011'); // view only

        TenantContext::runFor($shop->id, fn () => $this->actingAs($viewer)
            ->get(self::ERP . '/vault/lots/create'))->assertForbidden();
    }

    public function test_manufacturer_edition_cannot_reach_vault(): void
    {
        // /vault is inside an edition:retailer group — a manufacturer is refused.
        [$owner, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get(self::ERP . '/vault'))->assertForbidden();
    }

    public function test_guest_cannot_reach_vault(): void
    {
        $res = $this->get(self::ERP . '/vault');
        $res->assertRedirect();
        $this->assertStringContainsString('/login', (string) $res->headers->get('Location'));
    }

    // ── Append-only metal ledger ───────────────────────────────────────────

    public function test_metal_movement_is_append_only(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id, 100.0);

        $movement = TenantContext::runFor($shop->id, fn () => app(BullionVaultService::class)
            ->adjustLot($lot, 5.0, 'physical count higher', $owner->id));

        // ImmutableLedger: a posted movement can be neither mutated nor deleted.
        TenantContext::runFor($shop->id, function () use ($movement) {
            try {
                $movement->forceFill(['fine_weight' => 999])->save();
                $this->fail('a posted metal movement must not be updatable');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(\LogicException::class, $e);
            }
            try {
                $movement->delete();
                $this->fail('a posted metal movement must not be deletable');
            } catch (\Throwable $e) {
                $this->assertInstanceOf(\LogicException::class, $e);
            }
        });
    }
}
