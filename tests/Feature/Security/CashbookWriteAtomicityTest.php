<?php

namespace Tests\Feature\Security;

use App\Models\AuditLog;
use App\Models\CashDrawerCheck;
use App\Models\CashTransaction;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-10 — the cash write and its audit write must be one transaction.
 *
 * ─── What this finding IS, and what it is NOT ─────────────────────────────
 *
 * `CashBookController::store` writes `CashTransaction::record` and then
 * `AuditLog::create` as two unwrapped statements, and `storeDrawerCheck` has
 * the identical shape. Either pair can half-complete.
 *
 * This is deliberately NOT claimed to be input-driven, and an earlier row in
 * the handoff that called it simply "Measured" overstated the evidence. I
 * checked whether anything the validator accepts could make the second write
 * fail, and it cannot:
 *
 *   * `audit_logs.description` is `text` — no length ceiling to overflow, so
 *     the 100-char `source_type` that feeds it cannot truncate-fail.
 *   * `action` / `model_type` are varchar(255) holding fixed literals
 *     (`cash_in`, `CashTransaction`).
 *   * No CHECK constraints, and no unique index on `prev_hash` / `row_hash`
 *     that concurrent inserts could collide on.
 *   * The only INSERT trigger is `audit_logs_hash_trigger`, which computes a
 *     hash chain and never raises.
 *
 * So unlike S3-11 — where a routine owner settings change fired the defect —
 * there is no user action that reaches this one. The trigger is process-level
 * interruption between the two statements: a dropped connection, a PHP fatal
 * or `max_execution_time`, an OOM kill, a deploy restart. That is the ordinary
 * reason transactions exist, and it does not need a user to cooperate.
 *
 * ─── Why the consequence still justifies the repair ───────────────────────
 *
 * Because NEITHER side can be repaired afterwards:
 *
 *   * `cash_transactions` carries `prevent_ledger_mutation` and an append-only
 *     guard, so the orphaned money row cannot be updated or deleted. Only a
 *     compensating entry can offset it, and that leaves two rows where the
 *     operator made one movement.
 *   * `audit_logs` is append-only AND hash-chained over `prev_hash`. A late
 *     audit row cannot be slotted into its original position; it would land at
 *     the chain tip, out of order, permanently misdating the record.
 *
 * So a half-completed pair is not a transient inconsistency that a retry or a
 * reconciliation job can settle. It is a permanent one, in the two tables the
 * constitution protects most strongly.
 *
 * ─── Evidence class: SIMULATED INTERRUPTION ───────────────────────────────
 *
 * The failure below is INJECTED via an `AuditLog::creating` hook. It fires at
 * the real seam — between the two real statements, through the real route —
 * but it is a stand-in for a process death, not a reproduction of one. It is
 * therefore simulated-interruption coverage, in the same class as the
 * conflict-path test, and it does NOT establish that any such interruption has
 * ever occurred in this system.
 *
 * What it does establish is the atomicity property itself: given a failure at
 * that seam, no money row survives it.
 */
class CashbookWriteAtomicityTest extends TestCase
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
     * POST with the tenant context freshly re-established.
     *
     * `EnsureTenantUser` clears TenantContext in a `finally`, and under PHPUnit
     * `BelongsToShop::resolveTenantShopId()` returns null early on
     * `runningInConsole()` rather than falling back to the authenticated user.
     * Without the re-set, a second request in the same test resolves nothing.
     * Test-environment characteristic, not a production bug.
     */
    private function postAsTenant(int $shopId, string $uri, array $payload, array $headers): \Illuminate\Testing\TestResponse
    {
        TenantContext::set($shopId);

        return $this->withHeaders($headers)->postJson($uri, $payload);
    }

    /**
     * Count rows WITHOUT the tenant scope.
     *
     * Load-bearing: the HTTP request clears TenantContext on its way out, and
     * BelongsToShop fails closed to `whereRaw('1 = 0')`. A plain scoped count
     * after a request returns 0 regardless of what is in the table — which is
     * exactly the assertion these tests make, so a scoped query would satisfy
     * them for entirely the wrong reason.
     */
    private function unscopedCount(string $model, int $shopId): int
    {
        return $model::withoutTenant()->where('shop_id', $shopId)->count();
    }

    /**
     * Arm a one-shot failure on AuditLog creation.
     *
     * The bare `return;` on the already-fired path is load-bearing. Eloquent
     * dispatches model events with `until()` — halt on first non-null — so
     * returning `true` here to mean "carry on" would instead SUPPRESS every
     * listener registered after it, including BelongsToShop's own `creating`
     * hook that fills shop_id.
     *
     * Returns a closure reporting whether it actually fired, so each test can
     * assert the injection happened rather than trusting it.
     *
     * Both closures capture `$fired` BY REFERENCE, and the reporter cannot be
     * shortened to `fn () => $fired`. An arrow function binds by value at
     * creation time, so it would capture `false` and keep reporting `false`
     * forever — and PHP has no `fn () use (&$x)` to opt out of that. I wrote it
     * the short way first: the injection fired, the route returned the injected
     * 500, and the reporter still said it had not. The `assertTrue($didFire())`
     * guard is what caught that, which is the entire reason it exists.
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

    // ────────────────────────────────────────────────────────────────────
    // The defect
    // ────────────────────────────────────────────────────────────────────

    /**
     * [SIMULATED INTERRUPTION at the cash/audit seam]
     *
     * The assertion that matters is the last one. The two before it prove the
     * test exercised the seam it claims to, so a green run cannot be the
     * accidental result of the request never having written anything.
     */
    public function test_a_failed_audit_write_must_not_leave_an_orphaned_cash_row(): void
    {
        [, $shop] = $this->actAsOwner();

        $didFire = $this->armAuditLogFailure();

        $response = $this->postAsTenant(
            (int) $shop->id,
            '/api/mobile/v1/cashbook',
            $this->cashPayload(),
            ['X-Idempotency-Key' => 'cb-atomicity-entry'],
        );

        $this->assertTrue($didFire(), 'The audit failure never fired — the test proved nothing.');
        $this->assertSame(500, $response->getStatusCode(), 'Expected the injected failure to surface as a 500.');

        $this->assertSame(
            0,
            $this->unscopedCount(CashTransaction::class, (int) $shop->id),
            'A cash movement survived a failed audit write. cash_transactions is immutable and '
                . 'audit_logs is hash-chained, so neither side can be corrected afterwards.',
        );
    }

    /**
     * [SIMULATED INTERRUPTION at the drawer-check/audit seam]
     *
     * `storeDrawerCheck` has the same unwrapped shape as `store`. Included
     * because fixing only the route the finding names would leave its sibling
     * broken — both live in the same controller and share the defect exactly.
     */
    public function test_a_failed_audit_write_must_not_leave_an_orphaned_drawer_check(): void
    {
        [, $shop] = $this->actAsOwner();

        $didFire = $this->armAuditLogFailure();

        $response = $this->postAsTenant(
            (int) $shop->id,
            '/api/mobile/v1/cashbook/drawer-check',
            ['counted_cash' => 18400.00, 'note' => 'Evening count'],
            ['X-Idempotency-Key' => 'cb-atomicity-drawer'],
        );

        $this->assertTrue($didFire(), 'The audit failure never fired — the test proved nothing.');
        $this->assertSame(500, $response->getStatusCode(), 'Expected the injected failure to surface as a 500.');

        $this->assertSame(
            0,
            $this->unscopedCount(CashDrawerCheck::class, (int) $shop->id),
            'A drawer check survived a failed audit write; cash_drawer_checks is append-only.',
        );
    }

    // ────────────────────────────────────────────────────────────────────
    // Positive controls — the repair must not buy atomicity by writing less
    // ────────────────────────────────────────────────────────────────────

    /**
     * Without the injection, a single entry still writes BOTH rows.
     *
     * This is the control that stops the fix from passing the tests above by
     * suppressing the writes rather than by making them atomic.
     */
    public function test_control_an_unobstructed_entry_writes_both_the_cash_row_and_its_audit_row(): void
    {
        [, $shop] = $this->actAsOwner();

        $this->postAsTenant(
            (int) $shop->id,
            '/api/mobile/v1/cashbook',
            $this->cashPayload(),
            ['X-Idempotency-Key' => 'cb-atomicity-control'],
        )->assertStatus(201);

        $this->assertSame(1, $this->unscopedCount(CashTransaction::class, (int) $shop->id));
        $this->assertSame(1, $this->unscopedCount(AuditLog::class, (int) $shop->id));
    }

    /** Same control for the drawer-check sibling. */
    public function test_control_an_unobstructed_drawer_check_writes_both_rows(): void
    {
        [, $shop] = $this->actAsOwner();

        $this->postAsTenant(
            (int) $shop->id,
            '/api/mobile/v1/cashbook/drawer-check',
            ['counted_cash' => 18400.00, 'note' => 'Evening count'],
            ['X-Idempotency-Key' => 'cb-atomicity-drawer-control'],
        )->assertStatus(201);

        $this->assertSame(1, $this->unscopedCount(CashDrawerCheck::class, (int) $shop->id));
        $this->assertSame(1, $this->unscopedCount(AuditLog::class, (int) $shop->id));
    }
}
