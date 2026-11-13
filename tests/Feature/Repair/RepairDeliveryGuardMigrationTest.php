<?php

namespace Tests\Feature\Repair;

use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * CONSTITUTION §4 Rule 2: a migration that installs a trigger first counts the rows that already break its rule and
 * stops, naming them, instead of installing a guard over bad data. The repairs delivery guard (audit H1) missed it.
 */
class RepairDeliveryGuardMigrationTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const MIGRATION = 'database/migrations/2026_11_13_100000_add_repairs_delivery_guard_trigger.php';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_the_guard_refuses_to_install_over_an_open_repair_that_carries_an_invoice(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        $this->actingAs($owner);
        $invoiceId = TenantContext::runFor($shop->id, fn () => \App\Services\InvoiceAccountingService::createFinalized([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'gold_rate' => 0, 'subtotal' => 100, 'gst' => 0,
            'gst_rate' => 0, 'wastage_charge' => 0, 'total' => 100, 'round_off' => 0,
        ])->id);

        $migration = require base_path(self::MIGRATION);
        $migration->down();
        $bad = DB::table('repairs')->insertGetId([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'repair_number' => 999001, 'item_description' => 'x',
            'gross_weight' => 1, 'status' => 'ready', 'invoice_id' => $invoiceId, 'metal_type' => 'gold',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $refused = null;
        try {
            $migration->up();
        } catch (\RuntimeException $e) {
            $refused = $e;
        }
        $this->assertNotNull($refused, 'The guard was installed over a repair that breaks it.');
        $this->assertStringContainsString("repair ids: {$bad}", $refused->getMessage());

        // Legacy rows delivered before invoices were linked are allowed: the guard only stops new ones.
        DB::table('repairs')->where('id', $bad)->update(['status' => 'delivered', 'invoice_id' => null]);
        $migration->up();
        $this->assertSame(1, DB::selectOne("select count(*) as n from pg_trigger where tgname = 'repairs_delivery_guard_trigger'")->n);
    }
}
