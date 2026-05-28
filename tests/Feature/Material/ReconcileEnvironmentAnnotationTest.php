<?php

namespace Tests\Feature\Material;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * E2 — reconciliation environment annotation (display only).
 *
 * Non-production shops get a contextual note in reconcile output, but the note
 * NEVER changes discrepancy detection or the exit code.
 */
class ReconcileEnvironmentAnnotationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function unbalancedLot(int $shopId, float $stored): void
    {
        DB::table('metal_lots')->insert([
            'shop_id' => $shopId,
            'metal_type' => 'gold',
            'source' => 'opening',
            'purity' => 22.0,
            'fine_weight_total' => $stored,
            'fine_weight_remaining' => $stored,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_demo_shop_gets_context_note_but_still_fails(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        DB::table('shops')->where('id', $shop->id)->update(['environment' => 'demo']);
        $this->unbalancedLot($shop->id, 100.0);

        $exit = Artisan::call('vault:reconcile', ['--shop' => $shop->id]);
        $output = Artisan::output();

        // Annotation present...
        $this->assertStringContainsString('demo shop', $output);
        $this->assertStringContainsString('seeded inventory', $output);
        // ...but the discrepancy is NOT hidden — exit code unchanged.
        $this->assertSame(1, $exit);
    }

    public function test_production_shop_gets_no_note(): void
    {
        [$user, $shop] = $this->createRetailerTenant(); // defaults to production
        $this->unbalancedLot($shop->id, 100.0);

        $exit = Artisan::call('vault:reconcile', ['--shop' => $shop->id]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('seeded inventory', $output);
        $this->assertSame(1, $exit);
    }
}
