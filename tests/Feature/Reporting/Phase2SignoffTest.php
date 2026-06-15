<?php

namespace Tests\Feature\Reporting;

use App\Models\Permission;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Phase 2 sign-off completion (Option B): the user-facing compliance report
 * routes now serve the spine screen — same URLs/names/permissions — and no
 * longer depend on browser print.
 */
class Phase2SignoffTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function actAsViewer()
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::whereIn('name', ['reports.view'])->pluck('id'));
        });
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        return [$owner, $shop];
    }

    public function test_legacy_report_urls_now_serve_the_spine_without_browser_print(): void
    {
        [$owner, $shop] = $this->actAsViewer();

        $reports = [
            'report.gst' => ['GST', true],
            'report.gstr1' => ['GSTR-1', true],
            'report.gstr3b' => ['GSTR-3B', true],
            'report.cn-register' => ['Credit Note', true],
            'report.day-book' => ['Day Book', false], // Accounting — not rigid
        ];

        foreach ($reports as $routeName => [$title, $rigid]) {
            $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get(route($routeName)));

            $response->assertOk();
            $response->assertSee($title);                       // reached the spine screen
            $response->assertDontSee('window.print');           // browser print retired
            $response->assertSee('Export');                     // spine export entry point
            if ($rigid) {
                $response->assertSee('fixed statutory format');  // compliance rigidity surfaced
            } else {
                $response->assertDontSee('fixed statutory format');
            }
        }
    }

    public function test_permission_still_required(): void
    {
        // A user WITHOUT reports.view cannot open the repointed route. The shared
        // tenant factory now gives the owner the full permission set (like a real
        // owner), so revoke reports.view to exercise the denial path.
        [$owner, $shop] = $this->createManufacturerTenant();
        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->revokePermission('reports.view');
        });
        $owner->unsetRelation('role');
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get(route('report.gst')));
        $response->assertForbidden();
    }
}
