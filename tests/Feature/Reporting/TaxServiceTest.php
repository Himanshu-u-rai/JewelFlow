<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Reporting\ReportPeriod;
use App\Reporting\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * CA tax pack (Phase 2 M1): GSTR-1 B2B/B2CS split, GSTR-3B net liability, and
 * the credit-note register — all must reconcile to the canonical GST summary.
 */
class TaxServiceTest extends TestCase
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

    private function invoice(int $shopId, int $customerId, array $attrs): int
    {
        return (int) DB::table('invoices')->insertGetId(array_merge([
            'shop_id'        => $shopId,
            'customer_id'    => $customerId,
            'invoice_number' => 'INV-' . fake()->unique()->numerify('######'),
            'gold_rate'      => 7200,
            'subtotal'       => 0, 'discount' => 0, 'gst' => 0, 'gst_rate' => 3, 'total' => 0,
            'status'         => Invoice::STATUS_FINALIZED,
            'created_at'     => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00',
            'finalized_at'   => '2026-03-15 10:00:00',
        ], $attrs));
    }

    private function seedTaxFixture(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        // B2B (registered buyer)
        $invA = $this->invoice($shop->id, $customer->id, [
            'buyer_gstin' => '27ABCDE1234F1Z5', 'place_of_supply_state_code' => '27',
            'subtotal' => 100000, 'gst' => 3000, 'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0, 'total' => 103000,
        ]);
        // B2CS (consumer)
        $this->invoice($shop->id, $customer->id, [
            'subtotal' => 50000, 'gst' => 1500, 'cgst_amount' => 750, 'sgst_amount' => 750, 'igst_amount' => 0, 'total' => 51500,
        ]);
        // Draft — excluded
        $this->invoice($shop->id, $customer->id, [
            'status' => Invoice::STATUS_DRAFT, 'finalized_at' => null, 'subtotal' => 999999, 'gst' => 99999, 'total' => 1099998,
        ]);

        // Credit note against INV-A
        $ro = (int) DB::table('return_orders')->insertGetId([
            'shop_id' => $shop->id, 'invoice_id' => $invA, 'return_type' => 'customer_return', 'status' => 'settled',
            'created_by_user_id' => $user->id, 'created_at' => '2026-03-20', 'updated_at' => '2026-03-20',
        ]);
        DB::table('credit_notes')->insert([
            'shop_id' => $shop->id, 'return_order_id' => $ro, 'invoice_id' => $invA, 'customer_id' => $customer->id,
            'credit_note_sequence' => 1, 'credit_note_number' => 'CN-001',
            'subtotal' => 20000, 'gst' => 600, 'gst_rate' => 3, 'cgst_amount' => 300, 'sgst_amount' => 300, 'igst_amount' => 0, 'total' => 20600,
            'status' => 'issued', 'issued_at' => '2026-03-20 10:00:00', 'issued_by_user_id' => $user->id,
            'created_at' => '2026-03-20 10:00:00', 'updated_at' => '2026-03-20 10:00:00',
        ]);

        return [$user, $shop];
    }

    public function test_gstr1_splits_b2b_and_b2cs_and_reconciles(): void
    {
        [$user, $shop] = $this->seedTaxFixture();
        $data = app(TaxService::class)->gstr1($shop->id, ReportPeriod::month(self::YEAR, self::MONTH));

        $this->assertCount(1, $data->b2b, 'one registered-buyer invoice');
        $this->assertCount(1, $data->b2cs, 'one B2CS rate/place group');
        $this->assertEqualsWithDelta(150000.0, $data->taxable, 0.01);
        $this->assertEqualsWithDelta(4500.0, $data->totalGst, 0.01);
        $this->assertEqualsWithDelta(600.0, $data->cnGst, 0.01);

        // B2B + B2CS taxable must equal the report taxable.
        $this->assertEqualsWithDelta(150000.0, (float) $data->b2b->sum('taxable') + (float) $data->b2cs->sum('taxable'), 0.01);
    }

    public function test_gstr3b_net_is_output_minus_returns(): void
    {
        [$user, $shop] = $this->seedTaxFixture();
        $data = app(TaxService::class)->gstr3b($shop->id, ReportPeriod::month(self::YEAR, self::MONTH));

        $this->assertEqualsWithDelta(4500.0, $data->outwardGst, 0.01);
        $this->assertEqualsWithDelta(600.0, $data->cnGst, 0.01);
        $this->assertEqualsWithDelta(3900.0, $data->netGst, 0.01);
    }

    public function test_credit_note_register_classifies_type(): void
    {
        [$user, $shop] = $this->seedTaxFixture();
        $data = app(TaxService::class)->creditNoteRegister($shop->id, ReportPeriod::month(self::YEAR, self::MONTH));

        $this->assertSame(1, $data->count);
        $this->assertEqualsWithDelta(600.0, $data->totalGst, 0.01);
        $this->assertSame('partial_return', $data->rows->first()->cn_type);
    }

    public function test_screens_and_exports_render(): void
    {
        [$user, $shop] = $this->seedTaxFixture();
        $this->grant($user, 'reports.view');
        $q = ['month' => self::MONTH, 'year' => self::YEAR];

        // Screens served by the spine (report-document architecture).
        // CSV exports are covered against the spine format by TaxExportGoldenTest
        // (the legacy *.csv routes were retired in Phase 3 Cleanup #1).
        $this->actingAs($user)->get(route('report.gstr1', $q))->assertOk()->assertSee('GSTR-1', false)->assertDontSee('window.print');
        $this->actingAs($user)->get(route('report.gstr3b', $q))->assertOk()->assertSee('Net tax liability', false);
        $this->actingAs($user)->get(route('report.cn-register', $q))->assertOk()->assertSee('CN-001', false);
    }

    public function test_reports_validate_passes_with_tax_pack_checks(): void
    {
        [$user, $shop] = $this->seedTaxFixture();

        $this->artisan('reports:validate', ['--shop' => $shop->id, '--month' => self::MONTH, '--year' => self::YEAR])
            ->assertExitCode(0);
    }
}
