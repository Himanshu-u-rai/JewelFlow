<?php

namespace Tests\Feature\Reporting;

use App\Models\Invoice;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * M6 golden-file snapshot tests for the 🔴 CA tax EXPORTS (GSTR-1, CN register).
 * These CSVs are what a CA files from, so a silent column/value/format
 * regression is the highest-consequence reporting failure — runtime invariants
 * don't catch export SHAPE drift. We capture the streamed CSV, strip the BOM,
 * parse rows back, and lock the section schemas + exact data rows for a fully
 * deterministic fixture.
 */
class TaxExportGoldenTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** @return array{0:\App\Models\User,1:\App\Models\Shop} */
    private function seedGoldenFixture(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $role = Role::withoutGlobalScopes()->findOrFail($user->role_id);
        $perm = Permission::firstOrCreate(['name' => 'reports.view'], ['display_name' => 'View Reports', 'group' => 'reports']);
        $role->permissions()->syncWithoutDetaching([$perm->id]);

        $customer = $this->createCustomer($shop->id);

        $base = [
            'shop_id' => $shop->id, 'customer_id' => $customer->id,
            'gold_rate' => 7200, 'discount' => 0, 'gst_rate' => 3,
            'status' => Invoice::STATUS_FINALIZED,
            'created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00', 'finalized_at' => '2026-03-15 10:00:00',
        ];

        $invB2b = (int) DB::table('invoices')->insertGetId(array_merge($base, [
            'invoice_number' => 'INV-GOLD-B2B', 'buyer_gstin' => '27ABCDE1234F1Z5', 'place_of_supply_state_code' => '27',
            'subtotal' => 100000, 'gst' => 3000, 'cgst_amount' => 1500, 'sgst_amount' => 1500, 'igst_amount' => 0, 'total' => 103000,
        ]));
        DB::table('invoices')->insert(array_merge($base, [
            'invoice_number' => 'INV-GOLD-B2CS',
            'subtotal' => 50000, 'gst' => 1500, 'cgst_amount' => 750, 'sgst_amount' => 750, 'igst_amount' => 0, 'total' => 51500,
        ]));

        $ro = (int) DB::table('return_orders')->insertGetId([
            'shop_id' => $shop->id, 'invoice_id' => $invB2b, 'return_type' => 'customer_return', 'status' => 'settled',
            'created_by_user_id' => $user->id, 'created_at' => '2026-03-20', 'updated_at' => '2026-03-20',
        ]);
        DB::table('credit_notes')->insert([
            'shop_id' => $shop->id, 'return_order_id' => $ro, 'invoice_id' => $invB2b, 'customer_id' => $customer->id,
            'credit_note_sequence' => 1, 'credit_note_number' => 'CN-GOLD-001',
            'subtotal' => 20000, 'gst' => 600, 'gst_rate' => 3, 'cgst_amount' => 300, 'sgst_amount' => 300, 'igst_amount' => 0, 'total' => 20600,
            'status' => 'issued', 'issued_at' => '2026-03-20 10:00:00', 'issued_by_user_id' => $user->id,
            'created_at' => '2026-03-20 10:00:00', 'updated_at' => '2026-03-20 10:00:00',
        ]);

        return [$user, $shop];
    }

    /** Capture a streamed CSV response, strip the BOM, parse into rows. */
    private function csvRows(\Illuminate\Testing\TestResponse $response): array
    {
        $response->assertOk();
        $content = $response->streamedContent();
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // drop UTF-8 BOM
        $lines = explode("\n", rtrim($content, "\n"));
        return array_map(fn ($l) => str_getcsv($l), $lines);
    }

    public function test_gstr1_export_is_byte_stable(): void
    {
        [$owner] = $this->seedGoldenFixture();

        $rows = $this->csvRows(
            $this->actingAs($owner)->get(route('report.gstr1.csv', ['month' => 3, 'year' => 2026]))
        );

        // Section schemas a CA's tooling depends on — must not drift.
        $this->assertContains(['== B2B (registered buyers) =='], $rows);
        $this->assertContains(['Invoice', 'Date', 'Buyer GSTIN', 'Place of Supply', 'Rate %', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoice Total'], $rows);
        $this->assertContains(['== B2CS (consumers) =='], $rows);
        $this->assertContains(['Rate %', 'Place of Supply', 'Taxable', 'CGST', 'SGST', 'IGST', 'Total GST', 'Invoices'], $rows);
        $this->assertContains(['== HSN Summary =='], $rows);

        // Exact data rows.
        $this->assertContains(
            ['INV-GOLD-B2B', '2026-03-15', '27ABCDE1234F1Z5', '27', '3.00', '100000.00', '1500.00', '1500.00', '0.00', '3000.00', '103000.00'],
            $rows,
            'B2B line must export exactly'
        );
        $this->assertContains(
            ['3.00', '', '50000.00', '750.00', '750.00', '0.00', '1500.00', '1'],
            $rows,
            'B2CS rate-group line must export exactly'
        );
    }

    public function test_cn_register_export_is_byte_stable(): void
    {
        [$owner] = $this->seedGoldenFixture();

        $rows = $this->csvRows(
            $this->actingAs($owner)->get(route('report.cn-register.csv', ['month' => 3, 'year' => 2026]))
        );

        $this->assertContains(
            ['CN Number', 'Date', 'Type', 'Original Invoice', 'Original Invoice Date', 'Customer', 'Rate %', 'Taxable', 'CGST', 'SGST', 'IGST', 'GST', 'CN Total'],
            $rows,
            'CN register header schema must not drift'
        );

        $cnRow = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'CN-GOLD-001');
        $this->assertNotNull($cnRow, 'the credit note must appear');
        $this->assertSame('INV-GOLD-B2B', $cnRow[3], 'original invoice reference');
        $this->assertSame('20000.00', $cnRow[7], 'taxable');
        $this->assertSame('300.00', $cnRow[8], 'cgst');
        $this->assertSame('300.00', $cnRow[9], 'sgst');
        $this->assertSame('600.00', $cnRow[11], 'gst');
        $this->assertSame('20600.00', $cnRow[12], 'cn total');
    }
}
