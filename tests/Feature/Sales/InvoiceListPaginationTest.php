<?php

namespace Tests\Feature\Sales;

use App\Models\Invoice;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\AssertsStablePagination;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Bills made in the same second must each show on exactly one page of the
 * invoice list, on the web and in the mobile API.
 */
class InvoiceListPaginationTest extends TestCase
{
    use AssertsStablePagination;
    use CreatesTestTenant;
    use RefreshDatabase;

    /** @return array{0: \App\Models\User, 1: \App\Models\Shop, 2: array<int, int>} */
    private function shopWithTiedDrafts(): array
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();
        $customer = $this->createCustomer($shop->id);

        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, 50))->map(fn () => Invoice::issue([
            'shop_id' => $shop->id, 'customer_id' => $customer->id, 'status' => Invoice::STATUS_DRAFT,
            'gold_rate' => 0, 'subtotal' => 100, 'gst' => 0, 'gst_rate' => 3, 'wastage_charge' => 0,
            'discount' => 0, 'round_off' => 0, 'total' => 100,
        ])->id)->all());
        $this->tieAndScramble('invoices', $ids, ['created_at' => '2026-11-01 10:00:00']);

        return [$owner, $shop, $ids];
    }

    public function test_web_invoices_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        [$owner, $shop, $ids] = $this->shopWithTiedDrafts();

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/invoices?page=' . $p)->assertOk()->viewData('invoices'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 20, 'web invoices');
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), $page(1));
    }

    public function test_mobile_invoices_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        [$owner, $shop, $ids] = $this->shopWithTiedDrafts();
        Sanctum::actingAs($owner);

        $page = fn (int $p) => collect($this->getJson('/api/mobile/invoices?per_page=20&page=' . $p)
            ->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEachOnExactlyOnePage($ids, $page, 20, 'mobile invoices');
        $this->assertSame(array_slice(array_reverse($ids), 0, 20), $page(1));
    }
}
