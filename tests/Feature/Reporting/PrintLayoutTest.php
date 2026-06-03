<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M7 UX: every report must be printable for the CA. The layout ships a global
 * @media print stylesheet + a per-report Print button + a print-only
 * letterhead. This pins that wiring so it isn't silently dropped.
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

    public function test_report_screen_has_print_button_and_letterhead(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $role = Role::withoutGlobalScopes()->findOrFail($owner->role_id);
        $perm = Permission::firstOrCreate(['name' => 'reports.view'], ['display_name' => 'View Reports', 'group' => 'reports']);
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        $html = $this->actingAs($owner)->get(route('report.operator-performance'))->assertOk()->getContent();

        // Print button (triggers browser print of the chrome-hidden report).
        $this->assertStringContainsString('window.print()', $html);
        // Print-only letterhead carries the shop name.
        $this->assertStringContainsString('print-only', $html);
        $this->assertStringContainsString($shop->name, $html);
        // The @media print rule that hides app chrome is present.
        $this->assertStringContainsString('@media print', $html);
    }
}
