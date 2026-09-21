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
 * WHAT THESE TESTS WERE, AND WHAT THEY ARE NOW
 * --------------------------------------------
 * They began as CHARACTERIZATION. They passed the first time they ran, and that
 * was stated up front rather than dressed up as TDD. They existed to convert
 * three suspicions into demonstrated behaviour, because "this looks racy" is a
 * lead, not a finding. The docblock then said:
 *
 *     "If the fix sketched at the bottom is ever applied, R-01 and R-02 SHOULD
 *      fail — that failure is the repair landing."
 *
 * THE FIX HAS BEEN APPLIED AND THEY DID FAIL. Measured, before inverting them:
 *
 *     R-01  Failed asserting that 1 is identical to 2.      (2 payments → 1)
 *     R-02  Failed asserting that 409 is identical to 200.  (silent → refused)
 *     R-03  still passing, untouched — the control held.
 *
 * R-01 and R-02 now assert the REPAIRED behaviour and serve as regression
 * guards. Their original assertions are quoted inline at each site rather than
 * deleted, so the defect that justified the change is still legible. The
 * finding IDs are deliberately unchanged so the tracker keeps continuity.
 *
 * The desired-behaviour tests written RED first for this repair are in
 * `InvoicePaymentRetryRepairTest` (P-01…P-11); this file is the historical
 * record plus two guards, not the primary evidence for the fix.
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
 * HOW THE REPAIR DIFFERED FROM WHAT THIS FILE ORIGINALLY PROPOSED
 * ---------------------------------------------------------------
 * This docblock used to propose "a migration of this route onto the existing
 * `idempotency_keys` mechanism". That proposal was WRONG and is withdrawn.
 * Inspecting `EnsureIdempotency` rather than trusting its docblock showed two
 * disqualifying problems:
 *
 *   - it records its key AFTER `$next($request)` returns, outside the
 *     controller's transaction — the same uncoordinated shape as the cache
 *     write, so adopting it would not have fixed R-01 at all; and
 *   - it is UNIQUE on (shop_id, user_id, key), which is WIDER than the legacy
 *     (invoice_id, key). Adopting it would have let two users in one shop
 *     charge the same key twice — a regression introduced by the repair.
 *
 * What shipped instead is a dedicated `invoice_payment_claims` record, keyed
 * exactly (invoice_id, key), written INSIDE the payment transaction. The
 * compatibility window over the legacy cache key is as described — it still
 * READS the legacy entry — with one limit that cannot be engineered away and is
 * documented at `InvoicePaymentRetryRepairTest::test_p11...`: legacy entries
 * carry no request hash, so for them a changed payload cannot be detected.
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
    // Renamed when the assertions were inverted. The old name,
    // `..._charges_a_second_time`, described the DEFECT, and a test whose name
    // contradicts its own assertions is read as a bug in the test. The R-01 ID
    // is preserved so the finding mapping still resolves.
    public function test_r01_a_processed_key_whose_cache_record_is_absent_does_not_charge_again(): void
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
        ], ['X-Idempotency-Key' => $key])
            // 200, NOT 201. Before the fix this retry CREATED a second payment
            // and 201 was the honest answer. It is now a replay of the durable
            // claim, and a replay is not a creation. This line failed with
            //
            //     Failed asserting that 200 is identical to 201.
            //
            // which is the same repair landing as the assertion below, observed
            // on the status line instead of the row count.
            ->assertOk()
            ->assertHeader('X-Idempotent-Replay', 'true');

        // REPAIRED — this assertion was INVERTED when the fix landed.
        //
        // It previously read `assertSame(2, ...)` with the note "characterization:
        // a cache miss on an already-processed key is reprocessed", and 6,000
        // against a 3,000 collection. When the durable claim moved inside the
        // payment transaction this test failed with
        //
        //     Failed asserting that 1 is identical to 2.
        //
        // and that failure is the repair landing, precisely as this file's
        // docblock predicted it would. The assertion now states the required
        // behaviour, so it guards the fix instead of recording the defect.
        $this->assertSame(
            1,
            $this->paymentCount($invoice->id),
            'REPAIRED: the durable claim answers the retry; no second INSERT'
        );
        $this->assertSame(3000.0, $this->paidTotal($invoice->id));
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
    // Renamed for the same reason as R-01; was `..._silently_records_nothing`.
    public function test_r02_the_same_key_with_a_different_amount_is_refused_as_a_conflict(): void
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

        // REPAIRED — this assertion was INVERTED when the fix landed.
        //
        // It previously read `$second->assertOk()` and then asserted the body
        // described the FIRST payment (3,000), with the note "characterization:
        // the 4,500 was never recorded and no error was raised". The operator
        // was shown a success for a collection that had been discarded.
        //
        // The fix produced:
        //
        //     Failed asserting that 409 is identical to 200.
        //
        // 409 is now the required answer: a key already used with a different
        // payload is a conflict, not a replay.
        $second->assertStatus(409);

        $this->assertSame(1, $this->paymentCount($invoice->id));
        $this->assertSame(
            3000.0,
            $this->paidTotal($invoice->id),
            'REPAIRED: the 4,500 is refused loudly rather than dropped silently'
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
