<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\QuickBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A walk-in typed on the app's quick bill (name + mobile, no saved customer)
 * is saved as a customer, once: the same number later — however it is written
 * — reuses that record. One mobile, one customer per shop.
 */
class MobileQuickBillWalkInCustomerTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_a_walk_in_with_a_mobile_is_saved_once_and_reused(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);

        $first = $this->postJson('/api/mobile/quick-bills', $this->payload('Suresh Kumar', '9876500001'))->assertCreated();
        $again = $this->postJson('/api/mobile/quick-bills', $this->payload('Suresh', '+91 98765 00001'))->assertCreated();

        $customers = Customer::withoutGlobalScopes()->where('shop_id', $shop->id)->where('mobile', '9876500001')->get();
        $this->assertCount(1, $customers);
        $this->assertSame('Suresh', $customers[0]->first_name);
        $this->assertSame('Kumar', $customers[0]->last_name);

        $billCustomers = QuickBill::withoutGlobalScopes()
            ->whereIn('id', [$first->json('quick_bill.id'), $again->json('quick_bill.id')])
            ->pluck('customer_id')->unique();
        $this->assertSame([$customers[0]->id], $billCustomers->values()->all());
    }

    public function test_a_walk_in_without_a_mobile_stays_a_name_on_the_bill(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($user);
        $before = Customer::withoutGlobalScopes()->where('shop_id', $shop->id)->count();

        $this->postJson('/api/mobile/quick-bills', $this->payload('Walk-in Ravi', ''))->assertCreated();

        $this->assertSame($before, Customer::withoutGlobalScopes()->where('shop_id', $shop->id)->count());
    }

    private function payload(string $name, string $mobile): array
    {
        return [
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'no_gst',
            'gst_rate' => 0,
            'round_off' => 0,
            'save_action' => 'issue',
            'customer_name' => $name,
            'customer_mobile' => $mobile !== '' ? $mobile : null,
            'items' => [['description' => 'Ring', 'pcs' => 1, 'gross_weight' => 1, 'net_weight' => 1, 'rate' => 1000]],
            'payments' => [['payment_mode' => 'cash', 'amount' => 1000]],
        ];
    }
}
