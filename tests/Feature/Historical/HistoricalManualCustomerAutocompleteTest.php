<?php

namespace Tests\Feature\Historical;

use App\Models\Customer;
use App\Models\Historical\HistoricalSalesDocument;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

class HistoricalManualCustomerAutocompleteTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'original_document_number' => 'CUSTOMER-UI-' . fake()->unique()->numberBetween(1, 999999),
            'document_date' => '2023-06-15',
            'source_system' => 'Manual',
            'customer_name' => 'Historical Snapshot Name',
            'customer_mobile' => '9876543210',
            'customer_gstin' => '27HISTORICAL1Z5',
            'customer_pan' => 'ABCDE1234F',
            'customer_address' => 'Historical address only',
            'grand_total' => 12000,
            'tax_mode' => HistoricalSalesDocument::TAX_MODE_UNKNOWN,
            'intent' => 'draft',
        ], $override);
    }

    public function test_suggestions_return_only_masked_minimal_active_customer_data_for_the_authenticated_shop(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();

        $active = $this->createCustomer($shop->id, [
            'first_name' => 'Active',
            'last_name' => 'Buyer',
            'mobile' => '9876543210',
            'pan' => 'ABCDE1234F',
            'address' => 'Private address',
            'gstin' => '27ABCDE1234F1Z5',
            'customer_type' => 'b2b',
        ]);
        $this->createCustomer($shop->id, [
            'first_name' => 'Archived',
            'mobile' => '9876500000',
            'is_active' => false,
        ]);
        $this->createCustomer($otherShop->id, [
            'first_name' => 'Foreign',
            'mobile' => '9876543210',
        ]);

        $response = $this->actingAs($owner)->getJson(route('historical.customers.search', ['q' => '9876543210']));

        $response->assertOk()->assertExactJson([
            'status' => 'matches',
            'results' => [[
                'id' => $active->id,
                'name' => 'Active Buyer',
                'mobile_masked' => '98******10',
                'customer_type' => 'b2b',
            ]],
        ]);

        $body = $response->getContent();
        $this->assertStringNotContainsString('ABCDE1234F', $body);
        $this->assertStringNotContainsString('Private address', $body);
        $this->assertStringNotContainsString('27ABCDE1234F1Z5', $body);
        $this->assertStringNotContainsString('9876543210', $body);
        $this->assertStringNotContainsString('Foreign', $body);
        $this->assertStringNotContainsString('Archived', $body);
    }

    public function test_archived_status_is_reported_only_for_an_exact_mobile_in_the_authenticated_shop(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();

        $this->createCustomer($shop->id, ['mobile' => '9876500000', 'is_active' => false]);
        $this->createCustomer($otherShop->id, ['mobile' => '9876511111', 'is_active' => false]);

        $this->actingAs($owner)
            ->getJson(route('historical.customers.search', ['q' => '9876500000']))
            ->assertExactJson(['status' => 'archived_action_required', 'results' => []]);

        $this->actingAs($owner)
            ->getJson(route('historical.customers.search', ['q' => '9876511111']))
            ->assertExactJson(['status' => 'new', 'results' => []]);
    }

    public function test_typing_an_exact_match_does_not_link_but_explicit_selection_does_and_preserves_the_snapshot(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id, [
            'first_name' => 'Current',
            'last_name' => 'Customer',
            'mobile' => '9876543210',
            'address' => 'Current customer address',
        ]);

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->payload())->assertRedirect();

        $typedOnly = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocument::query()->latest('id')->firstOrFail());
        $this->assertNull($typedOnly->customer_id);

        $this->actingAs($owner)->post(route('historical.manual.store'), $this->payload([
            'original_document_number' => 'CUSTOMER-UI-EXPLICIT',
            'customer_id' => $customer->id,
        ]))->assertRedirect();

        $selected = TenantContext::runFor($shop->id, fn () => HistoricalSalesDocument::query()->latest('id')->firstOrFail());
        $this->assertSame($customer->id, $selected->customer_id);
        $this->assertSame('Historical Snapshot Name', $selected->customer_snapshot['name']);
        $this->assertSame('Historical Address Only', $selected->customer_snapshot['address']);
        $this->assertSame('Current customer address', $customer->fresh()->address);
    }

    public function test_archived_and_cross_shop_customer_ids_are_rejected_before_a_document_is_written(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        [, $otherShop] = $this->createRetailerTenant();

        $archived = $this->createCustomer($shop->id, ['is_active' => false]);
        $foreign = $this->createCustomer($otherShop->id);

        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $this->payload(['customer_id' => $archived->id]))
            ->assertSessionHasErrors('customer_id');

        $this->actingAs($owner)
            ->post(route('historical.manual.store'), $this->payload(['customer_id' => $foreign->id]))
            ->assertSessionHasErrors('customer_id');

        TenantContext::runFor($shop->id, fn () => $this->assertSame(0, HistoricalSalesDocument::query()->count()));
    }

    public function test_manual_form_renders_explicit_selection_status_and_safe_default_customer_creation_choice(): void
    {
        [$owner] = $this->createRetailerTenant();

        $html = $this->actingAs($owner)->get(route('historical.manual.create'))->assertOk()->getContent();

        $this->assertStringContainsString('data-historical-customer-picker', $html);
        $this->assertStringContainsString('name="customer_id"', $html);
        $this->assertStringContainsString('data-customer-search-url=', $html);
        $this->assertStringContainsString('data-customer-status', $html);
        $this->assertStringContainsString('Existing customer', $html);
        $this->assertStringContainsString('New customer', $html);
        $this->assertStringContainsString('Snapshot only', $html);
        $this->assertStringContainsString('Archived — action required', $html);
        $this->assertMatchesRegularExpression('/name="add_customer_on_publish"[^>]*value="1"[^>]*checked/', $html);
        $this->assertStringContainsString('lg:grid-cols-12', $html);
        $this->assertStringContainsString('lg:col-span-4', $html);
        $this->assertStringContainsString('lg:col-span-8', $html);

        $unchecked = $this->actingAs($owner)
            ->withSession(['_old_input' => ['add_customer_on_publish' => '0']])
            ->get(route('historical.manual.create'))
            ->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="add_customer_on_publish"[^>]*checked/', $unchecked);
    }
}
