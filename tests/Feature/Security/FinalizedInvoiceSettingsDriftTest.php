<?php

namespace Tests\Feature\Security;

use App\Models\Invoice;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Traits\CreatesTestTenant;
use Tests\TestCase;

/**
 * S3-05 — a finalized bill must reprint with the tax presentation it was ISSUED
 * with, not with today's shop settings.
 *
 * THIS FILE CHANGED CHARACTER, AND THAT IS THE POINT
 * --------------------------------------------------
 * Its first revision (commit 2f26386) held CHARACTERIZATION tests. They asserted
 * the broken behaviour on purpose, so the finding was reproducible and so they
 * would fail loudly on the day someone fixed it. That day is this commit. The
 * assertions below are inverted from that revision: they now pin the REPAIR.
 * If you are bisecting and see this file flip, that flip is the fix landing, not
 * a test being weakened.
 *
 * WHAT WAS WRONG
 * --------------
 * invoice_print.blade.php read shop_billing_settings live in 45 places. Most are
 * cosmetic — theme colour, font tier, paper size. Two were not:
 *
 *   igst_mode    decided whether the bill printed ONE IGST row or TWO CGST/SGST
 *                rows. Flipping the shop setting re-characterized an already
 *                issued supply as inter-state.
 *   hsnForMetal  resolved the HSN code printed against each line from the shop's
 *                CURRENT map, so a finalized line's HSN followed later edits.
 *
 * MONEY WAS NEVER AFFECTED, and saying otherwise would overstate this. The
 * amounts come off the invoice row; $cgst/$sgst are $gst/2. Total tax is
 * identical either way. D-03 pins that bound so a reader who sees "GST
 * presentation changed" does not conclude the totals moved. They did not.
 *
 * SCOPE, STATED HONESTLY — AND ONE EARLIER SENTENCE WITHDRAWN
 * -----------------------------------------------------------
 * The repair covers igst_mode and the HSN map — the two statutory fields. The
 * rest are still live and NOT fixed here; the finding stays open for them.
 *
 * This docblock previously said "the other 43 reads are still live and still
 * cosmetic". Both halves of that were wrong and are withdrawn.
 *
 *   THE COUNT. Measured on this file's template, not carried forward:
 *   `$billing?->` appears 44 times and `$shop?->` 26 times in
 *   invoice_print.blade.php, over 26 and 13 distinct fields. The old figure
 *   counted only `$billing?->`, was off by one against even that, and omitted
 *   the shop-identity reads entirely.
 *
 *   "COSMETIC". D-09, D-10 and D-11 measure three of them drifting, and none
 *   of the three is cosmetic: bank account number, printed terms, and the
 *   GSTIN on a tax invoice. D-12 measures theme colour drifting by the same
 *   mechanism and IS cosmetic, which is the bound that keeps this a claim
 *   about field meaning rather than about live reads in general.
 *
 * Pinning paper size or theme colour to a snapshot is still a separate, larger
 * change with its own review. Pinning the four in D-09 to D-11 is not the same
 * size of job: the snapshot already captures every one of those fields
 * (InvoiceRenderSnapshotService lines 91-132) and nothing reads them.
 *
 * LEGACY INVOICES ARE NOT REPAIRED BY THIS, EITHER. An invoice finalized before
 * invoice_render_snapshots carried these keys has no record of what it printed.
 * D-06 pins that it falls back to live settings and still renders rather than
 * erroring — a stated limitation, not a repair. Nothing can reconstruct a
 * presentation that was never recorded.
 *
 * WHAT EACH TEST ACTUALLY BINDS
 * -----------------------------
 * Verified by mutation, not by inspection. Each row below was applied to the
 * working tree, the suite run, and the file restored by copying back a
 * pre-mutation snapshot — restoration confirmed by `git diff` showing only the
 * intended additions, plus a green rerun. Grepping for the absence of the word
 * MUTATION would not have shown any of this.
 *
 *   Mutation                                          Killed        Survived
 *   ------------------------------------------------  ------------  -----------------
 *   BillTaxPresentation::resolve ignores the snapshot  D-01 D-04     D-02 D-03 D-05
 *   (`if (false)`), always using live settings         D-07          D-06 D-08
 *   InvoiceRenderSnapshotService drops 'hsn_map'       D-04 D-05     the rest
 *   QuickBillService::shopSnapshot drops 'igst_mode'   D-07          the rest
 *
 * The survivors are survivors for good reasons, and the reasons differ:
 *  - D-02, D-05, D-08 are POSITIVE CONTROLS. A positive control that died under
 *    a mutation would be bounding nothing; it must pass under both the broken
 *    and the fixed implementation, or it is just a second copy of the
 *    regression test.
 *  - D-06 asserts the live-settings FALLBACK, which mutation 1 makes universal.
 *    It passes there by definition.
 *  - D-03 is about stored figures, which no mutation here touches.
 *
 * ONE SURVIVOR WAS NOT LEGITIMATE, AND THE TEST WAS FIXED RATHER THAN EXCUSED.
 * D-04 originally issued its invoice under HSN 7113 — which is
 * HSN_DEFAULTS['gold']. Dropping 'hsn_map' from the snapshot left D-04 green,
 * because the empty-map fallback resolves to exactly that default. The test was
 * agreeing with the fix by coincidence. Changing the issued code to 5555 made
 * mutation 2 kill it, which is the result recorded in the table above.
 */
class FinalizedInvoiceSettingsDriftTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    // -------------------------------------------------------------------- D-01
    /**
     * THE DECISIVE REGRESSION. Finalize as intra-state, flip the shop to
     * inter-state, reprint — the bill still describes itself as it was issued.
     */
    public function test_d01_flipping_igst_mode_after_finalization_does_not_change_the_bill(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('false')]);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('CGST', $html, 'the bill was issued as an intra-state supply');
        $this->assertStringContainsString('SGST', $html, 'and must keep both halves of that split');
        $this->assertStringNotContainsString(
            'IGST',
            $html,
            'S3-05: a shop setting changed after finalization must not re-characterize the supply'
        );
    }

    // -------------------------------------------------------------------- D-02
    /**
     * The bound on D-01. Without this, D-01 would also pass if IGST never
     * rendered at all — a template that cannot print IGST satisfies
     * "assertStringNotContainsString('IGST')" for the wrong reason.
     */
    public function test_d02_an_invoice_finalized_under_igst_mode_prints_igst(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('IGST', $html, 'an inter-state shop still prints IGST');
        $this->assertStringNotContainsString('CGST', $html, 'and not the intra-state split');
    }

    // -------------------------------------------------------------------- D-03
    /** Presentation is pinned; the figures were never the thing at risk. */
    public function test_d03_no_stored_figure_moves_in_either_direction(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('false')]);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $before = Invoice::withoutTenant()->find($invoice->id);
        $storedGst = (string) $before->gst;
        $storedTotal = (string) $before->total;

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $this->printInvoice($owner, $invoice);

        $after = Invoice::withoutTenant()->find($invoice->id);

        $this->assertSame($storedGst, (string) $after->gst, 'the stored tax figure must not move');
        $this->assertSame($storedTotal, (string) $after->total, 'nor the stored total');
    }

    // -------------------------------------------------------------------- D-04
    /**
     * Separate from D-01 because it is a different mechanism — a method call on
     * the live settings model rather than a boolean branch. A fix for one would
     * not automatically cover the other.
     *
     * THE ISSUED CODE IS 5555, NOT 7113, AND THAT MATTERS. An earlier draft of
     * this test used 7113, which is HSN_DEFAULTS['gold']. Deleting the snapshot's
     * hsn_map still left it green, because an absent map falls back to exactly
     * that default — the test passed without the capture it was supposed to be
     * pinning. A non-default code cannot be reached by the fallback, so the
     * assertion now binds the capture rather than coinciding with it.
     */
    public function test_d04_editing_the_hsn_map_after_finalization_does_not_change_the_bill(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['hsn_gold' => '5555']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->setBilling($shop->id, ['hsn_gold' => '9999']);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('HSN: 5555', $html, 'the bill keeps the HSN it was issued under');
        $this->assertStringNotContainsString(
            '9999',
            $html,
            'S3-05: a later edit to the shop HSN map must not rewrite a finalized line'
        );
    }

    // -------------------------------------------------------------------- D-05
    /**
     * The bound on D-04 — proves the shop's configured code reaches the page at
     * all, so D-04 is not passing because HSN is hard-coded to the default.
     */
    public function test_d05_an_invoice_finalized_under_a_custom_hsn_prints_that_hsn(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['hsn_gold' => '9999']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('HSN: 9999', $html, 'the configured code is what gets frozen');
    }

    // -------------------------------------------------------------------- D-06
    /**
     * COMPATIBILITY, NOT REPAIR.
     *
     * An invoice with no render snapshot — every invoice finalized before the
     * snapshot service shipped — has no record of how it printed. It falls back
     * to live settings and still renders. This test exists so that fallback is a
     * decision with a name on it rather than an accident, and so nobody later
     * reads D-01 as covering historical invoices. It does not.
     */
    public function test_d06_an_invoice_without_a_render_snapshot_still_renders_from_live_settings(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('false')]);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        // Reproduce a pre-snapshot invoice by removing the row entirely. The
        // snapshot is deleted, never rewritten — a rewritten snapshot would be a
        // fabricated history, which is the thing this whole finding is against.
        DB::table('invoice_render_snapshots')->where('invoice_id', $invoice->id)->delete();

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString(
            'IGST',
            $html,
            'with no snapshot there is nothing to honour, so live settings are the only information that exists'
        );
    }

    // -------------------------------------------------------------------- D-07
    /** The same regression on the quick-bill path, which has its own snapshot. */
    public function test_d07_flipping_igst_mode_after_issue_does_not_change_a_quick_bill(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('false')]);
        $billId = $this->issueQuickBill($owner);

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $html = $this->printQuickBill($owner, $billId);

        $this->assertStringContainsString('CGST', $html, 'the bill was issued as an intra-state supply');
        $this->assertStringNotContainsString('IGST', $html, 'S3-05: and must not be re-characterized afterwards');
    }

    // -------------------------------------------------------------------- D-08
    /** The bound on D-07. */
    public function test_d08_a_quick_bill_issued_under_igst_mode_prints_igst(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $billId = $this->issueQuickBill($owner);

        $html = $this->printQuickBill($owner, $billId);

        $this->assertStringContainsString('IGST', $html, 'an inter-state shop still prints IGST');
        $this->assertStringNotContainsString('CGST', $html, 'and not the intra-state split');
    }

    // -------------------------------------------------------------------- D-09
    /**
     * CLASSIFICATION, not repair. D-09 to D-12 exist to answer one question the
     * earlier revision of this file answered by assertion rather than by
     * measurement: are the remaining live reads really all "cosmetic"?
     *
     * They are not. The reading MECHANISM is uniform — every one of them is
     * `$billing?->x` or `$shop?->x` evaluated at render time — so the mechanism
     * cannot be what separates them. What separates them is the meaning of the
     * field. D-09 through D-11 drift things a printed bill ASSERTS about the
     * transaction; D-12 drifts a thing it merely looks like. Only D-12 is
     * cosmetic, and it is here so that claim is bounded rather than asserted.
     *
     * These four pin the CURRENT behaviour deliberately, the way this file's
     * first revision did for igst_mode. Each was first written asserting the
     * desired behaviour and run, so the drift is a measured failure and not an
     * inference from reading the template; the recorded failures are in
     * docs/runbooks/security-multi-tenant-audit-handoff.md §3. When the
     * remaining half of S3-05 is repaired, these must flip.
     *
     * PAYMENT INSTRUCTIONS. The highest-consequence member of the set, which is
     * why it is first. A reprint of a bill issued against one account prints
     * today's account number instead. A customer settling an outstanding balance
     * from a reprinted copy is being given instructions that were never the ones
     * issued, and the bill carries no indication that they changed.
     */
    public function test_d09_bank_details_on_a_reprint_follow_todays_settings(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, [
            'bank_name'           => 'Issued Bank',
            'bank_account_number' => '11110000',
        ]);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->setBilling($shop->id, [
            'bank_name'           => 'Replacement Bank',
            'bank_account_number' => '99990000',
        ]);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('99990000', $html,
            'measured: the reprint carries the CURRENT account number');
        $this->assertStringNotContainsString('11110000', $html,
            'measured: and not the one the bill was issued against');
    }

    // -------------------------------------------------------------------- D-10
    /**
     * TERMS. The printed terms are the ones the customer was handed at the
     * counter. A reprint produced for a dispute shows whatever the shop
     * configured most recently, so the document cannot evidence its own terms.
     * Note the snapshot ALREADY captures terms_and_conditions — nothing reads
     * it. That makes this the cheapest member of the set to repair.
     */
    public function test_d10_terms_on_a_reprint_follow_todays_settings(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['terms_and_conditions' => 'Exchange within 7 days.']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->setBilling($shop->id, ['terms_and_conditions' => 'No exchange under any circumstances.']);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('No exchange under any circumstances.', $html,
            'measured: the reprint carries the CURRENT terms');
        $this->assertStringNotContainsString('Exchange within 7 days.', $html,
            'measured: and not the terms the customer was given');
    }

    // -------------------------------------------------------------------- D-11
    /**
     * PRINTED BUSINESS IDENTITY, and the statutory case inside it.
     *
     * GSTIN is a required particular of a tax invoice, so a reprint showing a
     * GSTIN other than the one the supply was made under is not a presentation
     * question. This one is worse than D-09 and D-10 in one specific way: the
     * snapshot captures `shop.gst_number`, and the template does not read it —
     * it reads `auth()->user()->shop`. That is the LOGGED-IN user's shop rather
     * than the invoice's. Tenant scoping means the two coincide today, so this
     * is not a cross-tenant finding and is not reported as one; it is recorded
     * because the correct source is one hop away and already snapshotted.
     */
    public function test_d11_shop_gstin_on_a_reprint_follows_todays_settings(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['show_gstin' => DB::raw('true')]);
        DB::table('shops')->where('id', $shop->id)->update(['gst_number' => '29AAAAA1111A1Z5']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        DB::table('shops')->where('id', $shop->id)->update(['gst_number' => '29BBBBB9999B1Z5']);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('29BBBBB9999B1Z5', $html,
            'measured: the reprint carries the CURRENT GSTIN');
        $this->assertStringNotContainsString('29AAAAA1111A1Z5', $html,
            'measured: and not the GSTIN the supply was made under');
    }

    // -------------------------------------------------------------------- D-12
    /**
     * THE BOUND ON D-09 TO D-11, and the one field in this group that really is
     * cosmetic. Theme colour drifts by exactly the same mechanism, and nothing
     * about the transaction changes when it does — an old bill reprinted in a
     * new house colour still asserts the same facts.
     *
     * Without this test the group would read as "live reads are bad", which is
     * the overreach the directive warns against. With it the finding is the
     * narrower and defensible one: the drift is uniform, the CONSEQUENCE is not,
     * and only the fields a bill asserts something with need pinning.
     */
    public function test_d12_theme_colour_also_drifts_and_that_one_is_cosmetic(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['theme_color' => '#111111']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->setBilling($shop->id, ['theme_color' => '#ABCDEF']);
        $html = $this->printInvoice($owner, $invoice);

        // Re-read rather than using $invoice: the fixture returns the DRAFT
        // model and invoice_number is assigned during finalization. The first
        // draft of this test asserted against the stale in-memory model and
        // died on a null needle — a fixture bug, easy to mistake for the
        // template failing to print the number at all.
        $number = (string) Invoice::withoutTenant()->find($invoice->id)->invoice_number;
        $this->assertNotSame('', $number, 'finalization must have assigned a number');

        $this->assertStringContainsString('#ABCDEF', $html,
            'same drift mechanism as D-09 to D-11');
        $this->assertStringContainsString($number, $html,
            'but the bill still asserts the same transaction, which is why this one is cosmetic');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A finalized invoice created through the REAL path.
     *
     * The draft and its line are seeded directly — Invoice money columns are
     * GUARDED by design (CONSTITUTION Art. I) and invoice_items_finalized_guard
     * (Art. IX.A) refuses inserts against a finalized invoice, so the fixture
     * follows the same order the application does. No trigger is dropped,
     * disabled or altered. Finalization itself goes through the controller so
     * the render snapshot is captured by production code, not by the fixture.
     */
    private function finalizedInvoice(User $owner, int $shopId): Invoice
    {
        $customer = $this->createCustomer($shopId);

        $invoice = TenantContext::runFor($shopId, function () use ($shopId, $customer) {
            $invoice = new Invoice();
            $invoice->forceFill([
                'shop_id'        => $shopId,
                'customer_id'    => $customer->id,
                'gold_rate'      => 7200,
                'subtotal'       => 1000,
                'gst_rate'       => 3,
                // NOT NULL even on a draft, seeded at zero rather than
                // pre-computed: finalizeDraft() recalculates both, so seeding
                // real figures would hide whether the calculation ran.
                'gst'            => 0,
                'total'          => 0,
                'wastage_charge' => 0,
                'discount'       => 0,
                'round_off'      => 0,
                'status'         => Invoice::STATUS_DRAFT,
            ])->save();

            $item = $this->createItem($shopId, null, ['metal_type' => 'gold']);

            DB::table('invoice_items')->insert([
                'invoice_id'     => $invoice->id,
                'item_id'        => $item->id,
                'metal_type'     => 'gold',
                'weight'         => 10,
                'rate'           => 7200,
                'making_charges' => 0,
                'stone_amount'   => 0,
                'line_total'     => 1000,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            return $invoice;
        });

        $owner->unsetRelation('shop');
        TenantContext::runFor($shopId, fn () => $this->actingAs($owner)
            ->put(route('invoices.update', $invoice), ['action' => 'finalize'])
            ->assertSessionHasNoErrors());

        return $invoice;
    }

    private function issueQuickBill(User $owner): int
    {
        $owner->unsetRelation('shop');

        TenantContext::runFor((int) $owner->shop_id, fn () => $this->actingAs($owner)
            ->post(route('quick-bills.store'), [
                'bill_date'     => now()->toDateString(),
                'pricing_mode'  => 'gst_exclusive',
                'gst_rate'      => 3,
                'save_action'   => 'issue',
                'customer_name' => 'Walk-in',
                'items'         => [[
                    'description' => 'Gold chain',
                    'metal_type'  => 'gold',
                    'net_weight'  => 10,
                    'rate'        => 7200,
                    'line_total'  => 1000,
                ]],
                // QuickBillService:265 refuses to ISSUE a fully unpaid bill.
                // A business rule, not a fixture obstacle — satisfied, not
                // routed around.
                'payments'      => [[
                    'payment_mode' => 'cash',
                    'amount'       => 1030,
                ]],
            ])->assertSessionHasNoErrors());

        return (int) DB::table('quick_bills')
            ->where('shop_id', $owner->shop_id)
            ->orderByDesc('id')
            ->value('id');
    }

    /** @param array<string, mixed> $values */
    private function setBilling(int $shopId, array $values): void
    {
        if (! DB::table('shop_billing_settings')->where('shop_id', $shopId)->exists()) {
            DB::table('shop_billing_settings')->insert(['shop_id' => $shopId]);
        }

        DB::table('shop_billing_settings')->where('shop_id', $shopId)->update($values);
    }

    /**
     * TEST HARNESS NOTE, not a production behaviour.
     *
     * The print views read `auth()->user()->shop->billingSettings`. actingAs()
     * keeps ONE User alive across every call in a test, so a relation loaded
     * during the first render is still cached during the second and the view
     * would never observe the settings change. A real request builds a fresh
     * User. Unsetting the relation reproduces that; without it these tests would
     * pass for a reason that has nothing to do with the fix.
     */
    private function printInvoice(User $owner, Invoice $invoice): string
    {
        $owner->unsetRelation('shop');

        return TenantContext::runFor(
            (int) $owner->shop_id,
            fn () => $this->actingAs($owner)
                ->get(route('invoices.print', $invoice))
                ->assertOk()
                ->getContent()
        );
    }

    private function printQuickBill(User $owner, int $billId): string
    {
        $owner->unsetRelation('shop');

        return TenantContext::runFor(
            (int) $owner->shop_id,
            fn () => $this->actingAs($owner)
                ->get(route('quick-bills.print', $billId))
                ->assertOk()
                ->getContent()
        );
    }
}
