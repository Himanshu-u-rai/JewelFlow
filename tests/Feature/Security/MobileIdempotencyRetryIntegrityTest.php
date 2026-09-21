<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\IdempotencyKey;
use App\Models\InstallmentPayment;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\ShopPreferences;
use App\Services\RetailerSalesService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-09 — EnsureIdempotency recorded completion AFTER the controller.
 *
 * ORIGINAL DEFECT. The middleware ran the controller first and only then
 * persisted the IdempotencyKey row — and only for a 2xx. Two consequences
 * followed, and this file proves the *money* consequence of each on a real
 * route rather than asserting it from a code reading:
 *
 *   (a) CRASH WINDOW. Any failure after the business write commits but before
 *       the key row is written leaves no claim. The client's retry — the same
 *       key, the same payload — misses the lookup and re-runs the controller.
 *
 *   (b) CONCURRENCY. Two simultaneous same-key requests both miss the lookup
 *       (nothing has been staked yet) and both reach the controller. The
 *       unique index on the key row only decides which of the two *records*
 *       its completion; both have already moved money by then.
 *
 * REPAIR. The claim is now staked BEFORE the controller, with a sentinel
 * `response_status` of 0 meaning in-flight. A same-key request that finds an
 * in-flight claim is refused with 409 `idempotency_in_flight` rather than
 * being re-run, and the unique index now admits exactly one request to the
 * controller. A 5xx deliberately LEAVES the claim in flight, because the
 * router pipeline converts a controller exception into a response and the
 * middleware therefore cannot tell "died before writing" from "wrote, then
 * died". A 4xx is a deliberate controller refusal with nothing written, so
 * the key is released and stays retryable.
 *
 * These tests are the post-repair regression evidence. Each asserts BOTH that
 * the retry is refused (409, `idempotency_in_flight`) AND — the assertion
 * that actually matters — that the money/metal/stock rows stayed at one.
 *
 * SCOPE NOTE — this file does NOT claim all 16 idempotency-protected routes
 * were vulnerable. Source reading established that they are not uniform, and
 * the repair does not make them uniform — it only removes the middleware's
 * contribution. Each route keeps whatever business-level protection it had:
 *
 *   POST /returns          PROTECTED AT THE SERVICE LAYER, and MEASURED so.
 *                          ReturnService carries two durable guards — the
 *                          invoice's own status and the per-line `returned_at`
 *                          stamp — and a replay is refused regardless of
 *                          middleware state. Covered here as a control, to
 *                          keep the finding honest about its own blast radius.
 *                          See that test for which of the two guards this
 *                          fixture actually reaches.
 *
 *   POST /cashbook         NO SERVICE-LAYER PROTECTION AT ALL. No transaction
 *                          and no dedup guard. Pre-repair, MEASURED: the retry
 *                          booked a second cash row. The middleware is now the
 *                          only thing standing between this route and a
 *                          duplicate — which is why its test asserts the cash
 *                          row count, not just the 409.
 *
 *   POST /job-orders/../receipt   and   POST /installments/{plan}/pay
 *                          PARTIALLY protected: both lock and both check a
 *                          status, but the status they permit is the one the
 *                          operation leaves behind (PARTIAL_RETURN / active),
 *                          so only the final receipt / final EMI is guarded.
 *                          Pre-repair, MEASURED: the retry duplicated in both
 *                          cases.
 *
 * The remaining 12 idempotency-protected routes are NOT RUN here. They share
 * the repaired middleware, so the crash-window and concurrency defects are
 * closed for them too, but their own service-layer duplicate behaviour is
 * UNVERIFIED and must not be assumed to match any of these four.
 *
 * Each test states which of these it is evidence for.
 */
class MobileIdempotencyRetryIntegrityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    private function actAsOwner(): array
    {
        [$owner, $shop] = $this->createRetailerTenant();
        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        return [$owner, $shop];
    }

    /**
     * POST to a mobile v1 route with the tenant context freshly established.
     *
     * The re-set before EVERY request is mandatory, not tidiness, and a test
     * that omits it can go green while proving nothing:
     *
     *   EnsureTenantUser sets TenantContext from the authenticated user and
     *   CLEARS IT IN A `finally` (EnsureTenantUser.php:28-33). In production
     *   the next request re-establishes it, and even route-model binding —
     *   which runs in SubstituteBindings, BEFORE the `tenant` route middleware
     *   — still resolves, because BelongsToShop::resolveTenantShopId() falls
     *   back to Auth::user()->shop_id.
     *
     *   Under PHPUnit that fallback is unreachable: resolveTenantShopId()
     *   returns null early on app()->runningInConsole(), which is TRUE in the
     *   test runner. So the SECOND request of any test starts with no context,
     *   every tenant model's global scope becomes `whereRaw('1 = 0')`, and a
     *   bound route parameter 404s.
     *
     * An earlier draft of the job-order test did exactly that. It passed —
     * because the retry 404'd on binding, not because the receipt was
     * deduplicated. A duplicate-prevention test whose retry never reaches the
     * controller is worthless, and it is worthless SILENTLY.
     */
    private function postAsTenant(int $shopId, string $uri, array $payload, array $headers): \Illuminate\Testing\TestResponse
    {
        TenantContext::set($shopId);

        return $this->withHeaders($headers)->postJson($uri, $payload);
    }

    /**
     * Count ledger rows WITHOUT the tenant scope.
     *
     * Not a convenience. CashTransaction and AuditLog use BelongsToShop, which
     * fails CLOSED — an unset TenantContext rewrites the query to
     * `whereRaw('1 = 0')`. The HTTP request clears the context on its way out,
     * so a plain `CashTransaction::where('shop_id', ...)` after a request
     * counts zero no matter what is in the table.
     *
     * That matters here beyond mere correctness: the natural way to write
     * "the retry did not duplicate" is `assertSame(0, ...)`, and a blind query
     * satisfies that assertion for entirely the wrong reason. Every count in
     * this file therefore goes through an explicitly unscoped query, and the
     * shop_id filter is applied by hand.
     */
    private function ledgerCount(string $model, int $shopId, array $where = []): int
    {
        $query = $model::withoutTenant()->where('shop_id', $shopId);
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    private function ledgerSum(string $model, int $shopId, string $column): float
    {
        return (float) $model::withoutTenant()->where('shop_id', $shopId)->sum($column);
    }

    /**
     * Count the EMI cash-ins this plan produced.
     *
     * Deliberately narrow: InstallmentService::recordPayment writes its
     * CashTransaction with source_type 'installment' and source_id = plan id
     * (InstallmentService.php:141-151), so this counts the EMI money movement
     * only and cannot be satisfied by unrelated drawer activity.
     */
    private function installmentCashCount(int $shopId, int $planId): int
    {
        return CashTransaction::withoutTenant()
            ->where('shop_id', $shopId)
            ->where('source_type', 'installment')
            ->where('source_id', $planId)
            ->count();
    }

    /** Mirrors InstallmentApiTest::setupDraft — a real POS-EMI draft sale. */
    private function setupEmiDraft(): array
    {
        [$user, $shop] = $this->createRetailerTenant();
        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $draft = TenantContext::runFor($shop->id, function () use ($user, $customer, $item) {
            $this->actingAs($user);

            return RetailerSalesService::prepareEmiDraftSale(customerId: $customer->id, itemIds: [$item->id]);
        });

        return [$user, $shop, $draft];
    }

    /**
     * Plant an ISSUED job order with 50g out at the karigar.
     *
     * Mirrors KarigarApiTest::test_receipt_requires_idempotency_key's fixture.
     * Written with the query builder on purpose: JobOrder carries BelongsToShop
     * and we want the row to exist regardless of the ambient tenant context.
     */
    private function plantIssuedJobOrder(int $shopId, int $userId): int
    {
        $karigarId = DB::table('karigars')->insertGetId([
            'shop_id' => $shopId,
            'name' => 'Ramesh',
            'mobile' => '9800000001',
            // DB::raw('true'): PostgreSQL rejects PHP's 1 for a boolean column
            // ("you will need to rewrite or cast the expression"). Eloquent
            // dodges this via $casts; the query builder does not.
            'is_active' => DB::raw('true'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('job_orders')->insertGetId([
            'shop_id' => $shopId,
            'karigar_id' => $karigarId,
            'job_order_number' => 'JO-S309-001',
            'challan_number' => 'CH-S309-001',
            'metal_type' => 'gold',
            'purity' => 22,
            'issued_gross_weight' => 50,
            'issued_fine_weight' => 45.83,
            'expected_return_fine_weight' => 44.93,
            'allowed_wastage_percent' => 2,
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
            'created_by_user_id' => $userId,
        ]);
    }

    /** Mirrors ReturnsApiTest::soldInvoice — a real finalized sale with locked allocations. */
    private function soldInvoiceForReturn(): array
    {
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);

        [$user, $shop] = $this->createManufacturerTenant();

        TenantContext::runFor($shop->id, function () use ($shop) {
            ShopPreferences::firstOrNew(['shop_id' => $shop->id])->forceFill([
                'shop_id' => $shop->id,
                'refund_making_charges' => true,
                'refund_stone_charges' => true,
                'refund_gst' => true,
                'wear_loss_pct' => 0,
                'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit',
                'return_policy_configured_at' => now(),
            ])->save();
        });

        $lot = $this->createMetalLot($shop->id);
        $customer = $this->createCustomer($shop->id);
        $item = $this->createItem($shop->id, $lot->id);

        $preview = $this->actingAs($user)->postJson('/api/price-preview', [
            'item_id' => $item->id, 'customer_id' => $customer->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
        ]);
        $total = (float) $preview->json('total');

        $sell = $this->actingAs($user)->postJson('/pos/sell', [
            'customer_id' => $customer->id, 'item_id' => $item->id,
            'gold_rate' => 6000, 'making' => 500, 'stone' => 200, 'discount' => 0, 'round_off' => 0,
            'payments' => [['mode' => 'cash', 'amount' => $total]],
        ])->assertOk();

        $invoice = TenantContext::runFor($shop->id, fn () => Invoice::findOrFail($sell->json('invoice_id')));
        $line = TenantContext::runFor($shop->id, fn () => InvoiceItem::where('invoice_id', $invoice->id)->firstOrFail());

        return [$user, $shop, $invoice, $line];
    }

    private function cashPayload(): array
    {
        return [
            'type' => 'in',
            'amount' => 2500.00,
            'source_type' => 'other',
            'payment_mode' => 'cash',
            'description' => 'Counter sale float top-up',
        ];
    }

    /**
     * Arm a one-shot failure on AuditLog creation.
     *
     * This is a GENUINE INJECTED FAILURE, not a simulation, and it detonates
     * at a real seam: CashBookController::store writes the CashTransaction at
     * line 165 and the AuditLog at line 176, with no transaction around the
     * pair. Throwing from the second write reproduces exactly what a process
     * death between those two statements would leave behind.
     *
     * Returns a closure reporting whether it actually fired, so the test can
     * assert the injection happened instead of trusting it.
     *
     * The bare `return;` on the already-fired path is load-bearing. Eloquent
     * dispatches model events with `until()` — halt-on-first-non-null — so a
     * listener that returns `true` to mean "carry on" instead SUPPRESSES every
     * listener registered after it, including BelongsToShop's own `creating`
     * hook that fills shop_id. An earlier draft returned `true` here and the
     * retry died on a NOT NULL violation that had nothing to do with the
     * defect under test.
     */
    private function armAuditLogFailure(): callable
    {
        $fired = false;

        AuditLog::creating(function () use (&$fired) {
            if ($fired) {
                return;
            }
            $fired = true;
            throw new \RuntimeException('process died after the cash write, before the audit write');
        });

        return function () use (&$fired) {
            return $fired;
        };
    }

    // ────────────────────────────────────────────────────────────────────
    // S3-09 (a) — the crash window, on the unprotected route
    // ────────────────────────────────────────────────────────────────────

    /**
     * [INJECTED FAILURE — after the cash write commits, before the key row]
     *
     * The assertion that matters is the LAST one: cash_transactions stays at
     * 1 across the retry. Everything before it is there to prove the test is
     * exercising the window it claims to, so that a green run cannot be the
     * accidental result of the first request never having written anything.
     */
    public function test_s309a_cashbook_retry_after_an_interrupted_first_attempt_must_not_move_cash_twice(): void
    {
        [, $shop] = $this->actAsOwner();
        $headers = ['X-Idempotency-Key' => 'cb-s309a-fixed-key'];
        $payload = $this->cashPayload();

        $didFire = $this->armAuditLogFailure();

        $first = $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $payload, $headers);

        // The window is real: the injected failure fired, and the request did
        // not succeed.
        $this->assertTrue($didFire(), 'the injected AuditLog failure never fired — the window was not exercised');
        $this->assertSame(500, $first->status());

        // The money write survived the failure. This is the no-transaction
        // defect in CashBookController::store, visible on its own: cash moved
        // and the audit row that is supposed to explain it does not exist.
        $this->assertSame(
            1,
            $this->ledgerCount(CashTransaction::class, (int) $shop->id),
            'expected the cash row to have been committed before the failure',
        );

        // The claim was staked BEFORE the controller and is still in flight:
        // the 5xx path deliberately does not release it, because from here
        // "died before writing" and "wrote, then died" are the same response.
        // The cash row above proves this instance is the second kind.
        $claim = IdempotencyKey::where('shop_id', $shop->id)->where('key', 'cb-s309a-fixed-key')->sole();
        $this->assertSame(0, (int) $claim->response_status, 'expected the claim to be left in flight by the 5xx');

        // The client does what every mobile client does with a 500: retries,
        // same key, same body.
        $retry = $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $payload, $headers);

        $this->assertSame(409, $retry->status());
        $this->assertSame('idempotency_in_flight', $retry->json('errors.0.code'));

        $this->assertSame(
            1,
            $this->ledgerCount(CashTransaction::class, (int) $shop->id),
            'DUPLICATE CASH: the retry re-ran the controller and moved money a second time',
        );
        $this->assertSame(
            2500.00,
            $this->ledgerSum(CashTransaction::class, (int) $shop->id, 'amount'),
            'DUPLICATE CASH: the drawer total reflects the entry twice',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // S3-09 (b) — the concurrency window
    // ────────────────────────────────────────────────────────────────────

    /**
     * [INJECTED RACE — a sibling stakes the same key between our lookup and
     * our insert]
     *
     * WHY THIS IS NOT A REAL RACE, AND WHY THAT IS FINE. A single-process
     * PHPUnit run cannot issue two simultaneous requests, and a test that
     * pretends otherwise usually proves nothing. So this does not reproduce
     * the timing — it reproduces the STATE the timing produces. The listener
     * raw-inserts a conflicting claim from inside `creating`, which is after
     * the middleware's lookup found nothing and before its own insert lands.
     * That is precisely where a losing concurrent sibling sits.
     *
     * Pre-repair there was no insert at that point at all: the claim was
     * written after the controller, so both siblings sailed past the lookup
     * and both moved money, and the unique index only decided which of the
     * two got to record a response. Post-repair the index is load-bearing —
     * it is what admits exactly one request to the controller.
     *
     * The assertion that carries the weight is `$cashWriteAttempted`. A 409
     * alone would still be green if the loser had run the controller and
     * moved money before the middleware got around to answering.
     *
     * WHY A SPY AND NOT A ROW COUNT. Every other test here counts rows after
     * the request. This one cannot: RefreshDatabase wraps the test in a
     * single transaction, and the unique violation aborts it, so every
     * subsequent read dies with SQLSTATE[25P02]. That is a harness artefact,
     * NOT a production concern — the middleware runs outside any transaction,
     * so PostgreSQL rolls back the failed statement alone and leaves the
     * connection usable. A model-event spy gives stronger evidence anyway:
     * it witnesses the controller not executing, rather than inferring it.
     */
    public function test_s309b_a_second_concurrent_request_for_one_key_must_not_reach_the_controller(): void
    {
        [$owner, $shop] = $this->actAsOwner();
        $headers = ['X-Idempotency-Key' => 'cb-s309b-race-key'];
        $payload = $this->cashPayload();

        $cashWriteAttempted = false;
        CashTransaction::creating(function () use (&$cashWriteAttempted) {
            $cashWriteAttempted = true;

            return;
        });

        $planted = false;
        IdempotencyKey::creating(function ($model) use (&$planted, $shop, $owner) {
            if ($planted) {
                return;
            }
            $planted = true;

            // The sibling that won the race, staked and still in flight.
            DB::table('idempotency_keys')->insert([
                'shop_id' => (int) $shop->id,
                'user_id' => (int) $owner->id,
                'key' => $model->key,
                'request_hash' => $model->request_hash,
                'response_status' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Bare return, not `true`. A non-null return halts Eloquent's
            // `until()` chain and would suppress every later listener.
            return;
        });

        $loser = $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $payload, $headers);

        $this->assertTrue($planted, 'the conflicting claim was never planted — the race window was not exercised');
        $this->assertSame(409, $loser->status(), 'expected the losing request to be refused on the unique index');
        $this->assertSame('idempotency_in_flight', $loser->json('errors.0.code'));

        $this->assertFalse(
            $cashWriteAttempted,
            'DUPLICATE CASH: the losing concurrent request reached the controller and moved money',
        );
    }

    /**
     * [POSITIVE CONTROL FOR THE SPY ABOVE]
     *
     * The race test's central assertion is that a flag stayed FALSE. A flag
     * that can never go true would make it green forever and prove nothing —
     * the same false-pass shape as an `assertSame(0, ...)` against a query
     * that cannot match. This pins the spy to a request that really does
     * write cash, so the negative above is load-bearing.
     */
    public function test_control_the_cash_write_spy_fires_on_an_unobstructed_request(): void
    {
        [, $shop] = $this->actAsOwner();

        $cashWriteAttempted = false;
        CashTransaction::creating(function () use (&$cashWriteAttempted) {
            $cashWriteAttempted = true;

            return;
        });

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), [
            'X-Idempotency-Key' => 'cb-s309b-spy-control-key',
        ])->assertCreated();

        $this->assertTrue($cashWriteAttempted, 'the spy never fired — the race test above proves nothing');
    }

    /**
     * Arm a one-shot failure on the middleware's claim COMPLETION write.
     *
     * Targets the exact window the repair leaves behind. Post-repair the
     * claim is staked BEFORE the controller, so the only remaining gap is:
     * claim staked in flight → controller commits → the update that records
     * the outcome never lands. That is what a process death after a
     * successful mutation looks like, and it is the window a retry must not
     * be allowed to re-run.
     *
     * `updating`, not `creating`: the stake must succeed, or the controller
     * never runs and there is no mutation to duplicate.
     *
     * Same `return;` discipline as armAuditLogFailure(): a non-null return
     * would halt the event chain and suppress later listeners.
     */
    private function armClaimCompletionFailure(): callable
    {
        $fired = false;

        IdempotencyKey::updating(function () use (&$fired) {
            if ($fired) {
                return;
            }
            $fired = true;
            throw new \RuntimeException('process died after the business commit, before recording the outcome');
        });

        return function () use (&$fired) {
            return $fired;
        };
    }

    /**
     * [INJECTED FAILURE — the middleware's own fail-soft claim-write path]
     *
     * InstallmentService::recordPayment IS transactional and DOES lock the
     * plan (InstallmentService.php:71-76), so this is not a missing-lock bug.
     * The guard it carries — `status === 'active'` plus a remaining-balance
     * ceiling — simply is not an idempotency guard, because 'active' is the
     * state a non-final EMI payment LEAVES the plan in. The duplicate is
     * therefore accepted as a perfectly legitimate second instalment.
     *
     * Asserted consequences are the money rows, not the response: a second
     * InstallmentPayment, a second InvoicePayment against the sale, a second
     * cash-in, and an emis_paid counter advanced twice for one payment.
     */
    public function test_s309a_installment_pay_retry_must_not_take_a_second_emi(): void
    {
        [$user, $shop, $draft] = $this->setupEmiDraft();
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/installments/finalize', [
            'invoice_id' => $draft->id,
            'down_payment' => 0,
            'total_emis' => 6,
            'interest_rate_annual' => 0,
        ], ['X-Idempotency-Key' => 'emi-finalize-key'])->assertCreated();

        $plan = InstallmentPlan::withoutTenant()->where('invoice_id', $draft->id)->firstOrFail();

        $didFire = $this->armClaimCompletionFailure();
        $headers = ['X-Idempotency-Key' => 'emi-pay-fixed-key'];
        $payload = ['amount' => 5000, 'payment_method' => 'cash'];

        $first = $this->postAsTenant((int) $shop->id, "/api/mobile/v1/installments/{$plan->id}/pay", $payload, $headers);

        // The window is real: the payment SUCCEEDED and the claim was lost.
        $first->assertCreated();
        $this->assertTrue($didFire(), 'the injected claim-completion failure never fired');

        // completeClaim() is fail-soft on purpose: the money already moved and
        // the claim row already holds the key, so a failure here degrades the
        // retry to a refusal, never to a re-run. The claim is left in flight.
        $claim = IdempotencyKey::where('shop_id', $shop->id)->where('key', 'emi-pay-fixed-key')->sole();
        $this->assertSame(0, (int) $claim->response_status, 'expected the lost completion to leave the claim in flight');

        $emiCashBefore = $this->installmentCashCount((int) $shop->id, (int) $plan->id);
        $this->assertSame(1, $emiCashBefore, 'expected exactly one EMI cash-in from the first payment');

        // The client retries the 201 it never saw acknowledged.
        $retry = $this->postAsTenant((int) $shop->id, "/api/mobile/v1/installments/{$plan->id}/pay", $payload, $headers);

        $this->assertSame(409, $retry->status());
        $this->assertSame('idempotency_in_flight', $retry->json('errors.0.code'));

        $this->assertSame(
            1,
            InstallmentPayment::withoutTenant()->where('plan_id', $plan->id)->count(),
            'DUPLICATE EMI: the retry recorded a second instalment payment',
        );
        $this->assertSame(
            1,
            $this->installmentCashCount((int) $shop->id, (int) $plan->id),
            'DUPLICATE CASH: the retry booked a second EMI cash-in',
        );
        $this->assertSame(
            1,
            (int) InstallmentPlan::withoutTenant()->whereKey($plan->id)->value('emis_paid'),
            'DOUBLE-COUNTED EMI: emis_paid advanced twice for one customer payment',
        );
    }

    /**
     * [INJECTED FAILURE — the middleware's own fail-soft claim-write path]
     *
     * The metal analogue, and the most consequential of the four: a duplicate
     * receipt does not merely re-record a number, it CREATES STOCK. Each
     * replayed line inserts a fresh `items` row marked in_stock and a fresh
     * `metal_movements` row of type 'manufacture', and advances the job
     * order's returned_fine_weight — so the shop's metal balance gains grams
     * that no karigar ever handed over.
     *
     * The receipt is deliberately PARTIAL. JobOrderService::receive does lock
     * and does guard on status (JobOrderService.php:574-583), but the statuses
     * it permits are ISSUED and PARTIAL_RETURN — and a partial receipt leaves
     * the order in PARTIAL_RETURN. The guard only closes on the final receipt.
     */
    public function test_s309a_job_order_receipt_retry_must_not_manufacture_metal_twice(): void
    {
        [$user, $shop] = $this->createRetailerTenant();
        $this->grantOnlyPermissions($user, ['job_order.manage']);
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $jobOrderId = $this->plantIssuedJobOrder((int) $shop->id, (int) $user->id);

        $didFire = $this->armClaimCompletionFailure();
        $headers = ['X-Idempotency-Key' => 'jo-receipt-fixed-key'];
        // Partial: 10g of a 50g issue, so the order stays receivable.
        $payload = ['items' => [['gross_weight' => 10.0, 'pieces' => 1]]];

        $first = $this->postAsTenant((int) $shop->id, "/api/mobile/v1/job-orders/{$jobOrderId}/receipt", $payload, $headers);

        $first->assertCreated();
        $this->assertTrue($didFire(), 'the injected claim-completion failure never fired');

        $fineAfterFirst = (float) DB::table('job_orders')->where('id', $jobOrderId)->value('returned_fine_weight');
        $this->assertGreaterThan(0.0, $fineAfterFirst, 'expected the first receipt to have credited fine weight');

        $retry = $this->postAsTenant((int) $shop->id, "/api/mobile/v1/job-orders/{$jobOrderId}/receipt", $payload, $headers);

        // Asserting the exact refusal, not merely "not a duplicate". An earlier
        // draft of this test passed on a 404 — the retry never reached the
        // controller at all because it had lost tenant scope, so of course
        // nothing duplicated. Pinning 409/idempotency_in_flight makes that
        // failure mode impossible to mistake for the behaviour under test.
        $this->assertSame(409, $retry->status(), 'expected the in-flight claim to refuse the retry');
        $this->assertSame('idempotency_in_flight', $retry->json('errors.0.code'));

        $this->assertSame(
            1,
            DB::table('job_order_receipts')->where('job_order_id', $jobOrderId)->count(),
            'DUPLICATE RECEIPT: the retry issued a second goods-receipt document',
        );
        $this->assertSame(
            1,
            DB::table('items')->where('job_order_id', $jobOrderId)->count(),
            'PHANTOM STOCK: the retry created a second saleable item from one delivery',
        );
        $this->assertSame(
            1,
            DB::table('metal_movements')
                ->where('reference_type', 'job_order')
                ->where('reference_id', $jobOrderId)
                ->where('type', 'manufacture')
                ->count(),
            'PHANTOM METAL: the retry recorded a second manufacture movement',
        );
        $this->assertEqualsWithDelta(
            $fineAfterFirst,
            (float) DB::table('job_orders')->where('id', $jobOrderId)->value('returned_fine_weight'),
            0.0001,
            'PHANTOM METAL: the job order was credited the same grams twice',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // Controls — these must stay green, before and after any fix
    // ────────────────────────────────────────────────────────────────────

    /**
     * [NEGATIVE CONTROL FOR THE FINDING ITSELF]
     *
     * POST /returns does NOT double-refund even with the middleware taken
     * entirely out of the picture. Durable business state does the
     * deduplication on its own.
     *
     * HOW THE MIDDLEWARE IS EXCLUDED — the retry carries a DIFFERENT
     * idempotency key. That is deliberate and it is the only honest way to
     * run this control post-repair. A same-key retry is now answered by the
     * middleware (a replay, or a 409 if the claim is in flight) and never
     * reaches ReturnService at all, so it would prove nothing about the
     * service. A fresh key is also the realistic client behaviour: an app
     * that lost its response and re-composes the request from scratch mints a
     * new key, and that request must still be refused. Middleware replay
     * itself is covered by tests/Feature/Mobile/V1/IdempotencyMiddlewareTest.
     *
     * WHICH GUARD ACTUALLY FIRES — measured, not assumed. ReturnService has
     * two durable guards, and this fixture exercises the outer one:
     *
     *   invoice status   The fixture invoice has ONE line, so returning it is
     *                    a full return and the invoice leaves `finalized`.
     *                    The retry is refused at ReturnService.php:90 with
     *                    "Only finalized invoices can have items returned."
     *                    This is the guard asserted below.
     *
     *   invoice_items.returned_at   The per-line guard at ReturnService.php:
     *                    141-148, reached only on a PARTIAL return where the
     *                    invoice stays finalized. NOT RUN — this fixture
     *                    cannot reach it, and no multi-line finalized-invoice
     *                    fixture exists yet on the mobile return path.
     *
     * An earlier draft of this docblock credited the `returned_at` guard here.
     * The test proved otherwise by failing on the message assertion; the claim
     * is corrected rather than the assertion loosened.
     *
     * This test exists to stop S3-09 from being reported as "all 16 routes
     * double-charge". It asserts the refusal MESSAGE and the refund rows, not
     * only the row counts, so that it cannot pass for some unrelated reason.
     */
    public function test_control_returns_retry_is_already_protected_by_a_durable_key(): void
    {
        [$user, $shop, , $line] = $this->soldInvoiceForReturn();
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $payload = [
            'invoice_id' => $line->invoice_id,
            'reason' => 'Customer changed mind',
            'refund_settlement' => 'cash',
            'lines' => [['invoice_item_id' => $line->id, 'condition' => 'good_condition']],
        ];

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/returns', $payload, [
            'X-Idempotency-Key' => 'ret-first-attempt-key',
        ])->assertCreated();

        // Fresh key: the middleware has no claim to match, stakes a new one,
        // and hands the request straight to the controller. Whatever refuses
        // it from here is the service.
        $retry = $this->postAsTenant((int) $shop->id, '/api/mobile/v1/returns', $payload, [
            'X-Idempotency-Key' => 'ret-second-attempt-key',
        ]);

        // Refused by the service, not by the middleware.
        $retry->assertStatus(422);
        $this->assertSame('return_creation_failed', $retry->json('errors.0.code'));
        $this->assertStringContainsString(
            'Only finalized invoices can have items returned',
            (string) $retry->json('errors.0.message'),
        );

        $this->assertSame(
            1,
            DB::table('return_orders')->where('invoice_id', $line->invoice_id)->count(),
            'expected exactly one return order',
        );
        $this->assertSame(
            1,
            DB::table('return_line_items')->where('invoice_item_id', $line->id)->count(),
            'expected exactly one refunded line',
        );
    }

    /**
     * [AUTHORIZED SUCCESS CONTROL]
     *
     * The ordinary path still works: one request, one cash row, one audit row,
     * one claim. A fix that makes the test above pass by breaking this one is
     * not a fix.
     */
    public function test_control_a_single_authorized_cashbook_entry_still_succeeds(): void
    {
        [, $shop] = $this->actAsOwner();

        $response = $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), ['X-Idempotency-Key' => 'cb-control-ok']);

        $response->assertStatus(201);
        $this->assertSame(1, $this->ledgerCount(CashTransaction::class, (int) $shop->id));
        $this->assertSame(2500.00, $this->ledgerSum(CashTransaction::class, (int) $shop->id, 'amount'));
        $this->assertSame(1, IdempotencyKey::where('shop_id', $shop->id)->count());
        $this->assertSame(
            1,
            $this->ledgerCount(AuditLog::class, (int) $shop->id, ['action' => 'cash_in']),
        );
    }

    /**
     * [LEGITIMATE DISTINCT-OPERATION CONTROL]
     *
     * Two genuinely different entries, two different keys, two cash rows. The
     * cashier who banks 2500 twice in one shift must be able to. A fix that
     * collapses distinct operations into one is a worse bug than the one it
     * replaces.
     */
    public function test_control_two_distinct_keys_record_two_separate_cash_entries(): void
    {
        [, $shop] = $this->actAsOwner();

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), ['X-Idempotency-Key' => 'cb-distinct-one'])
            ->assertStatus(201);

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $this->cashPayload(), ['X-Idempotency-Key' => 'cb-distinct-two'])
            ->assertStatus(201);

        $this->assertSame(2, $this->ledgerCount(CashTransaction::class, (int) $shop->id));
        $this->assertSame(5000.00, $this->ledgerSum(CashTransaction::class, (int) $shop->id, 'amount'));
    }
}
