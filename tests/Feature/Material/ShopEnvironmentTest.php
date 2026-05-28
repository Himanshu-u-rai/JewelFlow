<?php

namespace Tests\Feature\Material;

use App\Models\Shop;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * E1 — shop environment classification (metadata only).
 *
 * shops.environment defaults to 'production', supports demo/internal_test,
 * is platform-admin-only (not mass-assignable), and is constrained to the
 * three approved classes. It is read for labels only — never accounting.
 */
class ShopEnvironmentTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_default_environment_is_production(): void
    {
        $shop = $this->createShop('retailer');
        $shop->refresh();

        $this->assertSame('production', $shop->environment);
        $this->assertTrue($shop->isProduction());
        $this->assertFalse($shop->isDemo());
        $this->assertFalse($shop->isNonProduction());
    }

    public function test_demo_classification(): void
    {
        $shop = $this->createShop('retailer');
        DB::table('shops')->where('id', $shop->id)->update(['environment' => 'demo']);
        $shop->refresh();

        $this->assertTrue($shop->isDemo());
        $this->assertTrue($shop->isNonProduction());
        $this->assertFalse($shop->isProduction());
    }

    public function test_environment_is_not_mass_assignable(): void
    {
        // Platform-admin-only: must NOT be settable through shop forms.
        $this->assertNotContains('environment', (new Shop)->getFillable());
    }

    public function test_check_constraint_rejects_invalid_environment(): void
    {
        $shop = $this->createShop('retailer');

        $this->expectException(QueryException::class);
        DB::table('shops')->where('id', $shop->id)->update(['environment' => 'pilot']);
    }
}
