<?php

namespace Tests\Feature\Loyalty;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LoyaltyTransaction;
use App\Models\Shop;
use App\Services\LoyaltyService;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-16 — loyalty expiry, append-only.
 *
 * loyalty_transactions is append-only (Constitution Art. IX.A #9), so an
 * expiry is a new `redeem` row naming the lot it expires (expires_lot_id,
 * unique: one expiry per lot, whoever runs). What a lot still holds comes
 * from replaying the customer's ledger: a reversal takes from its own
 * invoice's lot, anything else from the earliest-expiring points first.
 *
 * Nothing expires unless loyalty.expiry_active_from is set, and only lots
 * that fell due on or after it. Lots that fell due before it — the overdue
 * backlog left by S3-16 — are reported, never expired.
 *
 * Clock: 2026-10-15. Activation, where set: 2026-10-01.
 */
class LoyaltyExpiryTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const BACKLOG = '2026-09-01';   // fell due before activation
    private const DUE = '2026-10-10';       // fell due after activation
    private const FUTURE = '2027-01-01';    // not yet due

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        Carbon::setTestNow('2026-10-15 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function holder(Shop $shop): Customer
    {
        return $this->createCustomer((int) $shop->id);
    }

    private function earn(Customer $customer, int $points, string $expiresAt, ?int $invoiceId = null): LoyaltyTransaction
    {
        return TenantContext::runFor((int) $customer->shop_id, fn () => $customer->fresh()
            ->addLoyaltyPoints($points, $invoiceId, 'fixture earn', Carbon::parse($expiresAt)));
    }

    private function redeem(Customer $customer, int $points): void
    {
        TenantContext::runFor((int) $customer->shop_id, fn () => app(LoyaltyService::class)
            ->adjustPoints($customer->fresh(), $points, 'redeem', 'fixture redemption'));
    }

    private function sale(Customer $customer): Invoice
    {
        return TenantContext::runFor((int) $customer->shop_id, fn () => Invoice::issue([
            'shop_id' => $customer->shop_id, 'customer_id' => $customer->id, 'gold_rate' => 7200,
            'subtotal' => 10000, 'gst' => 0, 'total' => 10000, 'status' => Invoice::STATUS_FINALIZED,
        ]));
    }

    private function cancel(Invoice $invoice): void
    {
        TenantContext::runFor((int) $invoice->shop_id, fn () => app(LoyaltyService::class)
            ->reversePoints((int) $invoice->id, (int) $invoice->shop_id));
    }

    private function balance(Customer $customer): int
    {
        return (int) DB::table('customers')->where('id', $customer->id)->value('loyalty_points');
    }

    /** loyalty:expire exactly as the scheduler runs it: no tenant context. */
    private function expire(?string $activeFrom, array $options = []): int
    {
        config(['loyalty.expiry_active_from' => $activeFrom]);
        $this->assertNull(TenantContext::get());

        return Artisan::call('loyalty:expire', $options);
    }

    private function expiries(Customer $customer)
    {
        return DB::table('loyalty_transactions')->where('customer_id', $customer->id)->whereNotNull('expires_lot_id')->get();
    }

    public function test_not_activated_it_reports_each_shops_overdue_points_and_writes_nothing(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $a = $this->holder($shopA);
        $b = $this->holder($shopB);
        $this->earn($a, 100, self::DUE);
        $this->earn($a, 40, self::BACKLOG);
        $this->earn($b, 70, self::DUE);
        $rows = DB::table('loyalty_transactions')->count();

        $this->assertSame(0, $this->expire(null));
        $out = Artisan::output();

        $this->assertStringContainsString('NOT ACTIVATED', $out);
        $this->assertStringContainsString("Shop #{$shopA->id}: nothing written; overdue, kept: 2 lot(s) / 140 pts", $out);
        $this->assertStringContainsString("Shop #{$shopB->id}: nothing written; overdue, kept: 1 lot(s) / 70 pts", $out);
        $this->assertSame($rows, DB::table('loyalty_transactions')->count());
        $this->assertSame(140, $this->balance($a));
        $this->assertSame(70, $this->balance($b));
    }

    public function test_activated_it_expires_only_lots_that_fell_due_after_activation_each_in_its_own_shop(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $a = $this->holder($shopA);
        $b = $this->holder($shopB);
        $backlog = $this->earn($a, 40, self::BACKLOG);
        $due = $this->earn($a, 100, self::DUE);
        $future = $this->earn($a, 25, self::FUTURE);
        $dueB = $this->earn($b, 70, self::DUE);
        $before = DB::table('loyalty_transactions')->orderBy('id')->get();

        $this->assertSame(0, $this->expire('2026-10-01'));

        $this->assertStringContainsString("Shop #{$shopA->id}: expired 1 lot(s) / 100 pts; overdue before activation, kept: 1 lot(s) / 40 pts", Artisan::output());
        $expiry = $this->expiries($a)->sole();
        $this->assertSame([(int) $due->id, 'redeem', 100, 'Points expired', 65, (int) $shopA->id],
            [(int) $expiry->expires_lot_id, $expiry->type, (int) $expiry->points, $expiry->description, (int) $expiry->balance_after, (int) $expiry->shop_id]);
        $this->assertSame(65, $this->balance($a), 'backlog 40 + future 25 remain');
        $this->assertSame((int) $dueB->id, (int) $this->expiries($b)->sole()->expires_lot_id);
        $this->assertSame((int) $shopB->id, (int) $this->expiries($b)->sole()->shop_id);
        $this->assertSame(0, $this->balance($b));
        $this->assertEquals($before, DB::table('loyalty_transactions')->whereIn('id', $before->pluck('id'))->orderBy('id')->get(),
            'every existing row is unchanged — nothing was updated');
        $this->assertNotContains((int) $backlog->id, $this->expiries($a)->pluck('expires_lot_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotContains((int) $future->id, $this->expiries($a)->pluck('expires_lot_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_redemption_is_taken_from_the_earliest_expiring_points_first(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $spentAll = $this->holder($shop);
        $this->earn($spentAll, 100, self::DUE);
        $this->earn($spentAll, 100, self::FUTURE);
        $this->redeem($spentAll, 150);                 // 100 from the due lot, 50 from the future one
        $spentSome = $this->holder($shop);
        $this->earn($spentSome, 100, self::DUE);
        $this->earn($spentSome, 100, self::FUTURE);
        $this->redeem($spentSome, 30);                 // 30 from the due lot: 70 left in it

        $this->expire('2026-10-01');

        $this->assertCount(0, $this->expiries($spentAll));
        $this->assertSame(50, $this->balance($spentAll));
        $this->assertSame(70, (int) $this->expiries($spentSome)->sole()->points);
        $this->assertSame(100, $this->balance($spentSome), 'the future lot is intact');
    }

    public function test_a_cancelled_sales_points_never_expire_and_are_never_taken_twice(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $cancelledFirst = $this->holder($shop);
        $sale = $this->sale($cancelledFirst);
        $this->earn($cancelledFirst, 100, self::DUE, (int) $sale->id);
        $this->earn($cancelledFirst, 30, self::FUTURE);
        $this->cancel($sale);                           // reversal takes the lot's 100

        $expiredFirst = $this->holder($shop);
        $sale2 = $this->sale($expiredFirst);
        $this->earn($expiredFirst, 100, self::DUE, (int) $sale2->id);
        $this->earn($expiredFirst, 30, self::FUTURE);

        $this->expire('2026-10-01');
        $this->cancel($sale2);                          // expiry already took the lot

        $this->assertCount(0, $this->expiries($cancelledFirst));
        $this->assertSame(30, $this->balance($cancelledFirst));
        $this->assertSame(100, (int) $this->expiries($expiredFirst)->sole()->points);
        $this->assertSame(30, $this->balance($expiredFirst), 'the reversal took nothing more');
        $this->assertSame(0, DB::table('loyalty_transactions')->where('customer_id', $expiredFirst->id)
            ->where('description', 'like', 'Reversed%')->count());
    }

    public function test_a_lot_is_expired_once_however_often_or_concurrently_the_run_happens(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->holder($shop);
        $lot = $this->earn($customer, 100, self::DUE);
        $this->earn($customer, 10, self::FUTURE);

        $this->expire('2026-10-01');
        $this->expire('2026-10-01');

        $this->assertCount(1, $this->expiries($customer));
        $this->assertSame(10, $this->balance($customer));

        // The database refuses a second expiry of the same lot, whatever wrote it.
        $this->expectException(UniqueConstraintViolationException::class);
        TenantContext::runFor((int) $shop->id, fn () => LoyaltyTransaction::create([
            'customer_id' => $customer->id, 'type' => 'redeem', 'points' => 1, 'description' => 'Points expired',
            'balance_after' => 9, 'expires_lot_id' => $lot->id,
        ]));
    }

    public function test_a_customer_whose_balance_disagrees_with_the_ledger_is_skipped_and_reported(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->holder($shop);
        $this->earn($customer, 100, self::DUE);
        DB::table('customers')->where('id', $customer->id)->update(['loyalty_points' => 60]);   // set outside the ledger

        $this->expire('2026-10-01');

        $this->assertStringContainsString("skipped, ledger ≠ balance: customer {$customer->id}", Artisan::output());
        $this->assertCount(0, $this->expiries($customer));
        $this->assertSame(60, $this->balance($customer));
    }

    public function test_a_dry_run_and_historical_expiries_write_nothing(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $customer = $this->holder($shop);
        $this->earn($customer, 100, self::DUE);
        // Expired by the pre-trigger code: flag set on the lot, debit written.
        $legacy = $this->holder($shop);
        $lot = DB::table('loyalty_transactions')->insertGetId([
            'shop_id' => $shop->id, 'customer_id' => $legacy->id, 'type' => 'earn', 'points' => 50,
            'description' => 'legacy earn', 'balance_after' => 50, 'expires_at' => self::DUE, 'expired' => DB::raw('true'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('loyalty_transactions')->insert([
            'shop_id' => $shop->id, 'customer_id' => $legacy->id, 'type' => 'redeem', 'points' => 50,
            'description' => 'Points expired', 'balance_after' => 0, 'expired' => DB::raw('false'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rows = DB::table('loyalty_transactions')->count();

        $this->assertSame(0, $this->expire('2026-10-01', ['--dry-run' => true]));
        $this->assertStringContainsString("Shop #{$shop->id}: due, not written (dry run): 1 lot(s) / 100 pts", Artisan::output());
        $this->assertSame($rows, DB::table('loyalty_transactions')->count());

        $this->expire('2026-10-01');
        $this->assertCount(1, $this->expiries($customer));
        $this->assertCount(0, $this->expiries($legacy), "the legacy lot was expired before; it is not expired again");
        $this->assertSame(0, (int) DB::table('loyalty_transactions')->where('expires_lot_id', $lot)->count());
    }
}
