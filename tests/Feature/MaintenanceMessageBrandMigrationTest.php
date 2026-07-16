<?php

namespace Tests\Feature;

use App\Http\Middleware\CheckMaintenanceMode;
use App\Models\Platform\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Guards the forward-only data migration that rewrites the legacy "JewelFlow"
 * brand in the stored platform maintenance message to canonical "JewelFlows",
 * without touching administrator-customized messages.
 */
class MaintenanceMessageBrandMigrationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const KEY = 'maintenance_message';

    private const LEGACY = "JewelFlow is temporarily down for maintenance. We'll be back shortly.";

    private const CANONICAL = "JewelFlows is temporarily down for maintenance. We'll be back shortly.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfNotPostgres();
    }

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_06_000000_normalize_maintenance_message_brand.php');
        $migration->up();
    }

    public function test_exact_legacy_default_becomes_jewelflows(): void
    {
        PlatformSetting::set(self::KEY, self::LEGACY);

        $this->runMigration();

        $this->assertSame(self::CANONICAL, PlatformSetting::get(self::KEY));
    }

    public function test_customized_message_is_preserved(): void
    {
        $custom = "We're upgrading our systems. Please check back at 6pm.";
        PlatformSetting::set(self::KEY, $custom);

        $this->runMigration();

        $this->assertSame($custom, PlatformSetting::get(self::KEY));
    }

    public function test_running_the_migration_repeatedly_is_safe(): void
    {
        PlatformSetting::set(self::KEY, self::LEGACY);

        $this->runMigration();
        $this->runMigration();

        $this->assertSame(self::CANONICAL, PlatformSetting::get(self::KEY));
    }

    public function test_new_default_uses_jewelflows_when_no_row_exists(): void
    {
        // No maintenance_message row: the code default must already be canonical.
        PlatformSetting::set('maintenance_mode', 'true');
        $this->runMigration();

        $this->assertNull(PlatformSetting::where('key', self::KEY)->value('value'));

        $response = (new CheckMaintenanceMode())->handle(
            Request::create('/dashboard', 'GET'),
            fn () => response('next')
        );

        $this->assertSame(503, $response->getStatusCode());
        // Blade escapes the apostrophe in "We'll"; assert the brand-bearing head.
        $this->assertStringContainsString('JewelFlows is temporarily down for maintenance.', $response->getContent());
        $this->assertStringNotContainsString('JewelFlow is temporarily down for maintenance.', $response->getContent());
    }
}
