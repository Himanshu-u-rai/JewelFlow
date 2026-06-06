<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\Mobile\CustomerController;
use App\Models\CustomerGoldTransaction;
use App\Models\LoyaltyTransaction;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Mobile customer context — additive personal fields, retailer loyalty history,
 * manufacturer gold balance/transactions.
 *
 * The controller method is invoked directly within TenantContext. No seeded/
 * authenticated users are needed: only a shop (for its edition + scoping) and an
 * in-memory User carrier that supplies shop_id / shop to the request.
 */
class CustomerContextTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    /** In-memory (unsaved) user carrying the shop context the controller reads. */
    private function userFor(Shop $shop): User
    {
        $user = new User();
        $user->forceFill(['shop_id' => $shop->id]);
        $user->setRelation('shop', $shop);

        return $user;
    }

    /** Invoke CustomerController@context and return the JSON payload as an array. */
    private function context(Shop $shop, $customer): array
    {
        $request = Request::create('/api/mobile/customers/' . $customer->id . '/context', 'GET');
        $request->setUserResolver(fn () => $this->userFor($shop));

        return TenantContext::runFor($shop->id, fn () => app(CustomerController::class)
            ->context($customer, $request)->getData(true));
    }

    public function test_additive_personal_fields_present_and_existing_unchanged(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id, [
            'email' => 'asha@example.com',
            'address' => '12 MG Road',
            'date_of_birth' => '1990-05-01',
            'anniversary_date' => '2015-02-14',
            'wedding_date' => '2015-02-14',
            'notes' => 'Prefers gold',
        ]);

        $data = $this->context($shop, $customer);

        // Existing fields unchanged.
        $this->assertSame($customer->id, $data['customer']['id']);
        $this->assertSame($customer->mobile, $data['customer']['mobile']);
        $this->assertArrayHasKey('customer_code', $data['customer']);
        $this->assertArrayHasKey('loyalty_points', $data['customer']);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('timeline', $data);

        // Additive personal fields.
        $this->assertSame('asha@example.com', $data['customer']['email']);
        $this->assertSame('12 MG Road', $data['customer']['address']);
        $this->assertSame('1990-05-01', $data['customer']['date_of_birth']);
        $this->assertSame('2015-02-14', $data['customer']['anniversary_date']);
        $this->assertSame('2015-02-14', $data['customer']['wedding_date']);
        $this->assertSame('Prefers gold', $data['customer']['notes']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['customer']['member_since']);
    }

    public function test_null_personal_fields_serialize_as_null(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id); // no optional fields

        $data = $this->context($shop, $customer);

        $this->assertNull($data['customer']['date_of_birth']);
        $this->assertNull($data['customer']['anniversary_date']);
        $this->assertNull($data['customer']['wedding_date']);
    }

    public function test_retailer_returns_loyalty_history_and_no_gold(): void
    {
        $shop = $this->createShop('retailer');
        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, fn () => LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'invoice_id' => null,
            'type' => 'earn',
            'points' => 50,
            'balance_after' => 350,
            'description' => 'Invoice #INV-0042',
        ]));

        $data = $this->context($shop, $customer);

        $this->assertCount(1, $data['loyalty_history']);
        $this->assertSame('earn', $data['loyalty_history'][0]['type']);
        $this->assertSame(50, $data['loyalty_history'][0]['points']);
        $this->assertSame(350, $data['loyalty_history'][0]['balance_after']);
        $this->assertSame('Invoice #INV-0042', $data['loyalty_history'][0]['description']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['loyalty_history'][0]['date']);

        // Manufacturer-only keys are empty/null for a retailer.
        $this->assertNull($data['gold_balance_grams']);
        $this->assertSame([], $data['gold_transactions']);
    }

    public function test_manufacturer_returns_gold_and_no_loyalty(): void
    {
        $shop = $this->createShop('manufacturer');
        $customer = $this->createCustomer($shop->id);

        TenantContext::runFor($shop->id, function () use ($customer) {
            CustomerGoldTransaction::record([
                'customer_id' => $customer->id,
                'fine_gold' => 10.5,
                'type' => 'advance',
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ]);
            CustomerGoldTransaction::record([
                'customer_id' => $customer->id,
                'fine_gold' => -2.0,
                'type' => 'sale_offset',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $data = $this->context($shop, $customer);

        // Balance = 10.5 − 2.0 = 8.5; newest-first ordering.
        $this->assertEqualsWithDelta(8.5, $data['gold_balance_grams'], 0.0001);
        $this->assertCount(2, $data['gold_transactions']);
        $this->assertSame('debit', $data['gold_transactions'][0]['type']);
        $this->assertEqualsWithDelta(2.0, $data['gold_transactions'][0]['fine_gold_grams'], 0.0001);
        $this->assertSame('credit', $data['gold_transactions'][1]['type']);
        $this->assertEqualsWithDelta(10.5, $data['gold_transactions'][1]['fine_gold_grams'], 0.0001);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $data['gold_transactions'][0]['date']);

        // Retailer-only key is empty for a manufacturer.
        $this->assertSame([], $data['loyalty_history']);
    }
}
