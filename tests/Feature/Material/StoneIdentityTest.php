<?php

namespace Tests\Feature\Material;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P5 — Stone identity containment.
 *
 * Stones are class C (attribute/value identity). They are NOT metals, never
 * carry purity, and can never enter the purity/fine-weight machinery. The
 * simple rupee stone_amount model and unexposed advanced routes are covered by
 * StoneUxSimplificationTest; this locks the identity boundary.
 */
class StoneIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_stones_are_not_metals(): void
    {
        foreach (['diamond', 'stone', 'ruby', 'emerald', 'moissanite', 'pearl'] as $stone) {
            $this->assertFalse(
                MetalRegistry::isSupported($stone),
                "{$stone} must not be a supported metal"
            );
            $this->assertNotContains($stone, MetalRegistry::allSupportedMetals());
        }
    }

    public function test_stone_has_no_metal_identity_class(): void
    {
        // A stone is not a metal, so asking the metal registry for its identity
        // class is an error — stone identity lives at the stone layer.
        $this->expectException(\LogicException::class);
        MetalRegistry::identityClass('diamond');
    }

    public function test_stones_never_produce_fine_weight(): void
    {
        // Even by accident, a stone string can never yield a fine weight.
        $this->expectException(\LogicException::class);
        MetalRegistry::fineWeight('diamond', 1.0, 100);
    }

    public function test_attribute_value_class_is_reserved_for_stones(): void
    {
        // The constant exists for documentation/reference, distinct from the
        // three metal identity classes, and is not assigned to any metal.
        $this->assertSame('attribute_value', MetalRegistry::IDENTITY_ATTRIBUTE_VALUE);
        foreach (MetalRegistry::allSupportedMetals() as $metal) {
            $this->assertNotSame(
                MetalRegistry::IDENTITY_ATTRIBUTE_VALUE,
                MetalRegistry::identityClass($metal),
                "{$metal} must not use the stone attribute_value class"
            );
        }
    }
}
