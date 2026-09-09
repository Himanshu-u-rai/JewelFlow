<?php

namespace Tests\Feature\Returns;

use App\Models\CashTransaction;
use App\Models\CreditNote;
use App\Models\EntityEvent;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\ReturnOrder;
use App\Models\ShopPreferences;
use App\Services\EntityEventService;
use App\Services\InvoiceAccountingService;
use App\Services\Returns\ReturnService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * The audit trail must never be able to kill a sale.
 *
 * EntityEventService already had a try/catch that logged and returned null in
 * production — and it did not work. ReturnOrderObserver::saved() fires inside
 * ReturnService's DB::transaction(), and on Postgres one failed statement
 * aborts the whole transaction: every statement after it, COMMIT included, is
 * refused with 25P02. Catching the exception in PHP does not un-poison the
 * connection, so the refund died anyway, further downstream, with an error
 * that pointed nowhere near the audit subsystem.
 *
 * The audit statements now run inside their own savepoint. These tests break
 * the audit subsystem outright and assert the money still moves.
 */
class AuditFailureDoesNotBreakReturnTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private ReturnService $returns;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
        $this->returns = app(ReturnService::class);
    }

    /** Simulate a dead audit subsystem the same way a bad deploy would. */
    private function breakAuditTable(): void
    {
        Schema::rename('entity_events', 'entity_events_broken');
    }

    private function repairAuditTable(): void
    {
        if (Schema::hasTable('entity_events_broken')) {
            Schema::rename('entity_events_broken', 'entity_events');
        }
    }

    private function configurePolicy(int $shopId): void
    {
        ShopPreferences::withoutTenant()->updateOrCreate(
            ['shop_id' => $shopId],
            [
                'refund_making_charges' => true, 'refund_stone_charges' => true,
                'refund_gst' => true, 'wear_loss_pct' => 0, 'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit',
            ],
        );
    }

    private function finalizedInvoice(int $shopId, int $customerId, Item $item): Invoice
    {
        return TenantContext::runFor($shopId, function () use ($shopId, $customerId, $item) {
            $draft = new Invoice();
            $draft->forceFill([
                'shop_id' => $shopId, 'customer_id' => $customerId,
                'gold_rate' => 7200, 'subtotal' => 0, 'gst' => 0, 'gst_rate' => 3,
                'wastage_charge' => 0, 'discount' => 0, 'round_off' => 0, 'total' => 0,
                'status' => Invoice::STATUS_DRAFT,
            ])->save();

            $price = (float) $item->selling_price;
            InvoiceItem::record([
                'invoice_id' => $draft->id, 'item_id' => $item->id,
                'metal_type' => $item->metal_type, 'weight' => (float) $item->gross_weight,
                'rate' => 0, 'making_charges' => (float) $item->making_charges,
                'stone_amount' => (float) $item->stone_charges, 'hallmark_charges' => 0,
                'line_total' => $price, 'gst_rate' => 3,
                'gst_amount' => round($price * 0.03, 2),
                'allocated_discount' => 0, 'allocated_round_off' => 0, 'allocated_loyalty_pts' => 0,
            ]);

            return InvoiceAccountingService::finalizeDraft($draft);
        });
    }

    /**
     * The headline case: a real cash refund, settled through the real service,
     * with the audit table gone. The customer must still get their money.
     */
    public function test_a_cash_refund_completes_with_the_audit_table_destroyed(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item     = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $invoice  = $this->finalizedInvoice($shop->id, $customer->id, $item);

        $this->app->detectEnvironment(fn () => 'production');
        $this->breakAuditTable();

        try {
            $return = TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn(
                $invoice, 'audit subsystem is down', $owner->id, false, ReturnService::REFUND_SETTLEMENT_CASH));
        } finally {
            $this->repairAuditTable();
            $this->app->detectEnvironment(fn () => 'testing');
        }

        // The return committed.
        $fresh = ReturnOrder::withoutTenant()->findOrFail($return->id);
        $this->assertSame(ReturnOrder::STATUS_SETTLED, $fresh->status);

        // The credit note was issued.
        $this->assertTrue(
            CreditNote::withoutTenant()->where('return_order_id', $return->id)->exists(),
            'credit note must still be issued when the audit trail is dead'
        );

        // The money actually moved.
        $this->assertSame(
            1,
            CashTransaction::withoutTenant()->where('shop_id', $shop->id)
                ->where('source_type', 'credit_note')->count(),
            'the refund cash-out must still be recorded'
        );

        // And the invoice line is marked returned — proof the whole transaction
        // committed rather than silently rolling back.
        $this->assertNotNull(
            InvoiceItem::where('invoice_id', $invoice->id)->first()->returned_at,
            'invoice line should be flagged returned'
        );
    }

    /**
     * The confirmation page must survive too.
     *
     * ReturnsController::show() is where the settle action redirects, and it
     * reads the event feed. The refund has already committed by then, so a 500
     * here tells the operator the refund failed when it did not — and the
     * obvious next move is to refund again. Found in a live browser run, not
     * in review: the money moved and the page still died.
     */
    public function test_the_confirmation_page_renders_after_the_refund_with_audit_dead(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item     = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $invoice  = $this->finalizedInvoice($shop->id, $customer->id, $item);

        $this->app->detectEnvironment(fn () => 'production');
        $this->breakAuditTable();

        try {
            $return = TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn(
                $invoice, 'audit down', $owner->id, false, ReturnService::REFUND_SETTLEMENT_CASH));

            // Exactly the redirect the operator follows after settling. ERP
            // routes are domain-scoped, so the absolute host is required.
            TenantContext::runFor($shop->id, fn () => $this->actingAs($owner)
                ->get('https://jewelflows.com/returns/' . $return->id))->assertOk();
        } finally {
            $this->repairAuditTable();
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    /**
     * Same run, opposite expectation: nothing was quietly swallowed. Once the
     * audit table is back, the trail is simply empty for that return — which is
     * the correct, honest outcome, and is what the log records.
     */
    public function test_the_lost_events_are_absent_rather_than_corrupt(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item     = $this->createItem($shop->id, null, ['selling_price' => 55000]);
        $invoice  = $this->finalizedInvoice($shop->id, $customer->id, $item);

        $this->app->detectEnvironment(fn () => 'production');
        $this->breakAuditTable();

        try {
            TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn(
                $invoice, 'audit down', $owner->id, false, ReturnService::REFUND_SETTLEMENT_CASH));
        } finally {
            $this->repairAuditTable();
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame(
            0,
            EntityEvent::withoutTenant()->where('event_type', 'return_settled')->count(),
            'no half-written or garbage audit rows'
        );
    }

    /**
     * A second return on the same connection must behave normally. If the
     * savepoint had leaked transaction state, this is where it would show.
     */
    public function test_the_connection_is_healthy_for_the_next_return(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->configurePolicy($shop->id);
        $item = $this->createItem($shop->id, null, ['selling_price' => 55000]);

        $customerA = $this->createCustomer($shop->id);
        $invoiceA  = $this->finalizedInvoice($shop->id, $customerA->id, $item);

        $this->app->detectEnvironment(fn () => 'production');
        $this->breakAuditTable();

        try {
            TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn(
                $invoiceA, 'audit down', $owner->id, false, ReturnService::REFUND_SETTLEMENT_CASH));
        } finally {
            $this->repairAuditTable();
            $this->app->detectEnvironment(fn () => 'testing');
        }

        // Audit subsystem restored — a fresh return must now audit normally.
        $itemB     = $this->createItem($shop->id, null, ['selling_price' => 31000]);
        $customerB = $this->createCustomer($shop->id);
        $invoiceB  = $this->finalizedInvoice($shop->id, $customerB->id, $itemB);

        $returnB = TenantContext::runFor($shop->id, fn () => $this->returns->createFullReturn(
            $invoiceB, 'audit back up', $owner->id, false, ReturnService::REFUND_SETTLEMENT_CASH));

        $this->assertSame(ReturnOrder::STATUS_SETTLED,
            ReturnOrder::withoutTenant()->findOrFail($returnB->id)->status);

        $recorded = EntityEvent::withoutTenant()
            ->where('event_type', 'return_settled')
            ->where('entity_type', 'return_order')
            ->where('entity_id', $returnB->id)
            ->first();

        $this->assertNotNull($recorded, 'the recovered subsystem must resume writing events');
        $this->assertIsArray($recorded->snapshot, 'snapshot must round-trip as an array, not the literal "Array"');
    }

    /**
     * Repeated failures inside one transaction must each unwind independently.
     * A savepoint that is opened but never released would run out or leak.
     */
    public function test_many_consecutive_audit_failures_do_not_poison_the_caller(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $events   = app(EntityEventService::class);

        $this->app->detectEnvironment(fn () => 'production');

        try {
            DB::transaction(function () use ($shop, $events) {
                for ($i = 0; $i < 25; $i++) {
                    $this->assertNull($events->record(
                        shopId:     999999,           // FK violation, every time
                        entityType: 'return_order',
                        entityId:   $i,
                        eventType:  'return_settled',
                        summary:    'nope',
                    ));
                }

                // Caller's transaction is still usable after 25 failures.
                DB::table('shop_counters')->insert([
                    'shop_id'       => $shop->id,
                    'counter_key'   => 'audit_hammer_probe',
                    'current_value' => 1,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
            });
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertDatabaseHas('shop_counters', [
            'shop_id' => $shop->id, 'counter_key' => 'audit_hammer_probe',
        ]);
    }

    /**
     * recordForMultiple must not let one bad entity take the others with it.
     *
     * The bad entity is deliberately FIRST: entity_type is varchar(50), so an
     * over-long value fails on insert. Without a savepoint that failure aborts
     * the transaction and the perfectly valid customer event behind it is
     * refused too — one malformed entity silently costs you the whole batch.
     */
    public function test_one_bad_entity_does_not_lose_the_good_ones(): void
    {
        [, $shop] = $this->createRetailerTenant();
        $events   = app(EntityEventService::class);

        $this->app->detectEnvironment(fn () => 'production');

        try {
            DB::transaction(fn () => $events->recordForMultiple(
                shopId:   $shop->id,
                entities: [
                    ['type' => str_repeat('x', 60), 'id' => 1],   // varchar(50) — fails
                    ['type' => 'customer', 'id' => 2],            // must still land
                ],
                eventType: 'sale_finalized',
                summary:   'Sale finalized',
                snapshot:  ['invoice_total' => 1234.5],
            ));
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $good = EntityEvent::withoutTenant()->where('event_type', 'sale_finalized')->get();

        $this->assertCount(1, $good, 'the valid entity behind the bad one must still be recorded');
        $this->assertSame('customer', $good->first()->entity_type);
        $this->assertSame(['invoice_total' => 1234.5], $good->first()->snapshot);
    }

    /**
     * Staging is deliberately on the forgiving side of the allow-list. If it
     * ever starts throwing, an audit bug will take staging sales down and the
     * environment stops being a safe rehearsal for production.
     */
    public function test_staging_swallows_like_production_does(): void
    {
        $this->app->detectEnvironment(fn () => 'staging');

        try {
            $this->assertNull(app(EntityEventService::class)->record(
                shopId:     999999,
                entityType: 'return_order',
                entityId:   1,
                eventType:  'return_settled',
                summary:    'Return settled',
            ));
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
