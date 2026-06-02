<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M9 (audit P2): bulk-import routes were gated on `reports.export`,
 * so an export-reports role could bulk-create inventory while a dedicated
 * imports role could not import. They are now gated on the purpose-built
 * `imports.manage` (held by owner+manager); `reports.export` stays on the
 * export routes only.
 */
class ImportPermissionGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_all_import_routes_use_imports_manage_not_reports_export(): void
    {
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || ! str_starts_with($name, 'imports.')) {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $this->assertContains('can:imports.manage', $mw, "{$name} must be gated on imports.manage");
            $this->assertNotContains('can:reports.export', $mw, "{$name} must NOT be gated on reports.export");
        }
    }

    public function test_export_routes_still_use_reports_export(): void
    {
        $route = Route::getRoutes()->getByName('export.customers');
        $this->assertContains('can:reports.export', $route->gatherMiddleware(),
            'export routes must keep reports.export');
    }

    public function test_imports_manage_permission_exists(): void
    {
        $this->assertDatabaseHas('permissions', ['name' => 'imports.manage']);
    }
}
