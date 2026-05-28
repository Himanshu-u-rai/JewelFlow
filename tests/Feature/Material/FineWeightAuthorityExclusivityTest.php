<?php

namespace Tests\Feature\Material;

use App\Services\MetalRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Structural anti-drift guard for the material/purity system.
 *
 * These tests FAIL the build if a future change reintroduces semantic
 * divergence — inline fine-weight math, or mobile metal-validation that
 * bypasses the capability layer. The point is to stop rediscovering the same
 * category of bug months later.
 */
class FineWeightAuthorityExclusivityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * No business code may derive fine weight inline. `purity / 24` and
     * `purity / 1000` must exist ONLY inside MetalRegistry (the authority).
     * One documented exception: a display-only SQL aggregate that is explicitly
     * gold-filtered (the PHP authority cannot run inside raw SQL).
     */
    public function test_no_inline_fine_weight_purity_math_outside_authority(): void
    {
        $roots = [app_path('Services'), app_path('Http/Controllers')];
        $offenders = [];

        foreach ($roots as $root) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                if ($file->getFilename() === 'MetalRegistry.php') {
                    continue; // the authority itself
                }

                foreach (file($file->getPathname()) as $n => $line) {
                    if (! preg_match('/purity\s*\/\s*(24|1000)/i', $line)) {
                        continue;
                    }
                    // Documented exception: gold-filtered display SQL aggregate.
                    if (str_contains($line, "metal_type = 'gold'")) {
                        continue;
                    }
                    $offenders[] = $file->getPathname() . ':' . ($n + 1) . ' → ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Inline fine-weight derivation found. Route through MetalRegistry::fineWeight()/fineWeightMultiplier():\n"
                . implode("\n", $offenders)
        );
    }

    /**
     * Mobile item validation must be capability-driven, exactly like web.
     * No hardcoded gold/silver literal; uses enabledMetalsForShop.
     */
    public function test_mobile_item_validation_is_capability_driven(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/Mobile/ItemController.php'));

        $this->assertStringNotContainsString("Rule::in(['gold', 'silver'])", $source);
        $this->assertStringContainsString('enabledMetalsForShop', $source);
        $this->assertStringContainsString('purityIsAccountingTruth', $source);
    }

    /**
     * The authority's gold/silver math must equal the legacy inline formula
     * exactly — byte-stability lock for the routing done in this pass.
     */
    public function test_authority_matches_legacy_gold_silver_formula(): void
    {
        foreach ([10.0, 9.5, 3.333, 50.0, 100.123456] as $net) {
            foreach ([24.0, 22.0, 18.0, 14.0] as $karat) {
                $this->assertSame(
                    $net * ($karat / 24),
                    $net * MetalRegistry::fineWeightMultiplier('gold', $karat),
                    "gold {$karat}K @ {$net}g diverged from legacy /24"
                );
            }
            foreach ([999.0, 925.0, 900.0] as $fineness) {
                $this->assertSame(
                    $net * ($fineness / 1000),
                    $net * MetalRegistry::fineWeightMultiplier('silver', $fineness),
                    "silver {$fineness} @ {$net}g diverged from legacy /1000"
                );
            }
        }
    }
}
