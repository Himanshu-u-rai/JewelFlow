<?php

namespace Tests\Feature\Customer;

use App\Models\Customer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * Customers created in the same second (a bulk import, a busy counter) must not
 * repeat across pages or drop out of the list. Ordering by created_at alone is
 * not a total order, and PostgreSQL may return ties in a different order for
 * each LIMIT/OFFSET query, so the list ends on the id as well.
 */
class CustomerListPaginationTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    public function test_customers_with_the_same_created_at_each_appear_on_exactly_one_page(): void
    {
        $this->skipIfNotPostgres();
        [$owner, $shop] = $this->createRetailerTenant();

        $ids = TenantContext::runFor($shop->id, fn () => collect(range(1, 45))->map(fn ($n) => Customer::create([
            'shop_id' => $shop->id, 'first_name' => 'Same', 'last_name' => 'Second ' . $n,
            'mobile' => '98123' . str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        ])->id)->all());
        DB::table('customers')->whereIn('id', $ids)->update(['created_at' => '2026-11-01 10:00:00']);
        // Later edits rewrite rows in another physical order, as everyday use does; ties then come back scrambled.
        $edited = $ids;
        mt_srand(7);
        shuffle($edited);
        foreach ($edited as $id) {
            DB::table('customers')->where('id', $id)->update(['notes' => 'edited']);
        }

        $page = fn (int $p) => TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->get('/customers?page=' . $p)->assertOk()->viewData('customers'))->pluck('id')->all();
        $first = $page(1);
        $second = $page(2);

        $third = $page(3);

        $this->assertCount(15, $first);
        $this->assertCount(15, $second);
        $this->assertCount(15, $third);
        $this->assertEqualsCanonicalizing($ids, array_merge($first, $second, $third), 'every customer on exactly one page');
        // Newest first: among equal times, the later-created (higher id) customer leads.
        $this->assertSame(array_slice(array_reverse($ids), 0, 15), $first);
    }
}
