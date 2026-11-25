<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Customers keep first_name and last_name apart, so a search for the whole name
 * ("Ramesh Kumar") must still find them. One test per kind of search surface;
 * every surface goes through Customer::matchingNameOrMobile(), so one passing
 * surface of each kind guards the shared helper from three directions: POS
 * picker JSON, the Customers list page and the mobile API.
 */
class CustomerFullNameSearchTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    /** @return array{0:\App\Models\User,1:\App\Models\Shop} owner + shop, with the fixture customers made */
    private function tenantWithCustomers(): array
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();
        $other = $this->createRetailerTenant()[1];

        foreach ([
            ['Ramesh', 'Kumar', '9811100001'],
            ['ZZ Test', 'Customer 01', '9811100002'],
            ['Meena', '100% Pure', '9811100003'],
            ['Meena', '1000 Pured', '9811100004'],
            ['Under_score', 'Shah', '9811100005'],
            ['UnderXscore', 'Shah', '9811100006'],
        ] as [$first, $last, $mobile]) {
            $this->createCustomer($shop->id, ['first_name' => $first, 'last_name' => $last, 'mobile' => $mobile]);
        }
        // Same name in another shop: must never come back.
        $this->createCustomer($other->id, ['first_name' => 'Ramesh', 'last_name' => 'Kumar', 'mobile' => '9822200001']);

        return [$owner, $shop];
    }

    /**
     * Runs the shared expectations against one surface.
     *
     * @param  \Closure(string):array<int,string>  $search  query => matching "First Last" names
     */
    private function assertSearchBehaviour(\Closure $search): void
    {
        $this->assertSame(['Ramesh Kumar'], $search('Ramesh Kumar'), 'full name');
        $this->assertSame(['Ramesh Kumar'], $search('ramesh  KUMAR'), 'case and repeated spaces');
        $this->assertSame(['ZZ Test Customer 01'], $search('ZZ Test Customer 01'), 'full name, multi-word parts');
        $this->assertSame(['ZZ Test Customer 01'], $search('Customer 01'), 'last name only');
        $this->assertSame(['Ramesh Kumar'], $search('Ramesh'), 'first name only');
        $this->assertSame(['Ramesh Kumar'], $search('mesh Kum'), 'name fragment across the space');
        $this->assertSame(['Ramesh Kumar'], $search('9811100001'), 'mobile');
        // % and _ are typed text, not wildcards.
        $this->assertSame(['Meena 100% Pure'], $search('100%'), 'percent is literal');
        $this->assertSame(['Under_score Shah'], $search('Under_s'), 'underscore is literal');
        $this->assertSame(['Meena 100% Pure'], $search('%'), 'a bare percent is not "match everything"');
    }

    public function test_pos_customer_picker_matches_full_name_partial_mobile_and_literal_wildcards_within_the_shop(): void
    {
        [$owner, $shop] = $this->tenantWithCustomers();

        $this->assertSearchBehaviour(fn (string $q) => TenantContext::runFor($shop->id, fn () => collect(
            $this->actingAs($owner)->getJson('/pos/customers/search?search=' . urlencode($q))->assertOk()->json()
        )->map(fn ($c) => trim($c['first_name'] . ' ' . $c['last_name']))->sort()->values()->all()));
    }

    public function test_customers_list_matches_full_name_partial_mobile_and_literal_wildcards_within_the_shop(): void
    {
        [$owner, $shop] = $this->tenantWithCustomers();

        $this->assertSearchBehaviour(fn (string $q) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/customers?search=' . urlencode($q))->assertOk()->viewData('customers')
            ->map(fn ($c) => trim($c->first_name . ' ' . $c->last_name))->sort()->values()->all()));
    }

    public function test_mobile_api_matches_full_name_partial_mobile_and_literal_wildcards_within_the_shop(): void
    {
        [$owner, $shop] = $this->tenantWithCustomers();
        Sanctum::actingAs($owner, ['*']);

        foreach (['/api/mobile/customers/search' => fn ($r) => $r->json(), '/api/mobile/customers' => fn ($r) => $r->json('data')] as $path => $rows) {
            $this->assertSearchBehaviour(fn (string $q) => TenantContext::runFor($shop->id, fn () => collect(
                $rows($this->getJson($path . '?search=' . urlencode($q))->assertOk())
            )->map(fn ($c) => trim($c['first_name'] . ' ' . $c['last_name']))->sort()->values()->all()));
        }
    }
}
