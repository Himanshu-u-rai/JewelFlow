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

    // ── Unscoped binding: the shop's platform billing invoice ─────────────
    //
    // PlatformInvoice has shop_id but no BelongsToShop, so /billing/{invoice}
    // binds any shop's invoice; BillingController@show checks the shop. Read
    // in §7e, not previously exercised across shops.

    private function platformInvoice(int $shopId, string $number): \App\Models\Platform\PlatformInvoice
    {
        $sub = \App\Models\Platform\ShopSubscription::query()->where('shop_id', $shopId)->firstOrFail();

        return \App\Models\Platform\PlatformInvoice::create([
            'shop_id' => $shopId, 'shop_subscription_id' => $sub->id, 'plan_id' => $sub->plan_id,
            'invoice_number' => $number, 'invoice_sequence' => 1, 'billing_cycle' => 'monthly',
            'billing_period_start' => now()->subMonth()->toDateString(), 'billing_period_end' => now()->toDateString(),
            'amount_before_tax' => 999, 'gst_rate' => 18, 'gst_amount' => 179.82, 'total_amount' => 1178.82,
            'status' => 'paid', 'issued_at' => now(),
            'created_by_admin_id' => \App\Models\Platform\PlatformAdmin::query()->value('id'),
        ]);
    }

    public function test_a_shop_cannot_read_another_shops_platform_billing_invoice(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $invoiceB = $this->platformInvoice((int) $shopB->id, 'PLT-SHOP-B-0001');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $invoiceA = $this->platformInvoice((int) $shopA->id, 'PLT-SHOP-A-0001');

        TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($ownerA)->get(self::ERP.'/billing/'.$invoiceB->id))
            ->assertForbidden()->assertDontSee('PLT-SHOP-B-0001');

        TenantContext::runFor((int) $shopA->id, fn () => $this->actingAs($ownerA)->get(self::ERP.'/billing/'.$invoiceA->id))
            ->assertOk()->assertSee('PLT-SHOP-A-0001');
    }

    // ── Read-only release checks (handoff §0a, R10), exercised ────────────
    //
    // Rows written before the S3-14/S3-15 fixes are inserted directly, as the
    // old routes wrote them, beside rows that name their own shop's records.
    // The documented queries must return the foreign ones and nothing else.

    public const S3_14_CHECK = 'select ip.id, ip.shop_id, spm.shop_id as account_shop_id from invoice_payments ip '
        .'join shop_payment_methods spm on spm.id = ip.payment_method_id where ip.shop_id <> spm.shop_id';

    public const S3_15_CHECK = 'select ki.id, ki.shop_id, jo.shop_id as job_order_shop_id from karigar_invoices ki '
        .'join job_orders jo on jo.id = ki.job_order_id where ki.shop_id <> jo.shop_id';

    public function test_the_s3_14_check_finds_a_historical_foreign_account_and_nothing_else(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $accountB = $this->account($shopB->id, 'ShopBUpi');
        [$ownerA, $shopA, $customerA, $itemA] = $this->retailShopReadyToSell();
        $accountA = $this->account($shopA->id, 'ShopAUpi');
        $this->sell($ownerA, $shopA, $customerA->id, $itemA->id, $accountA->id)->assertOk();   // own account: must not be found
        $invoiceId = (int) DB::table('invoices')->where('shop_id', $shopA->id)->value('id');
        $foreign = DB::table('invoice_payments')->insertGetId([   // as the pre-fix route wrote it
            'invoice_id' => $invoiceId, 'shop_id' => $shopA->id, 'mode' => 'upi', 'amount' => 1,
            'payment_method_id' => $accountB->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = DB::select(self::S3_14_CHECK);

        $this->assertSame([$foreign], array_map(fn ($r) => (int) $r->id, $rows));
        $this->assertSame((int) $shopB->id, (int) $rows[0]->account_shop_id);
    }

    public function test_the_s3_15_check_finds_a_historical_foreign_job_order_and_nothing_else(): void
    {
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $jobB = $this->jobOrder($shopB->id, $this->karigar($shopB->id, 'KarigarB'), $ownerB->id, 'JO-B-9');
        [$ownerA, $shopA] = $this->createRetailerTenant();
        $karigarA = $this->karigar($shopA->id, 'KarigarA');
        $jobA = $this->jobOrder($shopA->id, $karigarA, $ownerA->id, 'JO-A-9');
        $this->karigarInvoice($ownerA, $shopA, $karigarA, $jobA, 'KI-A-OWN')->assertSessionHasNoErrors();
        $foreign = DB::table('karigar_invoices')->insertGetId([   // as the pre-fix route wrote it
            'shop_id' => $shopA->id, 'karigar_id' => $karigarA, 'job_order_id' => $jobB, 'karigar_invoice_number' => 'KI-A-OLD',
            'karigar_invoice_date' => now()->toDateString(), 'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $rows = DB::select(self::S3_15_CHECK);

        $this->assertSame([$foreign], array_map(fn ($r) => (int) $r->id, $rows));
        $this->assertSame((int) $shopB->id, (int) $rows[0]->job_order_shop_id);
    }
    /**
     * The same class of row, for every reference between shop-owned tables:
     * the audit must name exactly the two injected references, count one row
     * each, and write nothing. A control with both shops' own references only
     * must pass.
     */
    public function test_the_foreign_reference_audit_finds_every_injected_cross_shop_reference_and_writes_nothing(): void
    {
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $accountB = $this->account($shopB->id, 'ShopBUpi');
        $jobB = $this->jobOrder($shopB->id, $this->karigar($shopB->id, 'KarigarB'), $ownerB->id, 'JO-B-7');
        [$ownerA, $shopA, $customerA, $itemA] = $this->retailShopReadyToSell();
        $this->sell($ownerA, $shopA, $customerA->id, $itemA->id, $this->account($shopA->id, 'ShopAUpi')->id)->assertOk();
        $karigarA = $this->karigar($shopA->id, 'KarigarA');
        $this->karigarInvoice($ownerA, $shopA, $karigarA, $this->jobOrder($shopA->id, $karigarA, $ownerA->id, 'JO-A-7'), 'KI-A-OWN7')
            ->assertSessionHasNoErrors();

        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('tenant:audit-foreign-references'), 'control: own references only');

        $invoiceId = (int) DB::table('invoices')->where('shop_id', $shopA->id)->value('id');
        DB::table('invoice_payments')->insert([   // as the pre-S3-14 route wrote it
            'invoice_id' => $invoiceId, 'shop_id' => $shopA->id, 'mode' => 'upi', 'amount' => 1,
            'payment_method_id' => $accountB->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('karigar_invoices')->insert([   // as the pre-S3-15 route wrote it
            'shop_id' => $shopA->id, 'karigar_id' => $karigarA, 'job_order_id' => $jobB, 'karigar_invoice_number' => 'KI-A-OLD7',
            'karigar_invoice_date' => now()->toDateString(), 'payment_status' => 'unpaid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $counts = fn () => [DB::table('invoice_payments')->count(), DB::table('karigar_invoices')->count(), DB::table('job_orders')->count()];
        $before = $counts();

        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('tenant:audit-foreign-references'));
        $out = \Illuminate\Support\Facades\Artisan::output();

        $this->assertStringContainsString('invoice_payments.payment_method_id -> shop_payment_methods (declared): 1 row(s)', $out);
        $this->assertStringContainsString('karigar_invoices.job_order_id -> job_orders (declared): 1 row(s)', $out);
        $this->assertStringContainsString('crossing shops: 2, rows: 2', $out);
        $this->assertSame($before, $counts(), 'read-only');
    }
    /** A table without shop_id, owned through its parent: a line on shop A's invoice naming shop B's item. */
    public function test_the_foreign_reference_audit_checks_references_of_rows_owned_through_a_parent(): void
    {
        [, $shopB] = $this->createRetailerTenant();
        $itemB = $this->createItem((int) $shopB->id, null, ['design' => 'BravoLineItem']);
        [$ownerA, $shopA, $customerA, $itemA] = $this->retailShopReadyToSell();
        $this->sell($ownerA, $shopA, $customerA->id, $itemA->id, $this->account($shopA->id, 'ShopAUpi2')->id)->assertOk();
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('tenant:audit-foreign-references'), 'control: the sale\'s own line');

        // A draft: lines of a finalized invoice are immutable (trigger).
        $draft = TenantContext::runFor((int) $shopA->id, fn () => \App\Models\Invoice::issue([
            'shop_id' => $shopA->id, 'customer_id' => $customerA->id, 'gold_rate' => 7200, 'subtotal' => 1, 'gst' => 0, 'total' => 1,
            'status' => \App\Models\Invoice::STATUS_DRAFT,
        ]));
        // Keep the line's own id well away from its invoice's, so a report that
        // printed the parent's id as the line's would be caught.
        DB::statement("select setval('invoice_items_id_seq', (select coalesce(max(id), 0) from invoice_items) + 5000)");
        $lineId = (int) DB::table('invoice_items')->insertGetId([
            'invoice_id' => $draft->id, 'item_id' => $itemB->id, 'weight' => 1, 'rate' => 1, 'making_charges' => 0,
            'stone_amount' => 0, 'line_total' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertNotSame((int) $draft->id, $lineId);

        $this->assertSame(1, \Illuminate\Support\Facades\Artisan::call('tenant:audit-foreign-references'));
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('invoice_items.item_id -> items (through invoices.invoice_id): 1 row(s)', $out);
        $this->assertStringContainsString(
            "invoice_items {$lineId}: invoice_id -> invoices {$draft->id} (shop {$shopA->id}), item_id -> items {$itemB->id} (shop {$shopB->id})", $out);
    }

    /**
     * Production, 2026-09-25: the audit reported two shop_notifications rows
     * naming another shop's invoice. Both had invoice_type = quick_bill, and
     * their invoice_id was a quick bill of their own shop: the column is
     * polymorphic by invoice_type, so reading it as a key to `invoices`
     * because of its name was wrong. It is listed as not covered instead.
     */
    public function test_the_foreign_reference_audit_does_not_read_a_polymorphic_id_by_its_name(): void
    {
        [, $shopA] = $this->createRetailerTenant();
        [$ownerB, $shopB] = $this->createRetailerTenant();
        $invoiceA = TenantContext::runFor((int) $shopA->id, fn () => Invoice::issue([
            'shop_id' => $shopA->id, 'customer_id' => $this->createCustomer((int) $shopA->id)->id, 'gold_rate' => 7200, 'subtotal' => 1,
            'gst' => 0, 'total' => 1, 'status' => Invoice::STATUS_DRAFT,
        ]));
        // Shop B's own quick bill, with the same id as shop A's invoice, and B's sale notification for it.
        DB::table('quick_bills')->insert(['id' => $invoiceA->id, 'shop_id' => $shopB->id, 'bill_sequence' => 1, 'bill_number' => 'QB-POLY-1',
            'bill_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shop_notifications')->insert(['shop_id' => $shopB->id, 'recipient_user_id' => $ownerB->id, 'type' => 'sale', 'counter_type' => 'quick_bill',
            'actor_name' => 'B', 'amount' => 1, 'invoice_id' => $invoiceA->id, 'invoice_type' => 'quick_bill', 'created_at' => now(), 'updated_at' => now()]);

        $code = \Illuminate\Support\Facades\Artisan::call('tenant:audit-foreign-references');
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringNotContainsString('shop_notifications.invoice_id -> invoices', $out);
        $this->assertStringContainsString('shop_notifications.invoice_id (polymorphic by invoice_type)', $out);
    }
}
