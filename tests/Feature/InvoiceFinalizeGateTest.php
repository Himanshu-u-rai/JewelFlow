<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M8 (audit P1): finalize + cancel shared one route gated
 * `sales.void`, so a cashier with `sales.create` (but not `sales.void`) could
 * not finalize a drafted invoice. The route is now gated `sales.create`
 * (finalize is the common cashier action) and the cancel/void branch is gated
 * `sales.void` INSIDE the controller. The Edit button is status-aware gated.
 */
class InvoiceFinalizeGateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_update_route_is_gated_on_sales_create_not_void(): void
    {
        $route = Route::getRoutes()->getByName('invoices.update');
        $mw = $route->gatherMiddleware();
        $this->assertContains('can:sales.create', $mw, 'finalize must be reachable by cashiers (sales.create)');
        $this->assertNotContains('can:sales.void', $mw, 'the route itself must not require sales.void (that gates only the cancel branch)');
    }

    public function test_edit_route_is_gated_on_sales_create(): void
    {
        $route = Route::getRoutes()->getByName('invoices.edit');
        $this->assertContains('can:sales.create', $route->gatherMiddleware());
    }

    public function test_controller_enforces_sales_void_on_cancel_branch(): void
    {
        // Source-level guarantee that the cancel branch carries its own gate.
        $src = file_get_contents(app_path('Http/Controllers/InvoiceController.php'));
        $this->assertMatchesRegularExpression(
            '/\$action === \'cancel\'.*?can\(\'sales\.void\'\)/s',
            $src,
            'the cancel branch must abort_unless the user can sales.void'
        );
    }

    public function test_cancel_forms_are_void_gated_in_edit_view(): void
    {
        $blade = file_get_contents(resource_path('views/invoices/edit.blade.php'));
        // Both cancel forms (draft void + finalized reversal) sit behind @can('sales.void').
        $this->assertSame(2, substr_count($blade, "@can('sales.void')"),
            'both the Cancel-Draft and Cancel-via-Reversal forms must be void-gated');
    }
}
