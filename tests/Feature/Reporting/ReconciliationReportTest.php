<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Reporting\InventoryService;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Reporting\SalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * CA Ledger & Reconciliation pack (Phase 2 M2): payment reconciliation,
 * day-book, inventory valuation. Surfaces mismatches and reconciles to the
 * canonical sale scope.
 */
class ReconciliationReportTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    private const YEAR = 2026;
    private const MONTH = 3;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function grant(\App\Models\User $user, string ...$permissions): void
    {
        $role = \App\Models\Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($permissions as $p) {
            $role->givePermission($p);
        }
    }

    private function invoice(int $shopId, int $customerId, float $subtotal, float $gst): int
    {
        return (int) DB::table('invoices')->insertGetId([
            'shop_id' => $shopId, 'customer_id' => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate' => 7200, 'subtotal' => $subtotal, 'discount' => 0, 'gst' => $gst,
            'gst_rate' => 3, 'total' => $subtotal + $gst, 'status' => Invoice::STATUS_FINALIZED,
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00', 'finalized_at' => '2026-03-15 10:00:00',
        ]);
    }

    private function pay(int $shopId, int $invoiceId, float $amount, string $mode = 'cash'): void
    {
        DB::table('invoice_payments')->insert([
            'shop_id' => $shopId, 'invoice_id' => $invoiceId, 'mode' => $mode, 'amount' => $amount,
            'created_at' => '2026-03-15 10:05:00', 'updated_at' => '2026-03-15 10:05:00',
        ]);
    }

    private function seedPayments(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $c = $this->createCustomer($shop->id);

        $paid    = $this->invoice($shop->id, $c->id, 9700, 300);   // total 10000
        $partial = $this->invoice($shop->id, $c->id, 19400, 600);  // total 20000
        $this->invoice($shop->id, $c->id, 7760, 240);              // total 8000, unpaid
        $over    = $this->invoice($shop->id, $c->id, 4850, 150);   // total 5000

        $this->pay($shop->id, $paid, 10000);
        $this->pay($shop->id, $partial, 5000);
        $this->pay($shop->id, $over, 6000); // over-collected

        return [$user, $shop];
    }

    public function test_payment_reconciliation_surfaces_mismatches(): void
    {
        [$user, $shop] = $this->seedPayments();
        $data = app(SalesService::class)->paymentReconciliation($shop->id, ReportPeriod::month(self::YEAR, self::MONTH));

        $this->assertEqualsWithDelta(43000.0, $data->invoiceTotal, 0.01);
        $this->assertEqualsWithDelta(21000.0, $data->collected, 0.01);
        $this->assertSame(4, $data->invoiceCount);
        $this->assertSame(1, $data->fullyPaidCount);
        $this->assertSame(1, $data->partialCount);
        $this->assertSame(1, $data->unpaidCount);
        $this->assertSame(1, $data->overCollectedCount);
        $this->assertFalse($data->reconciled, 'over-collection must break reconciliation');
        // Mismatches = over_collected + unpaid.
        $this->assertCount(2, $data->mismatches);
        $this->assertEqualsWithDelta(21000.0, (float) $data->modeBreakdown->sum(), 0.01);
    }

    public function test_day_book_streams_events_chronologically(): void
    {
        [$user, $shop] = $this->seedPayments();
        // A cash transaction + a credit note in the period.
        DB::table('cash_transactions')->insert([
            'shop_id' => $shop->id, 'user_id' => $user->id, 'type' => 'in', 'amount' => 1000,
            'source_type' => 'manual', 'description' => 'opening float',
            'created_at' => '2026-03-16 09:00:00', 'updated_at' => '2026-03-16 09:00:00',
        ]);

        $data = app(LedgerService::class)->dayBook($shop->id, ReportPeriod::month(self::YEAR, self::MONTH));

        $this->assertEqualsWithDelta(43000.0, $data->salesTotal, 0.01, '4 finalized sales');
        $this->assertEqualsWithDelta(1000.0, $data->cashIn, 0.01);
        $this->assertGreaterThanOrEqual(5, $data->eventCount); // 4 sales + 1 cash
        // Sorted ascending by time.
        $times = $data->events->pluck('occurred_at')->map(fn ($t) => \Carbon\Carbon::parse($t)->timestamp)->all();
        $sorted = $times; sort($sorted);
        $this->assertSame($sorted, $times);
    }

    public function test_inventory_valuation_at_cost(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->createItem($shop->id, null, ['cost_price' => 50000, 'selling_price' => 60000, 'category' => 'Ring', 'metal_type' => 'gold']);
        $this->createItem($shop->id, null, ['cost_price' => 30000, 'selling_price' => 38000, 'category' => 'Ring', 'metal_type' => 'gold']);
        // A dead-stock item aged 200 days.
        $old = $this->createItem($shop->id, null, ['cost_price' => 20000, 'category' => 'Chain', 'metal_type' => 'gold']);
        DB::table('items')->where('id', $old->id)->update(['created_at' => now()->subDays(200)]);

        $data = app(InventoryService::class)->valuation($shop->id, 90);

        $this->assertEqualsWithDelta(100000.0, $data->totalAtCost, 0.01);
        $this->assertSame(3, $data->itemCount);
        $this->assertEqualsWithDelta(20000.0, $data->deadCapitalValue, 0.01);
        $this->assertSame(1, $data->deadCapitalCount);
        $this->assertEqualsWithDelta(100000.0, (float) $data->byMetal->firstWhere('metal_type', 'gold')->cost_value, 0.01);
    }

    public function test_screens_and_exports_render(): void
    {
        [$user, $shop] = $this->seedPayments();
        $this->grant($user, 'reports.view');
        $q = ['month' => self::MONTH, 'year' => self::YEAR];

        // Payment Reconciliation now served by the reporting spine (Phase 3); its
        // CSV is the spine export (POST /reports/payment-reconciliation/export),
        // exercised by PaymentReconciliationReportTest.
        $this->actingAs($user)->get(route('report.payment-reconciliation', $q))->assertOk()->assertSee('Payment Reconciliation', false);
        $this->actingAs($user)->get(route('report.day-book', $q))->assertOk()->assertSee('Day Book', false);
        $this->actingAs($user)->get(route('report.inventory-valuation'))->assertOk()->assertSee('Inventory Valuation', false);
    }

    public function test_reports_validate_passes_with_reconciliation_checks(): void
    {
        [$user, $shop] = $this->seedPayments();

        $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => self::MONTH, '--year' => self::YEAR])
            ->assertExitCode(0);
    }
}
