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
