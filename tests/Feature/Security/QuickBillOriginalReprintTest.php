<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-05 on the ORIGINAL-reprint path, plus S3-13 found while covering it.
 *
 * ─── Why this file exists ─────────────────────────────────────────────────
 *
 * `FinalizedInvoiceSettingsDriftTest` covers the invoice print and the live
 * quick-bill print. It does not cover `quick-bills.print-original`, and
 * nothing else did either — a grep for `printOriginal` across `tests/` before
 * this file returned nothing. That route is the one place in the system whose
 * entire purpose is to reproduce an as-issued document, so leaving it unbound
 * while claiming S3-05 repaired would be claiming the repair on a path never
 * exercised.
 *
 * `printOriginal` renders a NON-PERSISTED QuickBill that carries the SAME id
 * as the live bill (see `QuickBillController::hydrateFromSnapshot`), and
 * `BillPresentation::forQuickBill` keys its memo on `spl_object_id` rather
 * than on that id.
 *
 * A CLAIM I MADE AND THEN WITHDREW. This docblock first said "Q-01 is what
 * holds that guard in place". It is not. I replaced the key with
 * `$quickBill->id` and re-ran: all three tests stayed green. Checking why
 * rather than leaving it as a puzzle — the guard is UNREACHABLE today, for
 * two compounding reasons:
 *
 *   1. `BillPresentation` has no container binding, so `app()` returns a
 *      FRESH instance on every resolve, and the memo dies with the render.
 *   2. Each template resolves it exactly once and renders exactly one bill.
 *      `printOriginal` and `print` are separate requests.
 *
 * So the memo never holds two quick bills at once and the key cannot collide.
 * `spl_object_id` is correct DEFENSIVE choice — it stays right if the class is
 * ever bound as a singleton or a view ever renders both bills — but no test
 * can bind it without fabricating a scenario that does not exist, and
 * fabricating one would be writing a test to raise a count. It is left
 * unbound, and said so here rather than implied to be covered.
 *
 * ─── What was measured, before anything was asserted ──────────────────────
 *
 * A scratch probe was run first and its output drove every assertion below.
 * Issue a bill under intra-state settings, flip the shop to inter-state and
 * change the terms, then edit the bill's CUSTOMER NAME only:
 *
 *   LIVE shop_snapshot igst_mode: true    ORIG: false
 *   LIVE terms: 'No exchange...'          ORIG: 'Exchange within 7 days.'
 *   live print: IGST, today's terms       original print: CGST, issued terms
 *
 * The first half is the repair working. The second half is S3-13.
 */
class QuickBillOriginalReprintTest extends TestCase
{
    use RefreshDatabase, CreatesTestTenant;

    private const ISSUED_TERMS = 'Exchange within 7 days.';

    private const LATER_TERMS = 'No exchange under any circumstances.';

    protected function setUp(): void
    {
        $this->skipIfNotPostgres();
        parent::setUp();
    }

    // ────────────────────────────────────────────────────────────────────
    // Q-01 — the repair, on the path that had no coverage at all
    // ────────────────────────────────────────────────────────────────────

    /**
     * The frozen original reprints as ISSUED, and the live bill does not.
     *
     * Both halves are load-bearing. Asserting only that the original prints
     * CGST would be satisfied by a system that never re-resolved anything;
     * asserting the live bill prints IGST alongside it is what shows the two
     * documents genuinely resolve from different snapshots in the same test.
     */
    public function test_q01_the_frozen_original_reprints_as_issued_while_the_live_bill_does_not(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, [
            'igst_mode' => DB::raw('false'),
            'terms_and_conditions' => self::ISSUED_TERMS,
        ]);
        $billId = $this->issueQuickBill($owner);

        $this->setBilling($shop->id, [
            'igst_mode' => DB::raw('true'),
            'terms_and_conditions' => self::LATER_TERMS,
        ]);
        $this->editCustomerName($owner, $billId);

        $original = $this->printOriginal($owner, $billId);
        $live = $this->printLive($owner, $billId);

        $this->assertStringContainsString('CGST', $original,
            'the original was issued as an intra-state supply and must reprint that way');
        $this->assertStringNotContainsString('IGST', $original,
            'S3-05: the frozen original must not be re-characterized by a later settings flip');
        $this->assertStringContainsString(self::ISSUED_TERMS, $original,
            'and must carry the terms the customer was actually handed');
        $this->assertStringNotContainsString(self::LATER_TERMS, $original,
            'not whatever the shop configured afterwards');

        $this->assertStringContainsString('IGST', $live,
            'the LIVE bill resolves from its own re-captured snapshot — see Q-02');
        $this->assertStringContainsString(self::LATER_TERMS, $live,
            'so the two documents must be resolving from different snapshots, which is the point');
    }

    /**
     * CONTROL. The route refuses a bill that was never edited.
     *
     * Without this, Q-01 could be passing against a route that returns the
     * as-issued document unconditionally for every bill, which would bound
     * nothing about the hydrate-from-snapshot path.
     */
    public function test_q01_control_the_original_route_is_refused_for_a_bill_that_was_never_edited(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['terms_and_conditions' => self::ISSUED_TERMS]);
        $billId = $this->issueQuickBill($owner);

        $owner->unsetRelation('shop');
        $response = TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->get(route('quick-bills.print-original', $billId)));

        $this->assertSame(404, $response->getStatusCode(),
            'an unedited bill has no separate original to print');
    }

    // ────────────────────────────────────────────────────────────────────
    // Q-02 / S3-13 — CHARACTERIZATION ONLY. NOT REPAIRED.
    // ────────────────────────────────────────────────────────────────────

    /**
     * [CHARACTERIZES S3-13] Editing a quick bill re-captures `shop_snapshot`,
     * so an unrelated edit re-states the supply type on a bill that keeps its
     * number.
     *
     * ROOT CAUSE, read not guessed. `QuickBillService` writes
     * `'shop_snapshot' => $this->shopSnapshot($shop)` on the SHARED save path
     * used by both `create` and `update` (line 313). There is no branch on
     * whether the bill already has one, so every edit overwrites it with
     * today's settings.
     *
     * THE SEQUENCE. Bill issued intra-state as QB-1. Shop later switches to
     * inter-state for unrelated reasons. An operator corrects a typo in the
     * CUSTOMER NAME. QB-1 now prints IGST instead of CGST/SGST. Nothing in the
     * edit screen mentions tax.
     *
     * TWO READINGS, AND THIS TEST DOES NOT PICK ONE:
     *
     *   DEFENSIBLE  an edit is a re-issue. `edited_at` is set, the UI marks
     *               the bill edited, and the as-issued original stays frozen
     *               and printable — Q-01 proves it. Re-capturing current
     *               settings is then correct.
     *   TROUBLING   the re-characterization is a SILENT side effect of an
     *               edit to an unrelated field, on a document whose number
     *               does not change.
     *
     * Which one governs is a business decision about what a quick-bill edit
     * means, and it is the operator's to make, not mine to encode in a repair.
     *
     * SEVERITY, BOUNDED BY MEASUREMENT RATHER THAN ASSERTED. The last three
     * assertions are the bound and they are the reason this is filed as
     * characterization and not as a money finding: the bill number and every
     * stored figure are byte-identical across the edit. `igst_amount` is
     * hard-coded to 0 on that same save path and the template derives the IGST
     * line from the CGST/SGST pair, so total tax is unchanged either way. This
     * is a CHARACTERIZATION change, not an arithmetic one — the same shape as
     * S3-05 itself.
     *
     * If someone later makes an edit preserve the original `shop_snapshot`,
     * the first two assertions fail. That failure is the fix landing.
     */
    public function test_q02_an_unrelated_edit_re_characterizes_the_live_bill_without_moving_a_figure(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('false')]);
        $billId = $this->issueQuickBill($owner);

        $before = $this->figures($billId);
        $this->assertSame('false', var_export($this->snapshotIgstMode($billId), true),
            'the bill must be issued intra-state for this sequence to mean anything');

        // Unrelated settings change, then an edit that touches only the name.
        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $this->editCustomerName($owner, $billId);

        $this->assertTrue($this->snapshotIgstMode($billId),
            'S3-13 appears repaired — an edit no longer re-captures shop_snapshot. Update the handoff.');
        $this->assertStringContainsString('IGST', $this->printLive($owner, $billId),
            'and the live bill now prints as an inter-state supply');

        // ── the bound ──
        $after = $this->figures($billId);
        $this->assertSame($before['bill_number'], $after['bill_number'],
            'the document keeps its number across the edit, which is what makes this worth recording');
        $this->assertSame($before, $after,
            'no stored figure moved: S3-13 is a characterization change, not an arithmetic one');
        $this->assertSame('0.00', $after['igst_amount'],
            'igst_amount is hard-coded to 0 on the save path; the IGST line is derived from the '
                .'CGST/SGST pair, so total tax is identical under either presentation');
    }

    // ────────────────────────────────────────────────────────────────── helpers

    private function setBilling(int $shopId, array $values): void
    {
        if (! DB::table('shop_billing_settings')->where('shop_id', $shopId)->exists()) {
            DB::table('shop_billing_settings')->insert(['shop_id' => $shopId]);
        }

        DB::table('shop_billing_settings')->where('shop_id', $shopId)->update($values);
    }

    /**
     * @return array<string, string>
     */
    private function figures(int $billId): array
    {
        $row = (array) DB::table('quick_bills')->where('id', $billId)->first();

        return array_map(
            static fn ($v) => (string) $v,
            array_intersect_key($row, array_flip([
                'bill_number', 'cgst_amount', 'sgst_amount', 'igst_amount',
                'taxable_amount', 'total_amount', 'paid_amount', 'due_amount',
            ])),
        );
    }

    private function snapshotIgstMode(int $billId): bool
    {
        $snapshot = json_decode(
            (string) DB::table('quick_bills')->where('id', $billId)->value('shop_snapshot'),
            true,
        );

        return (bool) ($snapshot['igst_mode'] ?? false);
    }

    /**
     * The payload the form posts. Shared by issue and edit so the edit differs
     * in exactly one field — otherwise Q-02 would not be an "unrelated" edit.
     *
     * @return array<string, mixed>
     */
    private function payload(string $customerName): array
    {
        return [
            'bill_date' => now()->toDateString(),
            'pricing_mode' => 'gst_exclusive',
            'gst_rate' => 3,
            'save_action' => 'issue',
            'customer_name' => $customerName,
            'items' => [[
                'description' => 'Gold chain',
                'metal_type' => 'gold',
                'net_weight' => 10,
                'rate' => 7200,
                'line_total' => 1000,
            ]],
            // QuickBillService refuses to ISSUE a fully unpaid bill. A business
            // rule, satisfied rather than routed around.
            'payments' => [['payment_mode' => 'cash', 'amount' => 1030]],
        ];
    }

    /**
     * `unsetRelation('shop')` before every call is a TEST HARNESS need, not a
     * production behaviour: `actingAs()` keeps one User alive across the whole
     * test, so a `billingSettings` relation loaded during an earlier render
     * would still be cached and the next render would never see the settings
     * change. A real request builds a fresh User.
     */
    private function issueQuickBill(User $owner): int
    {
        $owner->unsetRelation('shop');

        TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->post(route('quick-bills.store'), $this->payload('Walk-in'))
            ->assertSessionHasNoErrors());

        return (int) DB::table('quick_bills')
            ->where('shop_id', $owner->shop_id)
            ->orderByDesc('id')
            ->value('id');
    }

    private function editCustomerName(User $owner, int $billId): void
    {
        $owner->unsetRelation('shop');

        TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->put(route('quick-bills.update', $billId), $this->payload('Walk-in (corrected)'))
            ->assertSessionHasNoErrors());
    }

    private function printLive(User $owner, int $billId): string
    {
        $owner->unsetRelation('shop');

        return TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->get(route('quick-bills.print', $billId))->assertOk())->getContent();
    }

    private function printOriginal(User $owner, int $billId): string
    {
        $owner->unsetRelation('shop');

        return TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->get(route('quick-bills.print-original', $billId))->assertOk())->getContent();
    }
}
