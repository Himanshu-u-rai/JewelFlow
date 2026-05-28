<?php

namespace Tests\Feature\Material;

use App\Models\ShopMetalReferencePrice;
use App\Services\ReferencePriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * R2 — ReferencePriceService.
 *
 * Class B (platinum, copper) reference-price memo storage. Append-only.
 * Service must be incapable of recording for class A (gold/silver) or class C
 * (stones), and the service file must contain none of the forbidden rate-
 * engine tokens.
 */
class ReferencePriceServiceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_record_and_latest_for_platinum(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $svc = new ReferencePriceService;

        $first = $svc->recordReference($shop->id, 'platinum', 3200.0, $user->id, 'supplier ABC');
        $this->assertSame('platinum', $first->metal_type);
        $this->assertSame(3200.0, (float) $first->reference_price);

        $latest = $svc->latestReference($shop->id, 'platinum');
        $this->assertNotNull($latest);
        $this->assertSame((int) $first->id, (int) $latest->id);
    }

    public function test_latest_reflects_most_recent_record(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $svc = new ReferencePriceService;

        $svc->recordReference($shop->id, 'platinum', 3000.0);
        $svc->recordReference($shop->id, 'platinum', 3100.0);
        $latest = $svc->recordReference($shop->id, 'platinum', 3250.0, $user->id, 'updated this week');

        $this->assertSame((int) $latest->id, (int) $svc->latestReference($shop->id, 'platinum')->id);
        $this->assertSame(3250.0, (float) $svc->latestReference($shop->id, 'platinum')->reference_price);
    }

    public function test_latest_is_null_when_no_record_exists(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->assertNull((new ReferencePriceService)->latestReference($shop->id, 'platinum'));
    }

    public function test_metals_are_isolated(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $svc = new ReferencePriceService;
        $svc->recordReference($shop->id, 'platinum', 3200.0);

        $this->assertNotNull($svc->latestReference($shop->id, 'platinum'));
        $this->assertNull($svc->latestReference($shop->id, 'copper'));
    }

    public function test_rejects_class_a_metals(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $svc = new ReferencePriceService;

        foreach (['gold', 'silver'] as $metal) {
            try {
                $svc->recordReference($shop->id, $metal, 1000.0);
                $this->fail("Service must reject class A metal '{$metal}' but did not.");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('Class B', $e->getMessage());
            }
        }
    }

    public function test_rejects_stones_and_unsupported(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $svc = new ReferencePriceService;

        foreach (['diamond', 'stone', 'unobtainium'] as $bad) {
            try {
                $svc->recordReference($shop->id, $bad, 100.0);
                $this->fail("Service must reject non-Tier-2 input '{$bad}' but did not.");
            } catch (\LogicException $e) {
                $this->assertStringContainsString('Class B', $e->getMessage());
            }
        }
    }

    public function test_db_check_constraint_enforces_class_b(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        // Bypass the service and try to insert gold directly — the CHECK
        // constraint at the DB layer is the last line of defence.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('shop_metal_reference_prices')->insert([
            'shop_id'         => $shop->id,
            'metal_type'      => 'gold',
            'reference_price' => 5000.00,
            'noted_at'        => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function test_negative_price_is_rejected(): void
    {
        [$user, $shop] = $this->createRetailerTenant();

        $this->expectException(\LogicException::class);
        (new ReferencePriceService)->recordReference($shop->id, 'platinum', -1.0);
    }

    public function test_records_are_append_only(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $record = (new ReferencePriceService)->recordReference($shop->id, 'platinum', 3200.0);

        // Update via Eloquent should throw.
        try {
            $record->reference_price = 3300.0;
            $record->save();
            $this->fail('Updating a reference-price record must throw.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        // Delete via Eloquent should throw.
        try {
            $record->delete();
            $this->fail('Deleting a reference-price record must throw.');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }
    }

    /**
     * Immediate structural anti-drift: the reference-price file must not
     * contain any token belonging to the rate engine. The full architecture
     * exclusivity tests ship in R6; this is the entry-level guard.
     */
    public function test_service_file_contains_no_rate_engine_tokens(): void
    {
        $source = file_get_contents(app_path('Services/ReferencePriceService.php'));

        foreach (['rate_per_gram', 'resolvedRateForToday', 'RepriceRetailerInventoryJob', 'MetalRate::', 'shop_daily_metal_rate', 'ShopPricingService'] as $forbidden) {
            // The doc comment is allowed to NAME the forbidden tokens to warn
            // contributors. Strip the comment block before scanning so a future
            // executable mention is still caught.
            $code = preg_replace('!/\*.*?\*/!s', '', $source);
            $this->assertStringNotContainsString(
                $forbidden,
                $code,
                "ReferencePriceService.php must not reference '{$forbidden}' in executable code."
            );
        }
    }
}
