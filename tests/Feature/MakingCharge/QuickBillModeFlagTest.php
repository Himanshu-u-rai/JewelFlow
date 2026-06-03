<?php

namespace Tests\Feature\MakingCharge;

use App\Models\Shop;
use App\Models\User;
use App\Services\QuickBillService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MC-4 flag gate: the making_charge_modes flag controls activation. With the
 * flag OFF, Quick Bill ignores any submitted mode and behaves fixed-only
 * (byte-identical). With it ON, percentage resolves on metal value.
 */
class QuickBillModeFlagTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeBill(int $shopId, User $user, array $itemOverrides)
    {
        $shop = Shop::withoutGlobalScopes()->findOrFail($shopId);
        return TenantContext::runFor($shopId, fn () => app(QuickBillService::class)->create($shop, $user, [
            'pricing_mode' => 'no_gst',
            'items' => [array_merge([
                'description' => 'Gold ring', 'net_weight' => 10, 'rate' => 7000,
            ], $itemOverrides)],
        ]));
    }

    public function test_purity_factor_scales_metal_value(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $bill = $this->makeBill($shop->id, $user, [
            'metal_type' => 'gold', 'purity' => '22K', 'rate' => 7200, 'net_weight' => 10,
            'making_charge' => 0,
        ]);

        $line = TenantContext::runFor($shop->id, fn () => $bill->items()->first());
        // Pure 24K rate ₹7200 × 10g × (22/24) = ₹66,000 (no GST, no other charges).
        $this->assertEqualsWithDelta(66000.00, (float) $line->line_total, 0.01);
    }

    public function test_silver_purity_factor_uses_thousandths(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $bill = $this->makeBill($shop->id, $user, [
            'metal_type' => 'silver', 'purity' => '925', 'rate' => 100, 'net_weight' => 50,
            'making_charge' => 0,
        ]);

        $line = TenantContext::runFor($shop->id, fn () => $bill->items()->first());
        // ₹100 × 50g × (925/1000) = ₹4,625.
        $this->assertEqualsWithDelta(4625.00, (float) $line->line_total, 0.01);
    }

    public function test_platinum_is_piece_priced_no_purity_factor(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $bill = $this->makeBill($shop->id, $user, [
            'metal_type' => 'platinum', 'purity' => 'Pt950', 'rate' => 3000, 'net_weight' => 5,
            'making_charge' => 0,
        ]);

        $line = TenantContext::runFor($shop->id, fn () => $bill->items()->first());
        // Platinum is piece-priced: factor 1 → ₹3000 × 5 = ₹15,000 (purity ignored).
        $this->assertEqualsWithDelta(15000.00, (float) $line->line_total, 0.01);
    }

    public function test_flag_off_ignores_mode_and_stays_fixed(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        config(['features.making_charge_modes' => false]);

        $bill = $this->makeBill($shop->id, $user, [
            'making_charge' => 0,
            'making_charge_type' => 'percentage',
            'making_charge_value' => 10,
        ]);

        $line = TenantContext::runFor($shop->id, fn () => $bill->items()->first());
        // Flag off ⇒ forced fixed ⇒ making = raw making_charge (0), mode NULL.
        $this->assertEqualsWithDelta(0.0, (float) $line->making_charge, 0.001);
        $this->assertNull($line->making_charge_type);
    }

    public function test_flag_on_resolves_percentage_on_metal_value(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        config(['features.making_charge_modes' => true]);

        $bill = $this->makeBill($shop->id, $user, [
            'making_charge_type' => 'percentage',
            'making_charge_value' => 10,
        ]);

        $line = TenantContext::runFor($shop->id, fn () => $bill->items()->first());
        // 10% of (10g × ₹7000 = ₹70,000) metal value = ₹7,000.
        $this->assertEqualsWithDelta(7000.00, (float) $line->making_charge, 0.001);
        $this->assertSame('percentage', $line->making_charge_type);
        $this->assertEqualsWithDelta(10.00, (float) $line->making_charge_value, 0.001);
    }
}
