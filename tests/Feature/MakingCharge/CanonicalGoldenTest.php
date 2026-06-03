<?php

namespace Tests\Feature\MakingCharge;

use App\Services\PricingEngine;
use App\Services\PricingEngine\QuoteInput;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MC-2 constitutional guard: the canonical JSON that gets HMAC-signed for a
 * FIXED-mode quote must remain BYTE-IDENTICAL across the making-charge rollout.
 *
 * The locked string below was captured from the engine BEFORE any MC-2 change.
 * Fixed-mode quotes must emit NO new canonical keys (mode metadata appends only
 * for non-fixed modes), so this assertion proves historical signed quotes keep
 * verifying. If it ever fails → signature drift → STOP.
 */
class CanonicalGoldenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    /**
     * Byte-for-byte canonical JSON of the frozen fixed-mode manufacturer
     * fixture, captured pre-MC-2. DO NOT edit to make a test pass.
     */
    private const FIXED_CANONICAL = '{"shop_id":1,"customer_id":null,"mode":"manufacturer","quote_id":null,"expires_at":null,"subtotal":"67700.00","manual_discount":"0.00","offer_discount":"0.00","total_discount":"0.00","taxable":"67700.00","gst_rate":"3.00","gst":"2031.00","wastage_charge":"1440.00","pre_round_total":"71171.00","rounding_adjustment":"0.0000","final_total":"71171.00","rounding_method":"none","rounding_nearest":"1.00","offer":null,"compliance":null,"customer_gold":null,"lines":[{"item_id":1,"line_total":"67700.00","gst_amount":"2031.00","weight":"9.500","rate":"7200.00","making":"4800.00","stone":"200.00"}]}';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0:int,1:int} [shopId, itemId] with frozen pricing inputs. */
    private function fixture(): array
    {
        [, $shop] = $this->createManufacturerTenant();
        \App\Models\Shop::where('id', $shop->id)->update([
            'gst_rate' => 3,
            'wastage_recovery_percent' => 100,
        ]);
        $lot  = $this->createMetalLot($shop->id, 100.0);
        $item = $this->createItem($shop->id, $lot->id, [
            'net_metal_weight' => 9.500,
            'purity'           => 22.00,
            'wastage'          => 0.200,
            'metal_type'       => 'gold',
        ]);

        return [(int) $shop->id, (int) $item->id];
    }

    public function test_fixed_mode_canonical_json_is_byte_stable(): void
    {
        [$shopId, $itemId] = $this->fixture();

        $input = QuoteInput::manufacturer(
            shopId: $shopId,
            customerId: null,
            itemId: $itemId,
            goldRate: 7200.00,
            making: 4800.00,
            stone: 200.00,
            manualDiscount: 0.0,
        );

        $json = TenantContext::runFor($shopId, fn () =>
            app(PricingEngine::class)->compute($input)->toCanonicalJson()
        );

        // Identity fields (shop_id/item_id) are not money/structure; under
        // full-suite ID sequencing they are not 1. Substitute them into the
        // locked template so EVERY money/structure byte is still asserted
        // byte-for-byte while tolerating id variance.
        $expected = str_replace(
            ['"shop_id":1,', '"item_id":1,'],
            ['"shop_id":' . $shopId . ',', '"item_id":' . $itemId . ','],
            self::FIXED_CANONICAL,
        );

        $this->assertSame($expected, $json, 'Fixed-mode canonical JSON drifted — signed quotes would break.');
    }
}
