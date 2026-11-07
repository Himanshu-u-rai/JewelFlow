<?php

namespace Tests\Feature\Vault;

use App\Models\Customer;
use App\Models\MetalLot;
use App\Services\BuybackService;
use App\Services\BullionVaultService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Gold taken in (buyback, a customer's gold deposit, gold added to stock) must be recorded as gold. A lot without a
 * metal shows up in Vault Balances as "Unknown material" and is refused by every gold-only step (melt recovery,
 * return re-dispose).
 */
class LotMetalTypeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->withoutVite();
    }

    public function test_a_lot_cannot_be_created_without_its_metal(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $row = ['shop_id' => $shop->id, 'source' => 'purchase', 'purity' => 22, 'fine_weight_total' => 5,
            'fine_weight_remaining' => 5, 'cost_per_fine_gram' => 0, 'created_at' => now(), 'updated_at' => now()];

        // Left open, the purity decides: karats (up to 24) are gold, fineness (above 24, up to 1000) is silver.
        $gold = TenantContext::runFor($shop->id, fn () => MetalLot::create(['purity' => 22] + $row));
        $silver = TenantContext::runFor($shop->id, fn () => MetalLot::create(['purity' => 999] + $row));
        $this->assertSame('gold', $gold->fresh()->metal_type);
        $this->assertSame('silver', $silver->fresh()->metal_type);
        // A metal that is given is kept.
        $this->assertSame('silver', TenantContext::runFor($shop->id, fn () => MetalLot::create(['metal_type' => 'silver', 'purity' => 925] + $row))->metal_type);

        // A purity that cannot decide (0, above 1000), and a raw insert with no metal, are refused.
        foreach ([fn () => MetalLot::create(['purity' => 0] + $row), fn () => MetalLot::create(['purity' => 1200] + $row),
            fn () => \Illuminate\Support\Facades\DB::table('metal_lots')->insert($row + ['lot_number' => 999])] as $attempt) {
            try {
                \Illuminate\Support\Facades\DB::transaction(fn () => TenantContext::runFor($shop->id, $attempt));
                $this->fail('a lot without a metal was created');
            } catch (\LogicException|\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('metal', strtolower($e->getMessage()));
            }
        }
        $this->assertSame(0, MetalLot::withoutGlobalScopes()->where('shop_id', $shop->id)->whereNull('metal_type')->count());
    }

    public function test_old_unmarked_lots_are_marked_from_their_purity_only_on_commit(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $make = function (float $purity) use ($shop) {
            $lot = TenantContext::runFor($shop->id, fn () => MetalLot::create(['shop_id' => $shop->id, 'source' => 'purchase',
                'metal_type' => 'gold', 'purity' => $purity, 'fine_weight_total' => 5, 'fine_weight_remaining' => 5, 'cost_per_fine_gram' => 0]));
            // Old data: no metal recorded.
            \Illuminate\Support\Facades\DB::table('metal_lots')->where('id', $lot->id)->update(['metal_type' => null, 'purity' => $purity]);

            return $lot->id;
        };
        $karat = $make(22);
        $fine = $make(999);
        $undecidable = $make(0);
        $metal = fn (int $id) => \Illuminate\Support\Facades\DB::table('metal_lots')->where('id', $id)->value('metal_type');

        $this->artisan('lots:mark-metal')->assertSuccessful();
        $this->assertNull($metal($karat), 'the dry run wrote');

        $this->artisan('lots:mark-metal', ['--commit' => true])->assertSuccessful();
        $this->assertSame('gold', $metal($karat));
        $this->assertSame('silver', $metal($fine));
        $this->assertNull($metal($undecidable), 'a lot the purity cannot decide was guessed');
        $this->assertSame(2, \App\Models\AuditLog::withoutGlobalScopes()->where('action', 'metal_lot.metal_marked')->count());
    }

    public function test_gold_taken_in_is_recorded_as_gold(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $customer = TenantContext::runFor($shop->id, fn () => Customer::create([
            'shop_id' => $shop->id, 'first_name' => 'Gold', 'last_name' => 'Depositor', 'mobile' => '9815500009',
        ]));
        $this->actingAs($owner);

        $buyback = TenantContext::runFor($shop->id, fn () => BuybackService::buyGold(10.0, 22.0, 0, 6000.0));
        TenantContext::runFor($shop->id, fn () => $this->post("/customers/{$customer->id}/gold", ['gross_weight' => 8.0, 'purity' => 22.0]))->assertRedirect();
        TenantContext::runFor($shop->id, fn () => $this->post('/inventory/gold', ['source' => 'purchase', 'gross_weight' => 5.0, 'purity' => 22.0, 'cost_per_gram' => 6000]))->assertRedirect();

        $lots = MetalLot::withoutGlobalScopes()->where('shop_id', $shop->id)->whereIn('source', ['buyback', 'customer_advance', 'purchase'])->get();
        $this->assertCount(3, $lots);
        foreach ($lots as $lot) {
            $this->assertSame('gold', $lot->metal_type, "{$lot->source} lot has no metal");
        }
        $this->assertSame('gold', $buyback->metal_type);

        $unknown = app(BullionVaultService::class)->vaultBalances($shop->id)->filter(fn ($b) => $b['metal_type'] === null);
        $this->assertCount(0, $unknown, 'Vault Balances shows an Unknown metal');
    }
}
