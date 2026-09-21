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
 * S3-09 — EnsureIdempotency records completion AFTER the controller.
 *
 * The middleware (app/Http/Middleware/EnsureIdempotency.php) runs the
 * controller at line 140 and only then, at line 149, persists the
 * IdempotencyKey row — and only for a 2xx. Two consequences follow, and this
 * file is about proving the *money* consequence of each on a real route
 * rather than asserting it from a code reading:
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
 * SCOPE NOTE — this file does NOT claim all 16 idempotency-protected routes
 * are vulnerable. Source reading established that they are not uniform:
 *
 *   POST /returns          PROTECTED, and MEASURED so. ReturnService carries
 *                          two durable guards — the invoice's own status and
 *                          the per-line `returned_at` stamp — and a replay is
 *                          refused regardless of middleware state. Covered
 *                          here as a control, to keep the finding honest about
 *                          its own blast radius. See that test for which of
 *                          the two guards this fixture actually reaches.
 *
 *   POST /cashbook         UNPROTECTED. No transaction and no dedup guard.
 *                          MEASURED: the retry books a second cash row.
 *
 *   POST /job-orders/../receipt   and   POST /installments/{plan}/pay
 *                          PARTIALLY protected: both lock and both check a
 *                          status, but the status they permit is the one the
 *                          operation leaves behind (PARTIAL_RETURN / active),
 *                          so only the final receipt / final EMI is guarded.
 *                          MEASURED: the retry duplicates in both cases.
 *
 * The remaining 12 idempotency-protected routes are NOT RUN here and must not
 * be assumed to behave like any of these four.
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

        // And no claim was staked, because the middleware only persists a 2xx.
        $this->assertSame(
            0,
            IdempotencyKey::where('shop_id', $shop->id)->count(),
            'expected no idempotency claim for a non-2xx response',
        );

        // The client does what every mobile client does with a 500: retries,
        // same key, same body.
        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/cashbook', $payload, $headers);

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

    /**
     * Arm a one-shot failure on the middleware's OWN claim write.
     *
     * This is the most faithful reproduction available, because it is the
     * exact scenario EnsureIdempotency:166-177 already concedes in a comment:
     * the claim write fails, the middleware fails SOFT, the client gets its
     * 2xx, and "a future retry with the same key will simply re-run". The
     * business write has fully committed by this point — the controller has
     * already returned.
     *
     * Same `return;` discipline as armAuditLogFailure(): a non-null return
     * would halt the event chain.
     */
    private function armClaimWriteFailure(): callable
    {
        $fired = false;

        IdempotencyKey::creating(function () use (&$fired) {
            if ($fired) {
                return;
            }
            $fired = true;
            throw new \RuntimeException('claim write failed after the business commit');
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

        $didFire = $this->armClaimWriteFailure();
        $headers = ['X-Idempotency-Key' => 'emi-pay-fixed-key'];
        $payload = ['amount' => 5000, 'payment_method' => 'cash'];

        $first = $this->postAsTenant((int) $shop->id, "/api/mobile/v1/installments/{$plan->id}/pay", $payload, $headers);

        // The window is real: the payment SUCCEEDED and the claim was lost.
        $first->assertCreated();
        $this->assertTrue($didFire(), 'the injected claim-write failure never fired');
        $this->assertSame(
            0,
            IdempotencyKey::where('shop_id', $shop->id)->where('key', 'emi-pay-fixed-key')->count(),
            'expected the claim write to have been swallowed by the fail-soft path',
        );

        $emiCashBefore = $this->installmentCashCount((int) $shop->id, (int) $plan->id);
        $this->assertSame(1, $emiCashBefore, 'expected exactly one EMI cash-in from the first payment');

        // The client retries the 201 it never saw acknowledged.
        $this->postAsTenant((int) $shop->id, "/api/mobile/v1/installments/{$plan->id}/pay", $payload, $headers);

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

        $didFire = $this->armClaimWriteFailure();
        $headers = ['X-Idempotency-Key' => 'jo-receipt-fixed-key'];
        // Partial: 10g of a 50g issue, so the order stays receivable.
        $payload = ['items' => [['gross_weight' => 10.0, 'pieces' => 1]]];

        $first = $this->postAsTenant((int) $shop->id, "/api/mobile/v1/job-orders/{$jobOrderId}/receipt", $payload, $headers);

        $first->assertCreated();
        $this->assertTrue($didFire(), 'the injected claim-write failure never fired');

        $fineAfterFirst = (float) DB::table('job_orders')->where('id', $jobOrderId)->value('returned_fine_weight');
        $this->assertGreaterThan(0.0, $fineAfterFirst, 'expected the first receipt to have credited fine weight');

        $retry = $this->postAsTenant((int) $shop->id, "/api/mobile/v1/job-orders/{$jobOrderId}/receipt", $payload, $headers);
        $this->assertNotSame(404, $retry->status(), 'the retry never reached the controller — binding lost tenant scope');

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
     * [NEGATIVE CONTROL FOR THE FINDING ITSELF — must be green TODAY]
     *
     * POST /returns runs through the same defective middleware, on the same
     * lost-claim path, and still does NOT double-refund. Durable business
     * state does the deduplication the middleware failed to do.
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
     * double-charge". If a fix to the middleware is later written, this must
     * still pass — and for the SAME reason, which is why it asserts the refusal
     * message and the refund rows rather than only the row counts.
     */
    public function test_control_returns_retry_is_already_protected_by_a_durable_key(): void
    {
        [$user, $shop, , $line] = $this->soldInvoiceForReturn();
        Sanctum::actingAs($user);
        TenantContext::set((int) $shop->id);

        $didFire = $this->armClaimWriteFailure();
        $headers = ['X-Idempotency-Key' => 'ret-fixed-key'];
        $payload = [
            'invoice_id' => $line->invoice_id,
            'reason' => 'Customer changed mind',
            'refund_settlement' => 'cash',
            'lines' => [['invoice_item_id' => $line->id, 'condition' => 'good_condition']],
        ];

        $this->postAsTenant((int) $shop->id, '/api/mobile/v1/returns', $payload, $headers)->assertCreated();
        $this->assertTrue($didFire(), 'the injected claim-write failure never fired');

        $retry = $this->postAsTenant((int) $shop->id, '/api/mobile/v1/returns', $payload, $headers);

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
