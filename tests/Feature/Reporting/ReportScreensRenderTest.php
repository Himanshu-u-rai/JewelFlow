<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Pilot trust check: every Phase-2 report SCREEN must actually render (HTTP 200)
 * for an authorised owner. Unit tests lock the service numbers and the blades
 * compile, but only a real request catches blade-runtime errors, view-data
 * mismatches, and permission/edition wiring gaps. Read-only.
 */
class ReportScreensRenderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    #[DataProvider('reportRoutes')]
    public function test_report_screen_renders(string $routeName): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $role = Role::withoutGlobalScopes()->findOrFail($owner->role_id);
        foreach (['reports.view', 'reports.daily_closing'] as $name) {
            $perm = Permission::firstOrCreate(['name' => $name], ['display_name' => $name, 'group' => 'reports']);
            $role->permissions()->syncWithoutDetaching([$perm->id]);
        }

        $this->actingAs($owner)
            ->get(route($routeName))
            ->assertOk();
    }

    public static function reportRoutes(): array
    {
        // Keyed by route name so a failure reads "report.stock-aging" rather
        // than "data set #25", and so a single screen can be run in isolation.
        $routes = [
            // Reports hub (grouped landing)
            'report.hub',
            // M1 CA Tax Pack
            'report.gstr1', 'report.gstr3b', 'report.cn-register',
            // M2 Ledger & Reconciliation
            'report.payment-reconciliation', 'report.day-book', 'report.inventory-valuation',
            // M3 Receivables & Liability
            'report.dues-aging', 'report.emi', 'report.scheme-liability', 'report.metal-liability',
            // M4 Operational
            'report.dead-stock', 'report.karigar-settlement', 'report.purchase-efficiency', 'report.operator-performance',
            'report.suspicious-activity', 'report.shrinkage',
            // Legacy / existing reports (full-surface render coverage)
            'report.gst', 'report.pnl', 'report.gold', 'report.daily', 'report.cash',
            'report.closing', 'report.repairs', 'report.metal-exchange',
            // Retailer-only screens. Absent from this list until stock-aging 500'd
            // in production on a blade lookup for a bucket that was never a bucket.
            'report.stock-aging', 'report.sellers', 'report.occasions',
        ];

        return array_combine($routes, array_map(fn ($r) => [$r], $routes));
    }
}
