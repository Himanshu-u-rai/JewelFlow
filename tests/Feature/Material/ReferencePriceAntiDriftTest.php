<?php

namespace Tests\Feature\Material;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * R6 — Anti-drift architecture tests for the Class-B reference-price surface.
 *
 * Each test guards one concrete drift vector. They are named after the vector,
 * not the code. If any of these fail, someone is collapsing pricing classes
 * A/B/C back into one engine — the build must fail loudly.
 *
 * Vectors:
 *   - reference service NEVER imports the rate engine
 *   - pricing/vault/reprice paths NEVER import the reference service
 *   - reference table NEVER carries class-A column names
 *   - materials:audit stays clean (recursive scan, no class-leak literals)
 */
class ReferencePriceAntiDriftTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** Strip docblocks and // comments so prohibition reminders don't trip the scan. */
    private function executableCode(string $absolutePath): string
    {
        $src = (string) file_get_contents($absolutePath);
        $src = (string) preg_replace('!/\*.*?\*/!s', '', $src);
        $src = (string) preg_replace('!//[^\n]*!s', '', $src);
        return $src;
    }

    public function test_reference_service_does_not_import_rate_engine(): void
    {
        $code = $this->executableCode(app_path('Services/ReferencePriceService.php'));
        foreach ([
            'ShopPricingService',
            'shop_daily_metal_rate',
            'MetalRate::',
            'resolvedRateForToday',
            'RepriceRetailerInventoryJob',
            'fineWeightMultiplier',
            'rate_per_gram',
        ] as $token) {
            $this->assertStringNotContainsString($token, $code, "ReferencePriceService must not reference '{$token}' in executable code.");
        }
    }

    public function test_pricing_engine_does_not_import_reference_service(): void
    {
        $this->assertReferenceFree(app_path('Services/ShopPricingService.php'));
    }

    public function test_vault_service_does_not_import_reference_service(): void
    {
        $this->assertReferenceFree(app_path('Services/BullionVaultService.php'));
    }

    public function test_reprice_job_does_not_import_reference_service(): void
    {
        $this->assertReferenceFree(app_path('Jobs/RepriceRetailerInventoryJob.php'));
    }

    public function test_compute_retailer_cost_payload_callers_do_not_import_reference_service(): void
    {
        // computeRetailerCostPayload lives on ShopPricingService; its callers
        // must also be free of reference-price leakage at the controller/import
        // level (the partial that renders the hint reads $referenceHints from
        // the view data, not from the pricing path).
        $this->assertReferenceFree(app_path('Http/Controllers/ItemController.php'), allowImportOnly: true);
        $this->assertReferenceFree(app_path('Http/Controllers/Api/Mobile/ItemController.php'));
        $this->assertReferenceFree(app_path('Services/BulkImportService.php'));
    }

    public function test_reference_table_schema_carries_no_class_a_column_names(): void
    {
        $columns = Schema::getColumnListing('shop_metal_reference_prices');
        $this->assertNotEmpty($columns, 'shop_metal_reference_prices table must exist.');
        foreach (['rate_per_gram', 'business_date', 'resolved_rate_per_gram', 'fine_weight_multiplier'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns, "shop_metal_reference_prices must NOT carry the class-A column '{$forbidden}'.");
        }
        // And it MUST carry the class-B vocabulary.
        $this->assertContains('reference_price', $columns);
        $this->assertContains('noted_at', $columns);
        $this->assertContains('noted_by_user_id', $columns);
    }

    public function test_materials_audit_is_recursive_clean(): void
    {
        // The materials:audit command must run to completion against the
        // reference-price surface without surfacing class-leak literals. The
        // command is read-only.
        $exit = Artisan::call('materials:audit');
        $this->assertSame(0, $exit, 'materials:audit must exit clean after R2–R5.');
    }

    /**
     * Assert a file does NOT import or reference the class-B service surface
     * in executable code. The constitution-style banner comments in the file
     * are allowed; only live code is scanned.
     */
    private function assertReferenceFree(string $absolutePath, bool $allowImportOnly = false): void
    {
        $code = $this->executableCode($absolutePath);

        $forbidden = [
            'ReferencePriceService',
            'shop_metal_reference_prices',
            'latestReference',
            'recordReference',
            'ShopMetalReferencePrice',
        ];
        foreach ($forbidden as $token) {
            if ($allowImportOnly && in_array($token, ['ReferencePriceService', 'latestReference'], true)) {
                // ItemController is the official entry to the service for the
                // display-only hint payload (R4 hook). It is permitted to
                // instantiate the service and call latestReference. The rate
                // engine MUST NOT do this — those callers use the strict
                // version of this assertion above.
                continue;
            }
            $this->assertStringNotContainsString(
                $token,
                $code,
                basename($absolutePath) . " must not reference the class-B token '{$token}' in executable code."
            );
        }
    }
}
