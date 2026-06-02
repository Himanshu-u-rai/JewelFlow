<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Restoration M12 (audit F-SC): store-credit consumption (applyToInvoice) existed
 * and was correct, but had NO UI trigger on the invoice/billing screen — credit
 * could be issued and hand-adjusted but never applied to a sale. The invoice show
 * page now renders an "Apply Store Credit" form (gated sales.create) when the
 * finalized invoice has an outstanding balance and the customer has wallet credit.
 */
class StoreCreditApplyUiTest extends TestCase
{
    public function test_apply_route_exists_and_is_gated(): void
    {
        $this->assertTrue(Route::has('store-credit.apply'));
        $route = Route::getRoutes()->getByName('store-credit.apply');
        $this->assertContains('can:sales.create', $route->gatherMiddleware());
    }

    public function test_invoice_show_view_renders_the_apply_form(): void
    {
        $blade = file_get_contents(resource_path('views/invoices/show.blade.php'));
        $this->assertStringContainsString("route('store-credit.apply', \$invoice)", $blade,
            'invoice show must post to the store-credit apply endpoint');
        $this->assertStringContainsString('Apply Store Credit', $blade);
        // Gated and only shown when applicable.
        $this->assertStringContainsString("@can('sales.create')", $blade);
        $this->assertStringContainsString('storeCreditApplicable', $blade);
    }

    public function test_controller_supplies_store_credit_data(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/InvoiceController.php'));
        $this->assertStringContainsString('storeCreditApplicable', $src);
        $this->assertStringContainsString('StoreCreditService', $src);
    }
}
