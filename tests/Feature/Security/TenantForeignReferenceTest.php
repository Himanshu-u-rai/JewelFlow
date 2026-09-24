<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\KarigarInvoice;
use App\Models\Shop;
use App\Models\ShopPaymentMethod;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * §7e, request-supplied related ids — the ids a request names that point at
 * ANOTHER record, where ownership is not enforced by the record's own binding.
 * Found by tests/Inventory/request_id_sweep.php and traced to their writes.
 *
 * S3-14  POST /pos/sell (retail): payments.*.payment_method_id was validated
 *        only as an integer and written to the append-only invoice_payments
 *        row. Mobile POS, quick bills, installments, karigar and historical
 *        entry all check the account belongs to the shop; this path did not.
 * S3-15  POST /karigar-invoices: job_order_id was validated only as an
 *        integer and written to the invoice.
 *
 * Each case uses a real route, a real second shop, and checks the response,
 * the rows written, and the other shop's own operation afterwards.
 */
class TenantForeignReferenceTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ERP = 'https://jewelflows.com';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    }

    private function account(int $shopId, string $name): ShopPaymentMethod
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $name) {
            $method = new ShopPaymentMethod;
            $method->forceFill([
                'shop_id' => $shopId, 'type' => ShopPaymentMethod::TYPE_UPI, 'name' => $name,
                'upi_id' => strtolower($name).'@bank', 'is_active' => true, 'sort_order' => 0,
            ])->save();

            return $method;
        });
    }

    /** A retail shop that can sell today: daily rates saved through the real route. */
    private function retailShopReadyToSell(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->post(self::ERP.'/settings/pricing/daily-rates', ['gold_24k_rate_per_gram' => 7200, 'silver_999_rate_per_kg' => 92000]));
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 10000, 'source' => 'purchase', 'metal_type' => 'gold']);

        return [$owner, $shop, $customer, $item];
    }

    private function sell(User $owner, Shop $shop, int $customerId, int $itemId, ?int $accountId)
    {
        $total = (float) TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
            ->postJson(self::ERP.'/api/price-preview', ['item_id' => $itemId, 'customer_id' => $customerId]))->json('total');

        return TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->postJson(self::ERP.'/pos/sell', [
            'customer_id' => $customerId,
            'item_ids' => [$itemId],
            'payments' => [['mode' => 'upi', 'amount' => $total, 'payment_method_id' => $accountId]],
        ]));
    }

    // ── S3-14 ─────────────────────────────────────────────────────────────

    public function test_s3_14_a_retail_sale_refuses_another_shops_payment_account(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $accountB = $this->account($shopB->id, 'ShopBUpi');
        [$ownerA, $shopA, $customerA, $itemA] = $this->retailShopReadyToSell();

        $res = $this->sell($ownerA, $shopA, $customerA->id, $itemA->id, $accountB->id);

        $res->assertStatus(422)->assertJsonValidationErrors('payments.0.payment_method_id');
        $this->assertSame(0, Invoice::withoutGlobalScopes()->where('shop_id', $shopA->id)->count(), 'no sale was written for shop A');
        $this->assertSame(0, InvoicePayment::withoutGlobalScopes()->where('payment_method_id', $accountB->id)->count(),
            "no row anywhere refers to shop B's account");
        $this->assertSame('in_stock', DB::table('items')->where('id', $itemA->id)->value('status'));

        // Shop B's own operation is unaffected: it can still delete its account.
        [$ownerB] = [User::withoutGlobalScopes()->where('shop_id', $shopB->id)->firstOrFail()];
        TenantContext::runFor($shopB->id, fn () => $this->actingAs($ownerB)
            ->delete(self::ERP.'/settings/payment-methods/'.$accountB->id))->assertRedirect();
        $this->assertSame(0, DB::table('shop_payment_methods')->where('id', $accountB->id)->count());
    }

    public function test_s3_14_control_a_retail_sale_records_its_own_payment_account(): void
    {
        [$ownerA, $shopA, $customerA, $itemA] = $this->retailShopReadyToSell();
        $accountA = $this->account($shopA->id, 'ShopAUpi');

        $this->sell($ownerA, $shopA, $customerA->id, $itemA->id, $accountA->id)->assertOk();

        $this->assertSame(1, InvoicePayment::withoutGlobalScopes()->where('shop_id', $shopA->id)
            ->where('payment_method_id', $accountA->id)->count());
    }

    // ── S3-15 ─────────────────────────────────────────────────────────────

    private function karigar(int $shopId, string $name): int
    {
        return (int) DB::table('karigars')->insertGetId([
            'shop_id' => $shopId, 'name' => $name, 'is_active' => DB::raw('true'), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function jobOrder(int $shopId, int $karigarId, int $userId, string $number): int
    {
        return (int) DB::table('job_orders')->insertGetId([
            'shop_id' => $shopId, 'karigar_id' => $karigarId, 'job_order_number' => $number, 'challan_number' => 'CH-'.$number,
            'metal_type' => 'gold', 'purity' => 22, 'issued_gross_weight' => 10, 'issued_fine_weight' => 9.16,
            'expected_return_fine_weight' => 9, 'allowed_wastage_percent' => 2, 'status' => 'issued',
            'issue_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(), 'created_by_user_id' => $userId,
        ]);
    }

    private function karigarInvoice(User $owner, Shop $shop, int $karigarId, int $jobOrderId, string $number)
    {
        return TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)->post(self::ERP.'/karigar-invoices', [
            'karigar_id' => $karigarId, 'job_order_id' => $jobOrderId, 'mode' => 'job_work',
            'karigar_invoice_number' => $number, 'karigar_invoice_date' => now()->toDateString(),
            'lines' => [['description' => 'Ring labour', 'pieces' => 1, 'gross_weight' => 5, 'net_weight' => 5, 'purity' => 916, 'making_charge' => 1000]],
        ]));
    }

    public function test_s3_15_a_karigar_invoice_refuses_another_shops_job_order(): void
    {
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $jobB = $this->jobOrder($shopB->id, $this->karigar($shopB->id, 'KarigarB'), $ownerB->id, 'JO-B-1');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $karigarA = $this->karigar($shopA->id, 'KarigarA');

        $this->karigarInvoice($ownerA, $shopA, $karigarA, $jobB, 'KI-A-1')
            ->assertRedirect()->assertSessionHasErrors('job_order_id');

        $this->assertSame(0, KarigarInvoice::withoutGlobalScopes()->where('shop_id', $shopA->id)->count(), 'no invoice for shop A');
        $this->assertSame(0, KarigarInvoice::withoutGlobalScopes()->where('job_order_id', $jobB)->count(),
            "no invoice anywhere refers to shop B's job order");
    }

    public function test_s3_15_control_a_karigar_invoice_links_its_own_job_order(): void
    {
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $karigarA = $this->karigar($shopA->id, 'KarigarA');
        $jobA = $this->jobOrder($shopA->id, $karigarA, $ownerA->id, 'JO-A-1');

        $this->karigarInvoice($ownerA, $shopA, $karigarA, $jobA, 'KI-A-2')->assertSessionHasNoErrors();

        $this->assertSame($jobA, (int) KarigarInvoice::withoutGlobalScopes()->where('shop_id', $shopA->id)->value('job_order_id'));
    }
}
