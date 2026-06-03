<?php

namespace Tests\Feature\MakingCharge;

use App\Services\PricingEngine;
use App\Services\PricingEngine\QuoteInput;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * MC-2 engine evolution: percentage / per-gram resolution correctness,
 * canonical append-only ordering, recompute drift protection, and signed-quote
 * verification. Pure-engine (no HTTP/permission layer), so unaffected by the
 * unrelated RBAC test-suite breakage.
 *
 * Frozen fixture: net 9.5g, 22k gold, rate ₹7200 ⇒ fine 8.708333g ⇒
 * metal value ₹62,700.00.
 */
class MakingChargeEngineTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const METAL_VALUE = 62700.00;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0:int,1:int} */
    private function fixture(): array
    {
        [, $shop] = $this->createManufacturerTenant();
        \App\Models\Shop::where('id', $shop->id)->update(['gst_rate' => 3, 'wastage_recovery_percent' => 100]);
        $lot  = $this->createMetalLot($shop->id, 100.0);
        $item = $this->createItem($shop->id, $lot->id, [
            'net_metal_weight' => 9.500, 'purity' => 22.00, 'wastage' => 0.0, 'metal_type' => 'gold',
        ]);
        return [(int) $shop->id, (int) $item->id];
    }

    private function compute(int $shopId, QuoteInput $input)
    {
        return TenantContext::runFor($shopId, fn () => app(PricingEngine::class)->compute($input));
    }

    public function test_percentage_mode_resolves_on_metal_value_only(): void
    {
        [$shopId, $itemId] = $this->fixture();

        $b = $this->compute($shopId, QuoteInput::manufacturer(
            shopId: $shopId, customerId: null, itemId: $itemId, goldRate: 7200.00,
            stone: 200.00, makingType: 'percentage', makingValue: 12.0,
        ));

        // 12% of ₹62,700 metal value = ₹7,524.00 (stone NOT included).
        $this->assertEqualsWithDelta(7524.00, (float) $b->lines[0]['making'], 0.001);
        $this->assertEqualsWithDelta(self::METAL_VALUE + 7524.00 + 200.00, (float) $b->lines[0]['line_total'], 0.001);
        $this->assertEqualsWithDelta(self::METAL_VALUE + 7524.00 + 200.00, $b->subtotal, 0.001);
    }

    public function test_per_gram_mode_resolves_on_net_weight_only(): void
    {
        [$shopId, $itemId] = $this->fixture();

        $b = $this->compute($shopId, QuoteInput::manufacturer(
            shopId: $shopId, customerId: null, itemId: $itemId, goldRate: 7200.00,
            stone: 200.00, makingType: 'per_gram', makingValue: 350.0,
        ));

        // ₹350/g × 9.5g net = ₹3,325.00 (gross/stone not used).
        $this->assertEqualsWithDelta(3325.00, (float) $b->lines[0]['making'], 0.001);
        $this->assertEqualsWithDelta(self::METAL_VALUE + 3325.00 + 200.00, $b->subtotal, 0.001);
    }

    public function test_fixed_mode_unchanged_and_emits_no_mode_keys(): void
    {
        [$shopId, $itemId] = $this->fixture();

        $b = $this->compute($shopId, QuoteInput::manufacturer(
            shopId: $shopId, customerId: null, itemId: $itemId, goldRate: 7200.00,
            making: 4800.00, stone: 200.00,
        ));

        $this->assertEqualsWithDelta(4800.00, (float) $b->lines[0]['making'], 0.001);
        $json = $b->toCanonicalJson();
        $this->assertStringNotContainsString('making_type', $json, 'fixed mode must not emit mode keys');
        $this->assertStringNotContainsString('making_value', $json);
    }

    public function test_canonical_mode_keys_are_appended_after_stone(): void
    {
        [$shopId, $itemId] = $this->fixture();

        $b = $this->compute($shopId, QuoteInput::manufacturer(
            shopId: $shopId, customerId: null, itemId: $itemId, goldRate: 7200.00,
            makingType: 'percentage', makingValue: 12.0,
        ));
        $json = $b->toCanonicalJson();

        $this->assertStringContainsString('"making_type":"percentage"', $json);
        $this->assertStringContainsString('"making_value":"12.00"', $json);
        // Append-only: mode keys come AFTER `stone` in the line object.
        $this->assertLessThan(strpos($json, 'making_type'), strpos($json, '"stone"'), 'mode keys must append after stone');
    }

    public function test_recompute_reproduces_identical_canonical_bytes_no_drift(): void
    {
        [$shopId, $itemId] = $this->fixture();

        foreach ([
            QuoteInput::manufacturer(shopId: $shopId, customerId: null, itemId: $itemId, goldRate: 7200.00, making: 4800.00, stone: 200.00),
            QuoteInput::manufacturer(shopId: $shopId, customerId: null, itemId: $itemId, goldRate: 7200.00, stone: 200.00, makingType: 'percentage', makingValue: 12.0),
            QuoteInput::manufacturer(shopId: $shopId, customerId: null, itemId: $itemId, goldRate: 7200.00, makingType: 'per_gram', makingValue: 350.0),
        ] as $input) {
            $json1 = $this->compute($shopId, $input)->toCanonicalJson();
            // Replay through the persisted payload shape (what recompute() uses).
            $replayed = QuoteInput::fromArray($input->toArray());
            $json2 = $this->compute($shopId, $replayed)->toCanonicalJson();
            $this->assertSame($json1, $json2, 'recompute drift detected for mode ' . $input->makingType);
        }
    }

    public function test_signed_quote_verifies_and_rejects_tampering(): void
    {
        [$shopId, $itemId] = $this->fixture();
        $engine = app(PricingEngine::class);

        $b = $this->compute($shopId, QuoteInput::manufacturer(
            shopId: $shopId, customerId: null, itemId: $itemId, goldRate: 7200.00,
            makingType: 'percentage', makingValue: 12.0,
        ));
        [$json, $signature] = $engine->sign($b);

        $this->assertTrue($engine->verify($json, $signature));
        $this->assertFalse($engine->verify(str_replace('7524.00', '9999.00', $json), $signature), 'tampered making must fail verification');
    }
}
