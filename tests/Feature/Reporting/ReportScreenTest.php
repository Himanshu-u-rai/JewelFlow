<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\Permission;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The generic spine screen (ReportScreenController) renders every registered
 * report through one path. Compliance reports show the fixed-format note and no
 * profile selector; the flexible report keeps its selector.
 */
class ReportScreenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function seedFinalizedSale(int $shopId, int $customerId): void
    {
        $id = (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => 100000, 'discount' => 0, 'gst' => 3000, 'gst_rate' => 3,
            'total' => 103000, 'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0,
            'buyer_gstin' => '29ABCDE1234F1Z5', 'place_of_supply_state_code' => '29',
            'status' => Invoice::STATUS_DRAFT, 'finalized_at' => null,
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
        ]);
        $item = $this->createItem($shopId);
        DB::table('invoice_items')->insert([
            'invoice_id' => $id, 'item_id' => $item->id, 'weight' => 10, 'rate' => 5000,
            'making_charges' => 0, 'stone_amount' => 0, 'line_total' => 100000, 'gst_rate' => 3,
            'gst_amount' => 3000, 'metal_type' => 'gold', 'hsn_code' => '7113',
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
        ]);
        DB::table('invoices')->where('id', $id)->update(['status' => Invoice::STATUS_FINALIZED, 'finalized_at' => '2026-03-15 10:00:00']);
    }

    public function test_generic_screen_renders_every_registered_report(): void
    {
        [$owner, $shop] = $this->createManufacturerTenant();
        $customerId = $this->createCustomer($shop->id)->id;
        $this->seedFinalizedSale($shop->id, $customerId);

        TenantContext::runFor((int) $shop->id, function () use ($owner) {
            $owner->role->permissions()->syncWithoutDetaching(Permission::whereIn('name', ['reports.view'])->pluck('id'));
        });

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureTenantUser::class,
            \App\Http\Middleware\EnsureSubscriptionIsActive::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            \App\Http\Middleware\EnsureShopExists::class,
        ]);

        $titles = [
            'gst' => 'GST', 'gstr1' => 'GSTR-1', 'gstr3b' => 'GSTR-3B',
            'cn-register' => 'Credit Note', 'day-book' => 'Day Book', 'sales-register' => 'Sales / Invoice Register',
        ];

        foreach ($titles as $key => $needle) {
            $response = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)
                ->get("/reporting/{$key}/screen?date_preset=custom&date_from=2026-03-01&date_to=2026-03-31"));

            $response->assertOk();
            $response->assertSee($needle);
        }
    }

    public function test_compliance_screen_is_rigid_flexible_is_not(): void
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

        $gst = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/reporting/gst/screen'));
        $gst->assertOk()->assertSee('fixed statutory format');

        $sales = TenantContext::runFor((int) $shop->id, fn () => $this->actingAs($owner)->get('/reporting/sales-register/screen'));
        $sales->assertOk()->assertDontSee('fixed statutory format');
    }
}
