<?php

namespace Tests\Feature\Mobile;

use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/** Bill numbers now carry the financial year ("INV/26-27/1001"): the app's list must find and show them as written. */
class MobileInvoiceNumberWithYearTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    public function test_the_app_lists_and_finds_a_bill_by_its_year_numbered_number(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);
        foreach (['INV/26-27/1001', 'INV/26-27/1002', 'INV-0042'] as $number) {
            $bill = new Invoice();
            $bill->forceFill([
                'shop_id' => $shop->id, 'customer_id' => $customer->id, 'invoice_number' => $number,
                'gold_rate' => 7200, 'subtotal' => 100, 'gst' => 3, 'gst_rate' => 3, 'wastage_charge' => 0,
                'discount' => 0, 'round_off' => 0, 'total' => 103, 'status' => Invoice::STATUS_FINALIZED,
            ])->save();
        }
        Sanctum::actingAs($owner);

        $all = $this->getJson('/api/mobile/invoices')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['INV/26-27/1001', 'INV/26-27/1002', 'INV-0042'], array_column($all, 'invoice_number'));

        $found = $this->getJson('/api/mobile/invoices?search=' . rawurlencode('26-27/1002'))->assertOk()->json('data');
        $this->assertSame(['INV/26-27/1002'], array_column($found, 'invoice_number'));
    }
}
