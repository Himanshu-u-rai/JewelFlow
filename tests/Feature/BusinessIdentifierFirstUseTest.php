<?php

namespace Tests\Feature;

use App\Models\ShopCounter;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * A counter's first use, in sequence. Its first use by several requests at
 * once is tests/Concurrency/quick_bill_create_race.php, scenarios D to I:
 * that race cannot be scheduled inside one test transaction.
 */
class BusinessIdentifierFirstUseTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    public function test_a_shops_first_invoice_takes_its_own_starting_number_prefix_and_suffix(): void
    {
        [, $shop] = $this->createRetailerTenant();
        DB::table('shop_billing_settings')->where('shop_id', $shop->id)
            ->update(['invoice_prefix' => 'GJ/', 'invoice_start_number' => 5001, 'invoice_suffix' => '/26']);

        $this->assertSame(['sequence' => 5001, 'number' => 'GJ/5001/26'], BusinessIdentifierService::nextInvoiceIdentifier($shop->id));
        $this->assertSame(['sequence' => 5002, 'number' => 'GJ/5002/26'], BusinessIdentifierService::nextInvoiceIdentifier($shop->id));
    }

    public function test_every_other_counter_starts_at_one_and_each_shop_counts_for_itself(): void
    {
        [, $a] = $this->createRetailerTenant();
        [, $b] = $this->createRetailerTenant();

        $this->assertSame('QB-1', BusinessIdentifierService::nextQuickBillIdentifier($a->id)['number']);
        $this->assertSame('QB-2', BusinessIdentifierService::nextQuickBillIdentifier($a->id)['number']);
        $this->assertSame('QB-1', BusinessIdentifierService::nextQuickBillIdentifier($b->id)['number']);
        $this->assertSame('PUR-1', BusinessIdentifierService::nextPurchaseIdentifier($a->id)['number']);
        $this->assertSame(1, ShopCounter::query()->where('shop_id', $a->id)->where('counter_key', 'quick_bill')->count());
    }

    public function test_an_existing_counter_is_continued_not_started_again(): void
    {
        [, $shop] = $this->createRetailerTenant();
        ShopCounter::query()->create(['shop_id' => $shop->id, 'counter_key' => 'credit_note', 'current_value' => 41]);

        $this->assertSame('CN-42', BusinessIdentifierService::nextCreditNoteIdentifier($shop->id)['number']);
    }

    public function test_the_first_use_inside_a_callers_transaction_leaves_that_transaction_usable(): void
    {
        [, $shop] = $this->createRetailerTenant();

        // What a real caller does: take the number, then keep writing.
        $rows = DB::transaction(function () use ($shop) {
            BusinessIdentifierService::nextJobOrderIdentifier($shop->id);

            return DB::table('shop_counters')->where('shop_id', $shop->id)->count();
        });

        $this->assertGreaterThanOrEqual(1, $rows);
    }

    public function test_a_failure_that_is_not_the_first_use_conflict_is_not_absorbed(): void
    {
        // No such shop: the foreign key refuses the counter row, and says so.
        $this->expectException(QueryException::class);

        BusinessIdentifierService::nextCounter(2_000_000_000, 'purchase');
    }

    public function test_the_platform_counters_first_use_works_and_continues(): void
    {
        DB::table('platform_counters')->where('counter_key', 'platform_invoice')->delete();
        $prefix = config('business.platform_invoice_prefix', 'JFINV-');

        $this->assertSame($prefix . '1', BusinessIdentifierService::nextPlatformInvoiceNumber()['number']);
        $this->assertSame($prefix . '2', BusinessIdentifierService::nextPlatformInvoiceNumber()['number']);
    }
}
