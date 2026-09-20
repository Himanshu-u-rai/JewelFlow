<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-07b — RETRY INTEGRITY on the mobile payment idempotency cache.
 *
 * DELIBERATELY A SEPARATE FILE FROM THE ACCESS-CONTROL ONE.
 * `InvoicePaymentIdempotencyScopeTest` asks "who may read this cached
 * receipt?". This file asks a question that has nothing to do with identity:
 * "does the cache actually make a retry safe for the caller who is entitled to
 * make it?" Mixing them would let a green access-control run imply a
 * correctness property it never tested. Every caller below is fully
 * authorized; nobody is attacking anything.
 *
 * WHAT THESE TESTS ARE
 * --------------------
 * CHARACTERIZATION. They passed the first time they ran, and that is stated up
 * front rather than dressed up as TDD. They exist to convert three suspicions
 * into demonstrated behaviour, because "this looks racy" is a lead, not a
 * finding. Each one asserts what the code does TODAY. If the fix sketched at
 * the bottom is ever applied, R-01 and R-02 SHOULD fail — that failure is the
 * repair landing.
 *
 * THE MECHANISM UNDER TEST
 * ------------------------
 * `Api\Mobile\InvoiceController::storePayment` does, in order:
 *
 *     1. Cache::get(invoice_payment_idempotency:{invoice}:{key})   -- hit? return
 *     2. DB::transaction { lockForUpdate, validate, INSERT payments, COMMIT }
 *     3. Cache::put(same key, response, 24h)
 *
 * Steps 2 and 3 are not coordinated. The database commit is durable; the cache
 * write is a separate, later, best-effort operation against a different store.
 * Any interleaving or failure that leaves step 2 done and step 3 not done
 * leaves the system with a committed payment and no idempotency record — and
 * the next retry of the same key is then indistinguishable from a first
 * request.
 *
 * TWO CAUSES, ONE OBSERVABLE CONSEQUENCE. The gap can be opened by a crash,
 * a cache eviction, a Redis blip, or by two concurrent first requests both
 * completing step 1 before either reaches step 3. R-01 models the CONSEQUENCE
 * directly — cache entry absent, key retried — rather than pretending PHPUnit
 * can schedule two real HTTP workers. That is a deliberate limitation and is
 * named here rather than left for a reader to discover: what is demonstrated
 * is "a miss on a previously-processed key double-charges", and the race is
 * one documented way to reach that miss, not something this file observes.
 *
 * WHY THE OVERPAYMENT GUARD DOES NOT SAVE IT
 * ------------------------------------------
 * `storePayment` refuses a payment that would push the total past
 * `outstanding`. On a PARTIAL payment there is headroom by definition, so the
 * duplicate lands inside it and no guard fires. R-01 pays 3,000 twice against
 * a 10,000 invoice: 6,000 recorded, both rows legitimate-looking, no error
 * anywhere. Full payment is the lucky case where the guard happens to catch it.
 *
 * THE COMPARISON THAT MAKES THIS A FINDING RATHER THAN A PREFERENCE
 * ----------------------------------------------------------------
 * This repository already contains the correct mechanism. `EnsureIdempotency`
 * (app/Http/Middleware/EnsureIdempotency.php) is backed by the
 * `idempotency_keys` TABLE, claims the key atomically, and its docblock states
 * the contract this cache does not implement:
 *
 *     "Replay (same key + same payload hash): return cached response...
 *      Conflict (same key + DIFFERENT payload hash): 409. This catches the bug
 *      where a client retries with a stale key against a now-mutated payload."
 *
 * R-02 shows the legacy path has no such check: the same key sent with a
 * DIFFERENT amount returns the first receipt and silently records nothing. The
 * operator is told the payment succeeded. It did not. This is the lead I
 * consider most worth acting on, because unlike R-01 it needs no crash, no
 * race and no eviction — it is reachable by a client that reuses a key by
 * mistake, which is precisely what idempotency keys are supposed to make safe.
 *
 * NOT FIXED HERE, AND WHY. The repair is a migration of this route onto the
 * existing `idempotency_keys` mechanism with a compatibility window that READS
 * the legacy cache key and WRITES the new record, so keys in flight across the
 * deploy are not orphaned. That is a behaviour change to a live money path,
 * separable from the access-control repair already committed, and it is
 * offered for its own review rather than smuggled in beside one.
 */
class InvoicePaymentRetryIntegrityTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    private const ROUTE = '/api/mobile/invoices/%d/payments';

    // -------------------------------------------------------------------- R-01
    /**
     * Commit durable, cache record absent → the retry pays again.
     *
     * The `Cache::forget` below is not the bug; it is the STAND-IN for every
     * way step 3 can fail to happen after step 2 succeeded — the process dying
     * between commit and put, the entry being evicted under memory pressure,
     * the cache store being briefly unreachable, or a second concurrent first
     * request having already read a miss. From the controller's point of view
     * all of those are identical: a key it has already processed reads as
     * absent.
     */
    public function test_r01_a_processed_key_whose_cache_record_is_absent_charges_a_second_time(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $key = 'retry-key-0001';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, $this->paymentCount($invoice->id));
        $this->assertSame(3000.0, $this->paidTotal($invoice->id));

        // Step 3 never durably happened.
        Cache::forget("invoice_payment_idempotency:{$invoice->id}:{$key}");

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        // CHARACTERIZATION of today's behaviour, not an endorsement of it.
        // The same idempotency key produced two payments and the customer is
        // recorded as having paid 6,000 against a 3,000 collection.
        $this->assertSame(
            2,
            $this->paymentCount($invoice->id),
            'characterization: a cache miss on an already-processed key is reprocessed'
        );
        $this->assertSame(6000.0, $this->paidTotal($invoice->id));
    }

    // -------------------------------------------------------------------- R-02
    /**
     * Same key, DIFFERENT amount → the first receipt is replayed and the new
     * payment is silently dropped.
     *
     * No crash, no race, no eviction needed. `EnsureIdempotency` answers 409
     * for exactly this case; the legacy cache answers 200 with a receipt for a
     * payment the operator did not just take.
     */
    public function test_r02_the_same_key_with_a_different_amount_silently_records_nothing(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $key = 'stale-key-0001';

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => $key])->assertCreated();

        $this->actAs($owner);
        $second = $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 4500.00,
        ], ['X-Idempotency-Key' => $key]);

        // 200, not 409: the payload is never compared.
        $second->assertOk();

        // The body describes the FIRST payment. An operator reading this
        // screen is told 4,500 went through.
        $this->assertSame(3000.0, (float) $second->json('payment.amount'));
        $this->assertSame(3000.0, (float) $second->json('totals.paid_amount'));

        $this->assertSame(1, $this->paymentCount($invoice->id));
        $this->assertSame(
            3000.0,
            $this->paidTotal($invoice->id),
            'characterization: the 4,500 was never recorded and no error was raised'
        );
    }

    // -------------------------------------------------------------------- R-03
    /**
     * The control that keeps R-01 and R-02 honest.
     *
     * Two DIFFERENT keys are two different payments and both must land. Without
     * this, a future "fix" that refused every second payment against an invoice
     * would make R-01 and R-02 pass while breaking part-payment collection
     * entirely — which is a normal, supported flow for this endpoint.
     */
    public function test_r03_two_distinct_keys_record_two_distinct_payments(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, 10000.00);

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 3000.00,
        ], ['X-Idempotency-Key' => 'distinct-key-0001'])->assertCreated();

        $this->actAs($owner);
        $this->postJson(sprintf(self::ROUTE, $invoice->id), [
            'mode' => 'cash',
            'amount' => 4500.00,
        ], ['X-Idempotency-Key' => 'distinct-key-0002'])->assertCreated();

        $this->assertSame(2, $this->paymentCount($invoice->id));
        $this->assertSame(7500.0, $this->paidTotal($invoice->id));
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Authenticate and arm the tenant context for ONE request. Under PHPUnit
     * `EnsureTenantUser` clears the context in its finally at the end of each
     * request and nothing re-sets it, so every request here re-arms. See the
     * console note in InvoicePaymentIdempotencyScopeTest.
     */
    private function actAs(User $user): void
    {
        Sanctum::actingAs($user);
        TenantContext::set((int) $user->shop_id);
    }

    private function finalizedInvoice(int $shopId, float $total): Invoice
    {
        $customer = $this->createCustomer($shopId);

        $invoice = new Invoice();
        // Article I: Invoice money columns are GUARDED.
        $invoice->forceFill([
            'shop_id' => $shopId,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-RETRY-'.$shopId.'-'.uniqid(),
            'status' => Invoice::STATUS_FINALIZED,
            'gold_rate' => 6000,
            'subtotal' => $total,
            'total' => $total,
            'gst' => 0,
            'gst_rate' => 0,
            'finalized_at' => now(),
        ])->save();

        return $invoice;
    }

    private function paymentCount(int $invoiceId): int
    {
        return (int) \DB::table('invoice_payments')->where('invoice_id', $invoiceId)->count();
    }

    private function paidTotal(int $invoiceId): float
    {
        return (float) \DB::table('invoice_payments')->where('invoice_id', $invoiceId)->sum('amount');
    }
}
