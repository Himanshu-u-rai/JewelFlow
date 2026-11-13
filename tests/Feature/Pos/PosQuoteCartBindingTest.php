<?php

namespace Tests\Feature\Pos;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Shop;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Audit H4 (2026-11-13): a sale takes its discount, round-off and offer from the signed quote but its items and
 * customer from the request, and nothing checked that the two describe the same bill. A quote for two items (or for
 * another customer) could be spent on a three-item sale: billed with numbers nobody saw. The quote's signed lines
 * and customer must match the sale, or the sale gets a fresh quote for its own cart (409 quote_stale).
 */
class PosQuoteCartBindingTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    /** @return array{User, Shop, Customer, Item, Item} */
    private function shopWithTwoItems(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->seedRetailerPricing($shop, $user);
        $customer = $this->createCustomer($shop->id);
        $a = $this->createItem($shop->id, null, ['selling_price' => 10000, 'source' => 'purchase']);
        $b = $this->createItem($shop->id, null, ['selling_price' => 15000, 'source' => 'purchase']);

        return [$user, $shop, $customer, $a, $b];
    }

    /** Quote and persist, as the POS screen does before Complete Sale. */
    private function quote(User $user, int $customerId, array $itemIds, float $discount = 0): array
    {
        $payload = ['mode' => 'retailer', 'customer_id' => $customerId, 'item_ids' => $itemIds, 'manual_discount' => $discount];
        $q = $this->actingAs($user)->postJson('/pos/quote', $payload)->assertOk()->json();
        $this->actingAs($user)->postJson('/pos/quote/persist', [
            'quote_id' => $q['quote_id'], 'signature' => $q['signature'],
            'breakdown_json' => $q['breakdown_json'], 'input_payload' => $payload,
        ])->assertOk();

        return $q;
    }

    private function sell(User $user, int $customerId, array $itemIds, array $q, float $amount)
    {
        return $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customerId, 'item_ids' => $itemIds, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => $amount]],
            'quote_id' => $q['quote_id'], 'quote_signature' => $q['signature'],
        ]);
    }

    private function invoices(Shop $shop): int
    {
        return Invoice::withoutGlobalScopes()->where('shop_id', $shop->id)->count();
    }

    public function test_a_quote_for_fewer_items_cannot_bill_a_bigger_cart(): void
    {
        [$user, $shop, $customer, $a, $b] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$a->id], 1000);

        $res = $this->sell($user, $customer->id, [$a->id, $b->id], $q, 50000);

        $res->assertStatus(409)->assertJsonPath('error', 'quote_stale');
        $this->assertSame(0, $this->invoices($shop), 'nothing is billed on a quote for another cart');
        $this->assertSame('in_stock', $b->fresh()->status);
        $fresh = collect($res->json('new_quote.breakdown.lines'))->pluck('item_id')->sort()->values()->all();
        $this->assertSame(collect([$a->id, $b->id])->sort()->values()->all(), $fresh, 'the fresh quote prices the cart being sold');
    }

    public function test_a_quote_for_more_items_cannot_bill_a_smaller_cart(): void
    {
        [$user, $shop, $customer, $a, $b] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$a->id, $b->id]);

        $this->sell($user, $customer->id, [$a->id], $q, 50000)->assertStatus(409)->assertJsonPath('error', 'quote_stale');
        $this->assertSame(0, $this->invoices($shop));
    }

    public function test_a_quote_for_one_customer_cannot_bill_another(): void
    {
        [$user, $shop, $customer, $a] = $this->shopWithTwoItems();
        $other = $this->createCustomer($shop->id);
        $q = $this->quote($user, $customer->id, [$a->id]);

        $this->sell($user, $other->id, [$a->id], $q, 50000)->assertStatus(409)->assertJsonPath('error', 'quote_stale');
        $this->assertSame(0, $this->invoices($shop));
    }

    public function test_a_matching_quote_bills_with_its_own_numbers(): void
    {
        [$user, $shop, $customer, $a, $b] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$b->id, $a->id], 1000);

        // Same items in another order is the same cart.
        $res = $this->sell($user, $customer->id, [$a->id, $b->id], $q, (float) $q['breakdown']['final_total'])->assertOk();

        $invoice = Invoice::withoutGlobalScopes()->findOrFail($res->json('invoice_id'));
        $this->assertEqualsWithDelta((float) $q['breakdown']['final_total'], (float) $invoice->total, 0.01);
    }

    public function test_a_double_click_returns_the_same_invoice_but_a_spent_quote_cannot_bill_another_cart(): void
    {
        [$user, $shop, $customer, $a, $b] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$a->id]);
        $total = (float) $q['breakdown']['final_total'];

        $first = $this->sell($user, $customer->id, [$a->id], $q, $total)->assertOk()->json('invoice_id');
        $this->assertSame($first, $this->sell($user, $customer->id, [$a->id], $q, $total)->assertOk()->json('invoice_id'));

        $this->sell($user, $customer->id, [$b->id], $q, $total)->assertStatus(409);
        $this->assertSame(1, $this->invoices($shop));
        $this->assertSame('in_stock', $b->fresh()->status);
    }

    /** Break attempt: the same tag twice in one sale (a double scan), with and without a quote. */
    public function test_the_same_item_twice_is_refused(): void
    {
        [$user, $shop, $customer, $a] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$a->id]);

        $this->sell($user, $customer->id, [$a->id, $a->id], $q, 20600)->assertStatus(422)->assertJsonValidationErrors('item_ids.0');
        $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_ids' => [$a->id, $a->id], 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 20600]],
        ])->assertStatus(422);
        $this->actingAs($user)->postJson('/pos/quote', ['mode' => 'retailer', 'customer_id' => $customer->id, 'item_ids' => [$a->id, $a->id]])
            ->assertStatus(422);
        $this->assertSame(0, $this->invoices($shop));
    }

    /** Break attempt: payments that do not add up to the bill are a validation answer, not a server error. */
    public function test_payments_that_do_not_match_the_bill_are_refused_cleanly(): void
    {
        [$user, $shop, $customer, $a] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$a->id]);

        $this->sell($user, $customer->id, [$a->id], $q, 1)->assertStatus(422)->assertJsonValidationErrors('payments');
        $this->assertSame(0, $this->invoices($shop));
        $this->assertSame('in_stock', $a->fresh()->status);
    }

    /**
     * Audit N16 (2026-11-13): the sale bills the quote's discount, offer and redemption, but only items and customer
     * were compared. The web sale now states the price inputs it is paying for (quote_input, the payload its quote
     * check uses); a quote priced on other inputs gets a fresh quote for the sale's own inputs instead of a bill.
     */
    public function test_a_quote_for_another_discount_cannot_bill_the_sale(): void
    {
        [$user, $shop, $customer, $a] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$a->id], 1000);

        $res = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_ids' => [$a->id], 'discount' => 500, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => 50000]],
            'quote_id' => $q['quote_id'], 'quote_signature' => $q['signature'],
            'quote_input' => ['mode' => 'retailer', 'customer_id' => $customer->id, 'item_ids' => [$a->id], 'manual_discount' => 500],
        ]);

        $res->assertStatus(409)->assertJsonPath('error', 'quote_stale');
        $this->assertEqualsWithDelta(500, (float) $res->json('new_quote.breakdown.manual_discount'), 0.01, 'priced on the discount being sold');
        $this->assertSame(0, $this->invoices($shop));
    }

    public function test_a_sale_stating_the_quoted_inputs_bills(): void
    {
        [$user, $shop, $customer, $a, $b] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$b->id, $a->id], 1000);

        $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_ids' => [$a->id, $b->id], 'discount' => 1000, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => (float) $q['breakdown']['final_total']]],
            'quote_id' => $q['quote_id'], 'quote_signature' => $q['signature'],
            // Same inputs, items in another order and numbers as strings, as a browser may send them.
            'quote_input' => ['mode' => 'retailer', 'customer_id' => (string) $customer->id, 'item_ids' => [$a->id, $b->id],
                'manual_discount' => '1000', 'manual_discount_percent' => null, 'offer_scheme_id' => null, 'scheme_redemption' => null],
        ])->assertOk();
        $this->assertSame(1, $this->invoices($shop));
    }

    public function test_the_retail_pos_sends_the_inputs_it_is_paying_for(): void
    {
        $view = file_get_contents(resource_path('views/pos_customer_retailer.blade.php'));
        $this->assertMatchesRegularExpression('/quote_input:\s*this\.posQuoteV2Enabled \? soldPayload : null/', $view);
    }

    public function test_the_app_sale_is_bound_to_its_quote_too(): void
    {
        [$user, $shop, $customer, $a, $b] = $this->shopWithTwoItems();
        $q = $this->quote($user, $customer->id, [$a->id]);

        $this->app['auth']->forgetGuards();
        TenantContext::set($shop->id);
        $token = $this->json('POST', '/api/mobile/auth/login', ['mobile_number' => $user->mobile_number, 'password' => 'password'],
            ['Accept' => 'application/json', 'X-Idempotency-Key' => (string) Str::uuid()])->json('token');
        $this->app['auth']->forgetGuards();
        $this->json('POST', '/api/mobile/pos/sell', [
            'customer_id' => $customer->id, 'item_ids' => [$a->id, $b->id],
            'payments' => [['mode' => 'cash', 'amount' => 50000]],
            'quote_id' => $q['quote_id'], 'quote_signature' => $q['signature'],
        ], ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'X-Idempotency-Key' => (string) Str::uuid()])
            ->assertStatus(409);

        $this->assertSame(0, $this->invoices($shop));
    }
}
