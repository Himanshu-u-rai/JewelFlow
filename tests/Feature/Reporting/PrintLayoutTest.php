<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M7 UX: every report must be CA-presentable. The app layout ships a global
 * @media print stylesheet + a print-only letterhead carrying the shop name, so
 * a printed report is letterhead-stamped. On the reporting SPINE the per-report
 * browser-print button is retired in favour of the formal PDF export (frozen §4,
 * pinned by Phase2SignoffTest); this test pins the remaining global furniture
 * (letterhead + @media print) plus the spine Export entry point on a migrated
 * report (operator-performance is now on the spine).
 */
class PrintLayoutTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_report_screen_has_letterhead_and_export(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $role = Role::withoutGlobalScopes()->findOrFail($owner->role_id);
        // operator-performance is Audit class → needs the audit surface gate.
        $ids = Permission::whereIn('name', ['reports.view', 'reports.export', 'reports.audit'])->pluck('id');
        $role->permissions()->syncWithoutDetaching($ids);

        $html = $this->actingAs($owner)->get(route('report.operator-performance'))->assertOk()->getContent();

        // Print-only letterhead carries the shop name (global app-layout furniture).
        $this->assertStringContainsString('print-only', $html);
        $this->assertStringContainsString($shop->name, $html);
        // The @media print rule that hides app chrome is present.
        $this->assertStringContainsString('@media print', $html);
        // Spine convention: browser-print retired; the formal PDF export is the path.
        $this->assertStringNotContainsString('window.print()', $html);
        $this->assertStringContainsString('Export', $html);
    }
}
