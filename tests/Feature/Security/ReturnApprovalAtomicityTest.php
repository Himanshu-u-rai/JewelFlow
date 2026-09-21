<?php

namespace Tests\Feature\Security;

use App\Models\CreditNote;
use App\Models\IdempotencyKey;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Permission;
use App\Models\ReturnLineItem;
use App\Models\ReturnOrder;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopPreferences;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-11 — `ReturnService::approveReturn` persists a cancellation and can then
 * fail, leaving the return unrecoverable.
 *
 * ─── Why this file exists, and what question it answers ───────────────────
 *
 * S3-09 made `EnsureIdempotency` stake its claim before the controller. The
 * claim is RELEASED on a 4xx and RETAINED on a 5xx. The release side rested on
 * an assumption that had never been checked: *that a 4xx means the controller
 * refused and wrote nothing*. Retaining the old "all 4xx release" behaviour
 * does not itself establish that.
 *
 * Checking it splits the 4xx responses on these routes into three classes:
 *
 *   A. Refused by EnsureIdempotency ITSELF, before the claim is staked — a
 *      missing or malformed key (422), no authenticated user (401), a payload
 *      conflict or an in-flight claim (409). No claim row exists yet, so
 *      "release" is not even reachable. Safe by construction.
 *
 *   B. Refused AFTER the claim is staked but before any business write — the
 *      route-level `can:` gates (which run after the group's
 *      `mobile.idempotency`, so a 403 does reach the release path),
 *      route-model-binding 404s, `abort_if` shop-scope checks, and
 *      FormRequest/`$request->validate()` 422s. The claim is released and
 *      nothing was written. Safe, and the release is what makes the corrected
 *      resubmit work.
 *
 *   C. The controller ran, PERSISTED something, and then returned a 4xx.
 *      Releasing the key here hands back a key for an operation that partly
 *      happened.
 *
 * Class C was not assumed. It was looked for, and it exists — once, on this
 * route. `ReturnController::approve` catches `LogicException` from the service
 * and converts it to a 422 (ReturnController.php:244-250), and
 * `ReturnService::approveReturn` has NO transaction around these two steps:
 *
 *     // ReturnService.php:582 — committed immediately, nothing wrapping it
 *     DB::table('return_orders')->where('id', $returnOrder->id)->update([
 *         'status' => ReturnOrder::STATUS_CANCELLED, ...
 *     ]);
 *
 *     // ReturnService.php:588 — and this can throw LogicException at five
 *     // sites BEFORE its own DB::transaction opens (lines 90, 93, 97, 98,
 *     // 104, 107), so there is nothing to roll the cancellation back.
 *     return $this->createPartialReturn(...);
 *
 * ─── The consequence is NOT a duplicate ───────────────────────────────────
 *
 * This matters for how the finding is framed, and the honest answer runs
 * against the direction the S3-09 work was pointing. Releasing the key here
 * cannot double anything: the retry re-enters `approveReturn`, finds the order
 * already CANCELLED rather than PENDING_APPROVAL, and is refused again.
 *
 * The damage is the opposite failure. The customer's pending return is
 * DESTROYED — cancelled, never settled, and no longer approvable by any route.
 * No credit note, no restock, no refund, and no supported way back.
 *
 * So the defect is in the SERVICE's atomicity, not in the middleware's 4xx
 * policy, and the fix belongs there. The middleware is deliberately left
 * alone: broadening it to retain keys on 4xx would break class B (a corrected
 * resubmit after a validation failure) to work around one non-atomic service.
 *
 * @see app/Services/Returns/ReturnService.php
 * @see app/Http/Middleware/EnsureIdempotency.php
 */
class ReturnApprovalAtomicityTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ── Fixture helpers, mirroring Tests\Feature\Mobile\V1\ReturnsApiTest ──

    private function idempotency(string $tag): array
    {
        return ['X-Idempotency-Key' => 'approve-' . $tag . '-' . uniqid()];
    }

    /**
     * POST the approve route with tenant context re-established.
     *
     * `EnsureTenantUser` clears TenantContext in a `finally`, so context set
     * before one request is gone by the next. Without re-setting it,
     * route-model binding resolves `{returnOrder}` under a global scope that
     * has failed closed and returns 404 — which would silently turn every
     * assertion below into "the request never reached the controller".
     *
     * This is a TEST-ENVIRONMENT characteristic, not a production bug:
     * `BelongsToShop::resolveTenantShopId()` consults
     * `app()->runningInConsole()` before falling back to the authenticated
     * user's shop_id, and that is true under PHPUnit and false under FPM.
     */
    private function approve(Shop $shop, ReturnOrder $order, string $tag, ?string $key = null)
    {
        TenantContext::set((int) $shop->id);

        return $this->withHeaders(
            $key !== null ? ['X-Idempotency-Key' => $key] : $this->idempotency($tag),
        )->postJson("/api/mobile/v1/returns/{$order->id}/approve");
    }

    private function grant(User $user, string ...$perms): void
    {
        $role = Role::withoutTenant()->findOrFail($user->role_id);
        foreach ($perms as $p) {
            $role->givePermission($p);
        }
    }

    private function configureReturnPolicy(Shop $shop, array $overrides = []): void
    {
        TenantContext::runFor($shop->id, function () use ($shop, $overrides) {
            $prefs = ShopPreferences::firstOrNew(['shop_id' => $shop->id]);
            $prefs->forceFill(array_merge([
                'shop_id' => $shop->id,
                'refund_making_charges' => true,
                'refund_stone_charges' => true,
                'refund_gst' => true,
                'wear_loss_pct' => 0,
                'restocking_fee_pct' => 0,
                'return_settlement_mode' => 'cash_or_credit',
                'return_policy_configured_at' => now(),
            ], $overrides))->save();
        });
    }

    /** Sell an item on the manufacturer path → [owner, shop, Invoice, line]. */
    private function soldInvoice(): array
    {
        $this->withoutMiddleware([
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);

        [$user, $shop] = $this->createManufacturerTenant();
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

    /**
     * Park a return in pending_approval through the REAL mobile route, so the
     * row under test is one production actually produces — including the
     * `pending_data` blob that approval later replays.
     */
    private function pendingReturn(): array
    {
        [$owner, $shop, $invoice, $line] = $this->soldInvoice();

        // A ₹1 threshold is below any real refund, so approval is always required.
        $this->configureReturnPolicy($shop, ['approval_threshold_amount' => 1]);
        $this->grant($owner, 'returns.approve', 'sales.create');

        Sanctum::actingAs($owner);
        TenantContext::set((int) $shop->id);

        $this->withHeaders($this->idempotency('create'))
            ->postJson('/api/mobile/v1/returns', [
                'invoice_id' => $line->invoice_id,
                'reason' => 'Customer changed mind',
                'refund_settlement' => 'cash',
                'lines' => [[
                    'invoice_item_id' => $line->id,
                    'condition' => ReturnLineItem::CONDITION_GOOD,
                ]],
            ])->assertStatus(201);

        $order = ReturnOrder::withoutGlobalScopes()
            ->where('invoice_id', $invoice->id)
            ->firstOrFail();

        $this->assertSame(
            ReturnOrder::STATUS_PENDING_APPROVAL,
            $order->status,
            'fixture precondition: the return must start in pending_approval',
        );

        return [$owner, $shop, $invoice, $line, $order];
    }

    // ── S3-11 ─────────────────────────────────────────────────────────────

    /**
     * The failure, driven by a change an owner is entitled to make.
     *
     * The return was parked with `refund_settlement = 'cash'`. Before anyone
     * approves it the owner tightens shop policy to store-credit-only —
     * a legitimate settings change, not an injected fault. Approval now
     * replays the stored 'cash' settlement against the new policy and
     * `createPartialReturn` throws at ReturnService.php:107, BEFORE its
     * transaction opens.
     *
     * By then `approveReturn` has already committed the cancellation.
     */
    public function test_a_failed_approval_must_not_leave_the_return_cancelled(): void
    {
        [$owner, $shop, $invoice, $line, $order] = $this->pendingReturn();

        $this->configureReturnPolicy($shop, [
            'approval_threshold_amount' => 1,
            'return_settlement_mode' => 'store_credit_only',
        ]);

        $response = $this->approve($shop, $order, 'fail');

        // The refusal itself is correct and expected — policy really does
        // forbid this settlement. Nothing here argues the 422 is wrong.
        $response->assertStatus(422);

        $after = ReturnOrder::withoutGlobalScopes()->findOrFail($order->id);

        $this->assertSame(
            ReturnOrder::STATUS_PENDING_APPROVAL,
            $after->status,
            'S3-11: approveReturn committed the cancellation at ReturnService.php:582 and then '
                . 'failed in createPartialReturn, with no transaction to roll it back. The customer\'s '
                . 'pending return is destroyed: not settled, and no longer approvable.',
        );

        // And nothing settled on the way past, either.
        $this->assertSame(
            0,
            CreditNote::withoutGlobalScopes()->where('return_order_id', $order->id)->count(),
            'a failed approval must issue no credit note',
        );
        $this->assertNull(
            $line->fresh()->returned_at,
            'a failed approval must not stamp the invoice line as returned',
        );
    }

    /**
     * The consequence that makes it unrecoverable, stated separately.
     *
     * This is the part that turns a bad 422 into lost work. Once the header is
     * CANCELLED, `approveReturn`'s own guard (ReturnService.php:568) refuses
     * every future attempt — so correcting the policy back does NOT restore
     * the ability to approve.
     */
    public function test_the_return_is_still_approvable_after_the_policy_is_corrected(): void
    {
        [$owner, $shop, $invoice, $line, $order] = $this->pendingReturn();

        $this->configureReturnPolicy($shop, [
            'approval_threshold_amount' => 1,
            'return_settlement_mode' => 'store_credit_only',
        ]);

        $this->approve($shop, $order, 'fail')->assertStatus(422);

        // The owner realises the mistake and puts the policy back.
        $this->configureReturnPolicy($shop, [
            'approval_threshold_amount' => 1,
            'return_settlement_mode' => 'cash_or_credit',
        ]);

        $retry = $this->approve($shop, $order, 'retry');

        $retry->assertStatus(200);

        $this->assertSame(
            1,
            CreditNote::withoutGlobalScopes()
                ->whereIn('return_order_id', ReturnOrder::withoutGlobalScopes()
                    ->where('invoice_id', $invoice->id)->pluck('id'))
                ->count(),
            'the corrected approval must settle exactly one credit note',
        );
    }

    /**
     * Classifies the 4xx as a class-B release, and pins that classification.
     *
     * The retry above only reaches the controller because EnsureIdempotency
     * released the claim on the 422. That release is correct — this asserts it
     * directly rather than inferring it from the retry succeeding, so the two
     * facts cannot silently merge into one.
     */
    public function test_a_4xx_releases_the_idempotency_claim_so_a_corrected_resubmit_can_run(): void
    {
        [$owner, $shop, $invoice, $line, $order] = $this->pendingReturn();

        $this->configureReturnPolicy($shop, [
            'approval_threshold_amount' => 1,
            'return_settlement_mode' => 'store_credit_only',
        ]);

        $key = 'approve-release-probe-' . uniqid();

        $this->approve($shop, $order, 'release', $key)->assertStatus(422);

        $this->assertSame(
            0,
            IdempotencyKey::withoutGlobalScopes()->where('key', $key)->count(),
            'a 4xx must release the claim, otherwise the operator could never correct and resend',
        );
    }

    /**
     * Positive control — the ordinary approval still works end to end.
     *
     * Without this, every assertion above could be satisfied by an approval
     * path that is simply broken for all inputs.
     */
    public function test_control_an_unobstructed_approval_settles_the_return(): void
    {
        [$owner, $shop, $invoice, $line, $order] = $this->pendingReturn();

        $response = $this->approve($shop, $order, 'ok');

        $response->assertStatus(200);
        $this->assertNotNull($line->fresh()->returned_at, 'an approved return stamps the line');
    }
}
