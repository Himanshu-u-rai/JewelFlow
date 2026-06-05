<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Reporting\GstReportingService;
use App\Reporting\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Locks the GST report totals against persisted accounting data and proves the
 * canonical "what counts as a sale" definition (audit A1/A3/A4/A7).
 *
 * Fixture for a fixed period (March 2026):
 *   - INV-A  finalized  taxable 100000, gst 3000 (cgst/sgst 1500/1500)  ← counted
 *   - INV-B  finalized  taxable  50000, gst 1500 (cgst/sgst  750/ 750)  ← counted
 *   - INV-DRAFT     draft      huge numbers                              ← excluded
 *   - INV-CANCELLED cancelled  huge numbers                             ← excluded
 *   - INV-FEB  finalized but finalized_at in Feb                        ← excluded (period)
 *   - CN-1   credit note gst 600 issued in March                       ← reverses
 *
 * Expected: gst collected 4500, net liability 3900, cgst/sgst 2250/2250.
 */
class GstReportingTest extends TestCase
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
        foreach ($permissions as $permission) {
            $role->givePermission($permission);
        }
    }

    private function insertInvoice(int $shopId, int $customerId, array $attrs): int
    {
        return (int) DB::table('invoices')->insertGetId(array_merge([
            'shop_id'        => $shopId,
            'customer_id'    => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate'      => 7200,
            'subtotal'       => 0,
            'discount'       => 0,
            'gst'            => 0,
            'gst_rate'       => 3,
            'total'          => 0,
            'status'         => Invoice::STATUS_FINALIZED,
            'created_at'     => "2026-03-15 10:00:00",
            'updated_at'     => "2026-03-15 10:00:00",
            'finalized_at'   => "2026-03-15 10:00:00",
        ], $attrs));
    }

    private function insertCreditNote(int $shopId, int $invoiceId, int $customerId, int $userId, array $attrs): void
    {
        $returnOrderId = (int) DB::table('return_orders')->insertGetId([
            'shop_id'             => $shopId,
            'invoice_id'          => $invoiceId,
            'return_type'         => 'customer_return',
            'status'              => 'settled',
            'created_by_user_id'  => $userId,
            'created_at'          => '2026-03-20 10:00:00',
            'updated_at'          => '2026-03-20 10:00:00',
        ]);

        DB::table('credit_notes')->insert(array_merge([
            'shop_id'              => $shopId,
            'return_order_id'      => $returnOrderId,
            'invoice_id'           => $invoiceId,
            'customer_id'          => $customerId,
            'credit_note_sequence' => 1,
            'credit_note_number'   => 'CN-' . fake()->unique()->numerify('#####'),
            'subtotal'             => 0,
            'gst'                  => 0,
            'gst_rate'             => 3,
            'total'                => 0,
            'status'               => 'issued',
            'issued_at'            => '2026-03-20 10:00:00',
            'issued_by_user_id'    => $userId,
            'created_at'           => '2026-03-20 10:00:00',
            'updated_at'           => '2026-03-20 10:00:00',
        ], $attrs));
    }

    private function seedFixture(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $invA = $this->insertInvoice($shop->id, $customer->id, [
            'subtotal' => 100000, 'gst' => 3000, 'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0, 'total' => 103000,
        ]);
        $this->insertInvoice($shop->id, $customer->id, [
            'subtotal' => 50000, 'gst' => 1500, 'cgst_amount' => 750, 'sgst_amount' => 750, 'igst_amount' => 0, 'total' => 51500,
        ]);

        // Excluded: draft + cancelled + out-of-period.
        $this->insertInvoice($shop->id, $customer->id, [
            'status' => Invoice::STATUS_DRAFT, 'subtotal' => 999999, 'gst' => 99999, 'total' => 1099998, 'finalized_at' => null,
        ]);
        $this->insertInvoice($shop->id, $customer->id, [
            'status' => Invoice::STATUS_CANCELLED, 'subtotal' => 888888, 'gst' => 88888, 'total' => 977776,
        ]);
        $this->insertInvoice($shop->id, $customer->id, [
            'subtotal' => 70000, 'gst' => 2100, 'total' => 72100,
            'created_at' => '2026-02-15 10:00:00', 'updated_at' => '2026-02-15 10:00:00', 'finalized_at' => '2026-02-15 10:00:00',
        ]);

        // Credit note (return) in March against INV-A.
        $this->insertCreditNote($shop->id, $invA, $customer->id, $user->id, [
            'subtotal' => 20000, 'gst' => 600, 'cgst_amount' => 300, 'sgst_amount' => 300, 'igst_amount' => 0, 'total' => 20600,
        ]);

        return [$user, $shop];
    }

    public function test_gst_totals_count_only_finalized_in_period_and_net_of_returns(): void
    {
        [$user, $shop] = $this->seedFixture();

        $data = app(GstReportingService::class)->summary($shop->id, ReportPeriod::month(self::YEAR, self::MONTH));

        $this->assertSame(2, $data->invoiceCount, 'draft, cancelled, and out-of-period invoices must be excluded');
        $this->assertEqualsWithDelta(150000.0, $data->taxableAmount, 0.01);
        $this->assertEqualsWithDelta(4500.0, $data->gstCollected, 0.01);
        $this->assertEqualsWithDelta(2250.0, $data->cgstCollected, 0.01);
        $this->assertEqualsWithDelta(2250.0, $data->sgstCollected, 0.01);
        $this->assertEqualsWithDelta(0.0, $data->igstCollected, 0.01);

        // Net of credit notes (A1).
        $this->assertSame(1, $data->cnCount);
        $this->assertEqualsWithDelta(600.0, $data->cnGstReversed, 0.01);
        $this->assertEqualsWithDelta(20000.0, $data->cnTaxableReversed, 0.01);
        $this->assertEqualsWithDelta(3900.0, $data->netGstLiability, 0.01, 'net = 4500 collected − 600 reversed');
    }

    public function test_canonical_sale_scope_excludes_draft_cancelled_and_other_periods(): void
    {
        [$user, $shop] = $this->seedFixture();

        $count = Invoice::withoutTenant()
            ->where('shop_id', $shop->id)
            ->salesIn(ReportPeriod::month(self::YEAR, self::MONTH))
            ->count();

        $this->assertSame(2, $count);
    }

    public function test_gst_report_pages_render_on_the_spine(): void
    {
        // Post Phase-2 sign-off: report.gst now serves the spine GST Summary;
        // net liability and credit-note detail live on GSTR-3B and the Credit
        // Note Register. The legacy ?month=&year= bookmark still works.
        [$user, $shop] = $this->seedFixture();
        $this->grant($user, 'reports.view');
        $q = ['month' => self::MONTH, 'year' => self::YEAR];

        $this->actingAs($user)->get(route('report.gst', $q))
            ->assertOk()->assertSee('1,50,000', false)->assertDontSee('window.print');

        $this->actingAs($user)->get(route('report.gstr3b', $q))
            ->assertOk()->assertSee('Net tax liability', false);

        $this->actingAs($user)->get(route('report.cn-register', $q))->assertOk();
    }

    public function test_reports_validate_command_passes_on_consistent_data(): void
    {
        [$user, $shop] = $this->seedFixture();

        $this->artisan('reports:validate', [
            '--shop'  => $shop->id,
            '--month' => self::MONTH,
            '--year'  => self::YEAR,
        ])->assertExitCode(0);
    }
}
