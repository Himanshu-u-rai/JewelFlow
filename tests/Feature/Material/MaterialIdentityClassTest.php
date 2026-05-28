<?php

namespace Tests\Feature\Material;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1 — Material identity class formalization.
 *
 * Locks the four-identity-system contract (see material-identity-audit.md):
 *   gold, silver -> purity_accounting (A)
 *   platinum     -> purity_spec       (B)
 *   copper       -> manual_grade      (D)
 * and the capabilities derived from it.
 */
class MaterialIdentityClassTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_class_per_metal(): void
    {
        $this->assertSame(MetalRegistry::IDENTITY_PURITY_ACCOUNTING, MetalRegistry::identityClass('gold'));
        $this->assertSame(MetalRegistry::IDENTITY_PURITY_ACCOUNTING, MetalRegistry::identityClass('silver'));
        $this->assertSame(MetalRegistry::IDENTITY_PURITY_SPEC, MetalRegistry::identityClass('platinum'));
        $this->assertSame(MetalRegistry::IDENTITY_MANUAL_GRADE, MetalRegistry::identityClass('copper'));
    }

    public function test_identity_class_throws_for_unsupported(): void
    {
        $this->expectException(\LogicException::class);
        MetalRegistry::identityClass('unobtainium');
    }

    public function test_identity_class_throws_for_empty(): void
    {
        $this->expectException(\LogicException::class);
        MetalRegistry::identityClass('');
    }

    public function test_purity_is_accounting_truth_only_for_gold_and_silver(): void
    {
        $this->assertTrue(MetalRegistry::purityIsAccountingTruth('gold'));
        $this->assertTrue(MetalRegistry::purityIsAccountingTruth('silver'));
        $this->assertFalse(MetalRegistry::purityIsAccountingTruth('platinum'));
        $this->assertFalse(MetalRegistry::purityIsAccountingTruth('copper'));
    }

    public function test_purity_is_specification_only_for_platinum(): void
    {
        $this->assertTrue(MetalRegistry::purityIsSpecification('platinum'));
        foreach (['gold', 'silver', 'copper'] as $metal) {
            $this->assertFalse(MetalRegistry::purityIsSpecification($metal));
        }
    }

    public function test_hallmark_relevant_excludes_copper_only(): void
    {
        foreach (['gold', 'silver', 'platinum'] as $metal) {
            $this->assertTrue(MetalRegistry::hallmarkRelevant($metal), "{$metal} should be hallmark relevant");
        }
        $this->assertFalse(MetalRegistry::hallmarkRelevant('copper'));
    }

    public function test_purity_selector_mode(): void
    {
        $this->assertSame('mandatory', MetalRegistry::puritySelectorMode('gold'));
        $this->assertSame('mandatory', MetalRegistry::puritySelectorMode('silver'));
        $this->assertSame('lightweight', MetalRegistry::puritySelectorMode('platinum'));
        $this->assertSame('hidden', MetalRegistry::puritySelectorMode('copper'));
    }

    public function test_purity_label_is_scale_correct(): void
    {
        $this->assertSame('Karat (K)', MetalRegistry::purityLabel('gold'));
        $this->assertSame('Fineness', MetalRegistry::purityLabel('silver'));
        $this->assertSame('Hallmark grade', MetalRegistry::purityLabel('platinum'));
        $this->assertSame('', MetalRegistry::purityLabel('copper'));
    }

    /**
     * Consistency with existing capability flags. purity-accounting class must
     * line up with the rate-derivation and live-rate behaviour that already
     * exists, otherwise the implicit assumptions disagree with the formal model.
     */
    public function test_identity_class_consistent_with_existing_flags(): void
    {
        foreach (['gold', 'silver', 'platinum', 'copper'] as $metal) {
            $isAccounting = MetalRegistry::purityIsAccountingTruth($metal);

            // Rate-derived item creation iff purity is accounting truth.
            $this->assertSame(
                $isAccounting,
                MetalRegistry::uxItemCreationDefault($metal) === 'rate_derived',
                "uxItemCreationDefault disagrees with identity class for {$metal}"
            );

            // Live-rate eligibility (Tier 1) iff purity is accounting truth.
            $this->assertSame(
                $isAccounting,
                MetalRegistry::isLiveRateEligible($metal),
                "isLiveRateEligible disagrees with identity class for {$metal}"
            );

            // Rates dashboard visibility iff purity is accounting truth.
            $this->assertSame(
                $isAccounting,
                MetalRegistry::uxRatesDashboardVisible($metal),
                "uxRatesDashboardVisible disagrees with identity class for {$metal}"
            );
        }
    }
}
