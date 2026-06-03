<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Reporting\AuditService;
use App\Reporting\GstReportingService;
use App\Reporting\ReportPeriod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Operator performance (#16). Locks: (a) the seller is captured at invoice
 * creation, (b) sales group by operator and returns by CN issuer, (c) legacy
 * NULL-operator invoices bucket as "Unattributed" so the sales total still
 * reconciles to the canonical GST total sales.
 */
class OperatorPerfReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const YEAR = 2026;
    private const MONTH = 3;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function invoice(int $shopId, ?int $userId, float $total, float $discount = 0): int
    {
        $customerId = $this->createCustomer($shopId)->id;
        // Satisfy the accounting guard: total = subtotal + gst − discount.
        return (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId, 'user_id' => $userId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => $total + $discount, 'discount' => $discount, 'gst' => 0, 'gst_rate' => 0,
            'total' => $total, 'status' => Invoice::STATUS_FINALIZED,
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00', 'finalized_at' => '2026-03-15 10:00:00',
        ]);
    }

    public function test_seller_captured_at_invoice_creation(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $customerId = $this->createCustomer($shop->id)->id;
        $this->actingAs($owner);

        $inv = TenantContext::runFor($shop->id, function () use ($shop, $customerId) {
            $i = new Invoice();
            $i->forceFill([
                'shop_id' => $shop->id, 'customer_id' => $customerId,
                'invoice_number' => 'INV-CREATE-1', 'gold_rate' => 7200,
                'subtotal' => 1000, 'gst' => 0, 'total' => 1000, 'status' => Invoice::STATUS_DRAFT,
            ]); // user_id deliberately NOT set — the creating hook must fill it
            $i->save();
            return $i;
        });

        $this->assertSame((int) $owner->id, (int) Invoice::withoutGlobalScopes()->find($inv->id)->user_id,
            'invoice records the creating operator via the model hook');
    }

    public function test_grouping_unattributed_and_reconciles_to_gst_sales(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        DB::table('users')->where('id', $owner->id)->update(['name' => 'Owner Olivia']);
        $staff = DB::table('users')->insertGetId([
            'name' => 'Cashier Sam', 'mobile_number' => '9876500077', 'password' => Hash::make('Secret123!'),
            'shop_id' => $shop->id, 'role_id' => $owner->role_id, 'is_active' => DB::raw('true'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->invoice($shop->id, $owner->id, 10000, 500);
        $this->invoice($shop->id, $owner->id, 20000, 0);
        $this->invoice($shop->id, $staff, 15000, 200);
        $this->invoice($shop->id, null, 5000, 0);            // legacy → Unattributed

        $period = ReportPeriod::month(self::YEAR, self::MONTH);
        $data = TenantContext::runFor($shop->id, fn () => app(AuditService::class)->operatorPerformance($shop->id, $period));

        $this->assertSame(3, $data->operatorCount, 'owner, staff, unattributed');
        $this->assertEqualsWithDelta(50000.0, $data->totalSales, 0.01);
        $this->assertNotNull($data->rows->firstWhere('operator_name', 'Unattributed'));

        $owners = $data->rows->firstWhere('operator_name', 'Owner Olivia');
        $this->assertSame(2, $owners->invoice_count);
        $this->assertEqualsWithDelta(30000.0, $owners->total_sales, 0.01);

        // OP-1 — operator sales total reconciles to canonical GST total sales.
        $gst = TenantContext::runFor($shop->id, fn () => app(GstReportingService::class)->summary($shop->id, $period));
        $this->assertEqualsWithDelta($gst->totalSales, $data->totalSales, 0.01,
            'operator sales total must equal canonical GST total sales (incl. unattributed)');
    }
}
