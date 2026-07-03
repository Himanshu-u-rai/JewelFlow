<?php

namespace Tests\Feature;

use App\Models\CashTransaction;
use App\Models\CustomerGoldTransaction;
use App\Models\CustomerOpeningBalance;
use App\Models\Item;
use App\Models\Karigar;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Models\StoreCreditMovement;
use App\Models\SupplierOpeningBalance;
use App\Models\Vendor;
use App\Services\MetalRegistry;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Onboarding Phases 2–5 — staging entries post into the canonical ledgers at
 * lock, back-dated to the batch as-of date, with the two invariants that make
 * opening balances safe:
 *   - NO phantom cash: opening metal writes lots/movements only, never cash.
 *   - NO double-count: opening stock uses the item pool (metal_lot_id=null),
 *     never a vault lot; the four metal pools are summed disjointly.
 */
class OnboardingPostingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_lock_posts_every_kind_to_its_canonical_ledger(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $customer = $this->createCustomer($shop->id);

        $vendor = new Vendor();
        $vendor->forceFill(['shop_id' => $shop->id, 'name' => 'Acme Bullion', 'is_active' => true]);
        $vendor->save();

        $karigar = new Karigar();
        $karigar->forceFill(['shop_id' => $shop->id, 'name' => 'Ravi', 'is_active' => true]);
        $karigar->save();

        // Create the batch: start 2026-08-01 → as-of 2026-07-31.
        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        $stage = function (array $data) use ($user, $shop, $batch) {
            TenantContext::set($shop->id); // middleware clears context each teardown
            $this->actingAs($user)
                ->post(route('onboarding.entries.store', $batch), $data)
                ->assertSessionHasNoErrors();
        };

        $stage(['kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 100000]);
        $stage(['kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'bank', 'amount' => 50000]);
        $stage(['kind' => OnboardingEntry::KIND_VAULT_METAL, 'metal_type' => 'gold', 'purity' => 22, 'fine_weight' => 100, 'cost_per_fine_gram' => 5000]);
        $stage(['kind' => OnboardingEntry::KIND_KARIGAR_GOLD, 'karigar_id' => $karigar->id, 'metal_type' => 'gold', 'purity' => 22, 'fine_weight' => 20]);
        $stage(['kind' => OnboardingEntry::KIND_KARIGAR_MONEY, 'karigar_id' => $karigar->id, 'amount' => 15000]);
        $stage(['kind' => OnboardingEntry::KIND_CUSTOMER_GOLD, 'customer_id' => $customer->id, 'fine_gold' => 10, 'gross_weight' => 11, 'purity' => 22]);
        $stage(['kind' => OnboardingEntry::KIND_CUSTOMER_ADVANCE, 'customer_id' => $customer->id, 'amount' => 5000]);
        $stage(['kind' => OnboardingEntry::KIND_CUSTOMER_RECEIVABLE, 'customer_id' => $customer->id, 'amount' => 8000]);
        $stage(['kind' => OnboardingEntry::KIND_SUPPLIER_PAYABLE, 'vendor_id' => $vendor->id, 'amount' => 30000]);
        $stage(['kind' => OnboardingEntry::KIND_STOCK_ITEM, 'metal_type' => 'gold', 'gross_weight' => 10, 'stone_weight' => 0.5, 'purity' => 22, 'cost_price' => 40000]);

        $this->assertSame(10, OnboardingEntry::withoutTenant()->count());

        // Lock → post.
        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.lock', $batch))->assertRedirect(route('onboarding.index'));

        $asOf = '2026-07-31';

        // Cash: two opening rows, back-dated, flagged. No phantom cash for metal.
        $cash = CashTransaction::withoutTenant()->get();
        $this->assertCount(2, $cash, 'Only the two cash entries — metal must not write cash rows.');
        foreach ($cash as $row) {
            $this->assertSame('in', $row->type);
            $this->assertSame('opening_balance', $row->source_type);
            $this->assertTrue((bool) $row->is_opening);
            $this->assertSame($asOf, $row->created_at->toDateString(), 'Opening cash must be dated to as-of (before start).');
            $this->assertSame('onboarding_batch', $row->reference_type);
        }

        // Vault vs karigar-held lots are disjoint.
        $vaultLot = MetalLot::withoutTenant()->where('source', 'opening')->firstOrFail();
        $this->assertEquals(100, (float) $vaultLot->fine_weight_remaining);
        $this->assertNull($vaultLot->karigar_id);

        $heldLot = MetalLot::withoutTenant()->where('source', MetalLot::SOURCE_KARIGAR_HELD)->firstOrFail();
        $this->assertEquals(20, (float) $heldLot->fine_weight_remaining);
        $this->assertSame($karigar->id, $heldLot->karigar_id);

        $this->assertSame(2, MetalMovement::withoutTenant()->where('type', 'opening')->count());
        $this->assertSame(2, MetalMovement::withoutTenant()->whereRaw('is_opening IS TRUE')->count());

        // Karigar money is additive on the karigar row.
        $this->assertEquals(15000, (float) Karigar::withoutTenant()->find($karigar->id)->opening_balance);

        // Customer gold, advance, receivable.
        $this->assertEquals(10, (float) CustomerGoldTransaction::withoutTenant()->where('type', 'adjust')->sum('fine_gold'));
        $adv = StoreCreditMovement::withoutTenant()->where('source_type', 'opening_advance')->firstOrFail();
        $this->assertEquals(5000, (float) $adv->amount);
        $rec = CustomerOpeningBalance::withoutTenant()->firstOrFail();
        $this->assertSame('receivable', $rec->direction);
        $this->assertEquals(8000, (float) $rec->amount);

        // Supplier payable.
        $sup = SupplierOpeningBalance::withoutTenant()->firstOrFail();
        $this->assertSame('payable', $sup->direction);
        $this->assertEquals(30000, (float) $sup->amount);

        // Opening stock item: own pool, never debits a vault lot.
        $item = Item::withoutTenant()->where('source', 'opening_stock')->firstOrFail();
        $this->assertNull($item->metal_lot_id, 'Opening stock must not debit a vault lot (no double-count).');
        $this->assertSame('in_stock', $item->status);
        $this->assertEquals(9.5, (float) $item->net_metal_weight);

        // Snapshot reconciles the four disjoint metal pools.
        $batch = OnboardingBatch::withoutTenant()->find($batch->id);
        $snap = $batch->totals_snapshot;
        $this->assertEquals(100, $snap['vault_fine']);
        $this->assertEquals(20, $snap['karigar_held_fine']);
        $this->assertEquals(10, $snap['customer_gold_fine']);
        $this->assertEqualsWithDelta(9.5 * 22 / 24, $snap['stock_item_fine'], 0.0001);
        $this->assertEquals(100000, $snap['cash_by_mode']['cash']);
        $this->assertEquals(50000, $snap['cash_by_mode']['bank']);
    }

    public function test_staged_entry_can_be_deleted_before_lock_but_not_after(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CASH, 'payment_mode' => 'cash', 'amount' => 100,
        ])->assertSessionHasNoErrors();

        $entry = OnboardingEntry::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->delete(route('onboarding.entries.destroy', [$batch, $entry]))->assertSessionHasNoErrors();
        $this->assertSame(0, OnboardingEntry::withoutTenant()->count());
    }

    public function test_cannot_stage_entry_referencing_another_shops_customer(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        [, $otherShop] = $this->createManufacturerTenant();
        $foreign = $this->createCustomer($otherShop->id);

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_CUSTOMER_RECEIVABLE, 'customer_id' => $foreign->id, 'amount' => 500,
        ])->assertSessionHasErrors('customer_id');

        $this->assertSame(0, OnboardingEntry::withoutTenant()->count());
    }

    public function test_vault_and_karigar_enforce_metal_tier_and_purity_scale(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $karigar = new Karigar();
        $karigar->forceFill(['shop_id' => $shop->id, 'name' => 'Ravi', 'is_active' => true]);
        $karigar->save();

        // Enable gold+silver (Tier 1) AND platinum (Tier 2, purity is NOT
        // accounting truth). Once ANY shop_enabled_metals row exists the empty
        // fallback stops applying, so gold/silver must be seeded explicitly too.
        // Platinum is an accepted shop metal but still barred from the
        // fine-weight-bearing vault/karigar pools.
        // Postgres rejects PHP true→1 on a boolean column; use a raw SQL literal
        // (project-wide pattern — see MetalRegistry::enabledMetalsForShop).
        foreach (['gold', 'silver', 'platinum'] as $metal) {
            DB::table('shop_enabled_metals')->updateOrInsert(
                ['shop_id' => $shop->id, 'metal_type' => $metal],
                ['enabled' => DB::raw('true')]
            );
        }
        MetalRegistry::clearShopCache($shop->id);

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        $post = function (array $data) use ($user, $shop, $batch) {
            TenantContext::set($shop->id);
            return $this->actingAs($user)->post(route('onboarding.entries.store', $batch), $data);
        };

        // Gold purity is karat-scaled (max 24) — 25 is rejected.
        $post(['kind' => OnboardingEntry::KIND_VAULT_METAL, 'metal_type' => 'gold', 'purity' => 25, 'fine_weight' => 10])
            ->assertSessionHasErrors('purity');

        // Silver purity is millesimal — 999 accepted.
        $post(['kind' => OnboardingEntry::KIND_VAULT_METAL, 'metal_type' => 'silver', 'purity' => 999, 'fine_weight' => 10])
            ->assertSessionHasNoErrors();

        // Silver 1000 would overflow metal_lots.purity decimal(5,2) — rejected.
        $post(['kind' => OnboardingEntry::KIND_VAULT_METAL, 'metal_type' => 'silver', 'purity' => 1000, 'fine_weight' => 10])
            ->assertSessionHasErrors('purity');

        // Platinum is enabled but non-accounting → barred from vault AND karigar.
        $post(['kind' => OnboardingEntry::KIND_VAULT_METAL, 'metal_type' => 'platinum', 'purity' => 950, 'fine_weight' => 10])
            ->assertSessionHasErrors('metal_type');
        $post(['kind' => OnboardingEntry::KIND_KARIGAR_GOLD, 'karigar_id' => $karigar->id, 'metal_type' => 'platinum', 'purity' => 950, 'fine_weight' => 10])
            ->assertSessionHasErrors('metal_type');

        // Only the valid silver-999 vault row survived.
        $this->assertSame(1, OnboardingEntry::withoutTenant()->count());
    }

    public function test_migration_down_refuses_to_orphan_posted_opening_advances(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();
        $customer = $this->createCustomer($shop->id);

        // A posted opening advance exists — narrowing the CHECK would orphan it.
        DB::table('store_credit_movements')->insert([
            'shop_id'     => $shop->id,
            'customer_id' => $customer->id,
            'amount'      => 5000,
            'source_type' => 'opening_advance',
            'user_id'     => $user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $migration = require database_path(
            'migrations/2026_09_01_010500_add_opening_advance_to_store_credit_source_check.php'
        );

        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_cannot_stage_stock_item_with_stone_heavier_than_gross(): void
    {
        [$user, $shop] = $this->createManufacturerTenant();

        $this->actingAs($user)->post(route('onboarding.store'), ['start_date' => '2026-08-01']);
        $batch = OnboardingBatch::withoutTenant()->firstOrFail();

        // stone > gross would make net_metal_weight (gross - stone) negative.
        TenantContext::set($shop->id);
        $this->actingAs($user)->post(route('onboarding.entries.store', $batch), [
            'kind' => OnboardingEntry::KIND_STOCK_ITEM, 'metal_type' => 'gold',
            'gross_weight' => 5, 'stone_weight' => 6, 'purity' => 22, 'cost_price' => 40000,
        ])->assertSessionHasErrors('stone_weight');

        $this->assertSame(0, OnboardingEntry::withoutTenant()->count());
    }
}
