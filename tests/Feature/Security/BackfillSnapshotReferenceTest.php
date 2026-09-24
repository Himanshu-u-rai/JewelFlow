<?php

namespace Tests\Feature\Security;

use App\Models\ShopPaymentMethod;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §7e, persisted. accounting:backfill-snapshots writes each payment row's
 * label snapshot from the payment method it references by id (on quick-bill
 * payments; invoice and karigar payments are append-only and refuse the
 * update). Every form
 * validates payment_method_id with exists + shop, but a row written before
 * that rule, or by a path that skips it, can reference another shop's
 * method — and a label copied from it would put that shop's account name and
 * UPI id into this shop's receipts for good. The label must come only from a
 * method of the row's own shop; otherwise from the payment mode.
 */
class BackfillSnapshotReferenceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function method(int $shopId, string $name, string $upi): ShopPaymentMethod
    {
        return TenantContext::runFor($shopId, fn () => ShopPaymentMethod::create([
            'shop_id' => $shopId, 'type' => ShopPaymentMethod::TYPE_UPI, 'name' => $name, 'upi_id' => $upi, 'is_active' => true,
        ]));
    }

    /** A quick-bill payment (invoice and karigar payments are append-only: the backfill cannot update them). */
    private function payment(int $shopId, int $methodId): int
    {
        static $sequence = 0;
        $sequence++;
        $billId = DB::table('quick_bills')->insertGetId([
            'shop_id' => $shopId, 'bill_sequence' => $sequence, 'bill_number' => "QB-T-{$sequence}", 'bill_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('quick_bill_payments')->insertGetId([
            'shop_id' => $shopId, 'quick_bill_id' => $billId, 'payment_mode' => 'upi', 'amount' => 1000, 'payment_method_id' => $methodId,
            'payment_method_label_snapshot' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_the_label_backfill_never_copies_another_shops_payment_method(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [, $shopB] = $this->createRetailerTenant();
        $own = $this->payment((int) $shopA->id, (int) $this->method((int) $shopA->id, 'AlphaCounter', 'alpha@upi')->id);
        $foreign = $this->payment((int) $shopA->id, (int) $this->method((int) $shopB->id, 'BravoSecret', 'bravo@upi')->id);   // as an inconsistent row would sit

        $this->artisan('accounting:backfill-snapshots', ['--shop' => $shopA->id])->assertSuccessful();

        $label = fn (int $id) => (string) DB::table('quick_bill_payments')->where('id', $id)->value('payment_method_label_snapshot');
        $this->assertSame('AlphaCounter (alpha@upi)', $label($own), 'a consistent reference still labels from its method');
        $this->assertStringNotContainsString('Bravo', $label($foreign), "another shop's account name");
        $this->assertStringNotContainsString('bravo@upi', $label($foreign), "another shop's UPI id");
        $this->assertSame('UPI', $label($foreign), 'the label falls back to the payment mode');
    }
}
