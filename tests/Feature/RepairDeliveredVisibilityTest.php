<?php

namespace Tests\Feature;

use App\Models\Repair;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Restoration M14 (audit H4): the repair index hard-excluded 'delivered', so
 * repair history vanished after billing (yet a Delivered KPI chip + a dead
 * "View Invoice" branch existed). The index now respects ?status=, revealing
 * delivered repairs, and the Delivered KPI links to that filter.
 */
class RepairDeliveredVisibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function makeRepair(int $shopId, string $status): Repair
    {
        $customer = $this->createCustomer($shopId);

        // A 'delivered' repair must carry an invoice_id (model invariant).
        $invoiceId = null;
        if ($status === 'delivered') {
            $inv = new \App\Models\Invoice();
            $inv->forceFill([
                'shop_id' => $shopId, 'invoice_number' => 'INV-RP-' . uniqid(),
                'customer_id' => $customer->id, 'gold_rate' => 6000,
                'subtotal' => 500, 'gst' => 15, 'total' => 515, 'status' => 'finalized',
            ])->save();
            $invoiceId = $inv->id;
        }

        $r = new Repair();
        $r->forceFill([
            'shop_id' => $shopId,
            'customer_id' => $customer->id,
            'repair_number' => random_int(100000, 999999999),
            'item_description' => 'Ring',
            'gross_weight' => 5.0,
            'status' => $status,
            'invoice_id' => $invoiceId,
        ])->save();
        return $r;
    }

    public function test_default_index_hides_delivered_but_status_filter_reveals_it(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $active = $this->makeRepair($shop->id, 'in_repair');
        $delivered = $this->makeRepair($shop->id, 'delivered');

        // Mirror the controller's query logic, in tenant context.
        $defaultIds = TenantContext::runFor($shop->id, fn () =>
            Repair::where('shop_id', $shop->id)->whereNotIn('status', ['delivered'])->pluck('id')->all()
        );
        $this->assertContains($active->id, $defaultIds);
        $this->assertNotContains($delivered->id, $defaultIds, 'default view excludes delivered');

        $deliveredIds = TenantContext::runFor($shop->id, fn () =>
            Repair::where('shop_id', $shop->id)->where('status', 'delivered')->pluck('id')->all()
        );
        $this->assertContains($delivered->id, $deliveredIds, 'status=delivered filter reveals delivered repairs');
    }

    public function test_index_view_links_delivered_kpi_to_status_filter(): void
    {
        $blade = file_get_contents(resource_path('views/repairs.blade.php'));
        $this->assertStringContainsString("route('repairs.index', ['status' => 'delivered'])", $blade,
            'the Delivered KPI must link to the delivered filter');
    }

    public function test_controller_respects_status_param(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/RepairController.php'));
        $this->assertMatchesRegularExpression(
            "/filled\('status'\).*?where\('status'.*?else.*?whereNotIn\('status', \['delivered'\]\)/s",
            $src,
            'index must filter by ?status= and only default-exclude delivered'
        );
    }
}
