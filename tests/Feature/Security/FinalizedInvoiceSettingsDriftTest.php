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
 * The repair covers what the document ASSERTS about the transaction:
 *
 *   igst_mode, hsn_map                        how the supply was taxed
 *   show_gstin, shop.gst_number               the tax identity it was made under
 *   terms_and_conditions                      what the customer agreed to
 *   upi_id, bank_name, bank_account_holder,   where the customer was told to pay
 *   bank_account_number, bank_ifsc,
 *   bank_account_type, bank_branch,
 *   bank_details
 *
 * It deliberately does NOT cover how the document is RENDERED today: theme
 * colour, font tier, paper size, subtitle, tagline, copy label and count, and
 * the show_* column toggles other than show_gstin. Those describe the printer,
 * not the sale, and freezing them to an old snapshot would be a defect. D-12 is
 * the standing proof that they were left alone.
 *
 * This docblock previously said "the other 43 reads are still live and still
 * cosmetic". Both halves of that were wrong and are withdrawn.
 *
 *   THE COUNT. Measured on this file's template, not carried forward:
 *   `$billing?->` appeared 44 times and `$shop?->` 26 times in
 *   invoice_print.blade.php, over 26 and 13 distinct fields. The old figure
 *   counted only `$billing?->`, was off by one against even that, and omitted
 *   the shop-identity reads entirely.
 *
 *   "COSMETIC". D-09, D-10 and D-11 measured three of them drifting, and none
 *   of the three was cosmetic: bank account number, printed terms, and the
 *   GSTIN on a tax invoice. All three are pinned now. D-12 measures theme
 *   colour drifting by the same mechanism and IS cosmetic, which is the bound
 *   that keeps this a claim about field meaning rather than about live reads in
 *   general.
 *
 * QUICK BILLS GET LESS, AND THE GAP IS NAMED. Their snapshot is flat and has
 * only terms_and_conditions, bank_details, upi_id, gst_number and igst_mode. It
 * has never carried the itemised bank_name/ifsc/branch group, and this change
 * does not start capturing it — the template's reads of those keys are left
 * exactly as they were. Quick bills print no GSTIN at all. See
 * BillPresentation::forQuickBill.
 *
 * LEGACY INVOICES ARE NOT REPAIRED BY THIS, EITHER. An invoice finalized before
 * invoice_render_snapshots carried these keys has no record of what it printed.
 * D-06 pins that a bill with NO snapshot falls back to live settings and still
 * renders rather than erroring; D-13 pins the same for a snapshot that exists
 * but predates the asserted keys, which is the commoner case and the one that
 * would otherwise reprint BLANK. Both are stated limitations, not repairs.
 * Nothing can reconstruct a presentation that was never recorded.
 *
 * WHAT EACH TEST ACTUALLY BINDS
 * -----------------------------
 * Verified by mutation, not by inspection. Each row below was applied to the
 * working tree, the suite run, and the file restored by copying back a
 * pre-mutation snapshot — restoration confirmed by `git diff` showing only the
 * intended additions, plus a green rerun. Grepping for the absence of the word
 * MUTATION would not have shown any of this.
 *
 * The first three rows were recorded against the FIRST half of the repair
 * (commit b216b80), when the class was still called BillTaxPresentation and
 * D-09 to D-12 were characterization tests. The last two were recorded against
 * the second half, on the tests as they now stand.
 *
 *   Mutation                                          Killed        Survived
 *   ------------------------------------------------  ------------  -----------------
 *   BillPresentation::resolve ignores the snapshot     D-01 D-04     D-02 D-03 D-05
 *   (`if (false)`), always using live settings         D-07          D-06 D-08
 *   InvoiceRenderSnapshotService drops 'hsn_map'       D-04 D-05     the rest
 *   QuickBillService::shopSnapshot drops 'igst_mode'   D-07          the rest
 *   resolve() ignores the ASSERTED keys, always using  D-09 D-10     the rest,
 *   live settings for them                             D-11 D-11b    incl. D-12 D-13
 *   resolve() uses `??` for the asserted keys instead  D-13c         the rest,
 *   of array_key_exists()                                            incl. D-13
 *
 * THE LAST ROW IS A CORRECTION, AND D-13c EXISTS BECAUSE OF IT. That row first
 * read "D-13", by inference from what D-13 was for rather than from a run. The
 * mutation was then actually applied and ALL FIFTEEN tests stayed green: the
 * per-key fallback was justified at length in BillPresentation's docblock and
 * bound by nothing. D-13c was written against the live mutation, watched fail
 * on the right assertion, and passes once it is reverted. An unrun mutation
 * table is a claim, not evidence, and this one was wrong.
 *
 * The survivors are survivors for good reasons, and the reasons differ:
 *  - D-02, D-05, D-08 are POSITIVE CONTROLS. A positive control that died under
 *    a mutation would be bounding nothing; it must pass under both the broken
 *    and the fixed implementation, or it is just a second copy of the
 *    regression test.
 *  - D-06 and D-13 assert the live-settings FALLBACK, which mutations 1 and 4
 *    make universal. They pass there by definition. D-13 survives mutation 5
 *    too, and that is not a weakness in it: `??` and array_key_exists() agree
 *    on an ABSENT key, which is the only shape D-13 creates. D-13c is the pair
 *    that separates them, on a key recorded as NULL.
 *  - D-03 is about stored figures, which no mutation here touches.
 *  - D-12 survives everything, which is the point of it. A mutation to the
 *    asserted-field resolution that killed D-12 would mean the repair had
 *    leaked into rendering.
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
     * THE SECOND HALF OF THE REPAIR, AND THESE THREE HAVE NOW FLIPPED.
     *
     * D-09 to D-11 were written as CLASSIFICATION tests to answer one question
     * the first revision of this file answered by assertion rather than by
     * measurement: are the remaining live reads really all "cosmetic"? They are
     * not. The reading MECHANISM was uniform — every one of them was
     * `$billing?->x` or `$shop?->x` evaluated at render time — so the mechanism
     * could not be what separated them. What separates them is the MEANING of
     * the field. D-09 to D-11b drift things a printed bill ASSERTS about the
     * transaction; D-12 drifts a thing the bill merely looks like.
     *
     * Those three asserted the broken behaviour on purpose, and said so: "when
     * the remaining half of S3-05 is repaired, these must flip." That is this
     * commit. They are inverted here and now pin the repair — the as-issued
     * value is printed, today's is not. If you are bisecting and see them flip,
     * that flip is the fix landing, not a test being weakened. The measured
     * pre-repair failures they were derived from are recorded in
     * docs/runbooks/security-multi-tenant-audit-handoff.md §3.
     *
     * D-12 did NOT flip and must not. Theme colour drifts by the identical
     * mechanism and is left drifting deliberately, which is what keeps this a
     * claim about field meaning rather than a blanket freeze on live reads.
     *
     * PAYMENT INSTRUCTIONS. The highest-consequence member of the set, which is
     * why it is first. Before the repair, a reprint of a bill issued against one
     * account printed today's account number instead: a customer settling an
     * outstanding balance from a reprinted copy was given instructions that were
     * never the ones issued, with nothing on the bill saying they had changed.
     */
    public function test_d09_bank_details_on_a_reprint_are_the_ones_the_bill_was_issued_with(): void
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

        $this->assertStringContainsString('11110000', $html,
            'the reprint carries the account the bill was issued against');
        $this->assertStringContainsString('Issued Bank', $html,
            'and the bank that went with it');
        $this->assertStringNotContainsString('99990000', $html,
            'S3-05: a customer settling from a reprint must not be handed an account that was never the one issued');
        $this->assertStringNotContainsString('Replacement Bank', $html,
            'and the shop changing banks must not rewrite an already-issued document');
    }

    // -------------------------------------------------------------------- D-10
    /**
     * TERMS. The printed terms are the ones the customer was handed at the
     * counter. A reprint produced for a dispute shows whatever the shop
     * configured most recently, so the document cannot evidence its own terms.
     * Note the snapshot ALREADY captures terms_and_conditions — nothing reads
     * it. That makes this the cheapest member of the set to repair.
     */
    public function test_d10_terms_on_a_reprint_are_the_ones_the_customer_was_given(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['terms_and_conditions' => 'Exchange within 7 days.']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->setBilling($shop->id, ['terms_and_conditions' => 'No exchange under any circumstances.']);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('Exchange within 7 days.', $html,
            'the reprint evidences the terms the customer was handed at the counter');
        $this->assertStringNotContainsString('No exchange under any circumstances.', $html,
            'S3-05: a reprint pulled for a dispute must not restate the terms in whatever the shop configured since');
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
    public function test_d11_shop_gstin_on_a_reprint_is_the_one_the_supply_was_made_under(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['show_gstin' => DB::raw('true')]);
        DB::table('shops')->where('id', $shop->id)->update(['gst_number' => '29AAAAA1111A1Z5']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        DB::table('shops')->where('id', $shop->id)->update(['gst_number' => '29BBBBB9999B1Z5']);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('29AAAAA1111A1Z5', $html,
            'the reprint carries the GSTIN the supply was made under');
        $this->assertStringNotContainsString('29BBBBB9999B1Z5', $html,
            'S3-05: GSTIN is a required particular of a tax invoice, so a reprint must not substitute a later one');
    }

    // ------------------------------------------------------------------- D-11b
    /**
     * show_gstin is pinned alongside gst_number, and the pair has to move
     * together or the repair is half-done: a bill issued WITHOUT a GSTIN on it
     * must not grow one when the shop later switches the toggle on. Asserting
     * only the value would leave that hole open, because the value is only
     * reached when the flag lets it through.
     *
     * This is the one show_* flag treated as asserted rather than rendering.
     * Its neighbours choose which COLUMNS appear; this one chooses whether a
     * statutory particular is on the page.
     */
    public function test_d11b_hiding_the_gstin_at_issue_survives_the_toggle_being_switched_on(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['show_gstin' => DB::raw('false')]);
        DB::table('shops')->where('id', $shop->id)->update(['gst_number' => '29AAAAA1111A1Z5']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->setBilling($shop->id, ['show_gstin' => DB::raw('true')]);
        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringNotContainsString('29AAAAA1111A1Z5', $html,
            'S3-05: the bill was issued without a GSTIN printed and must reprint that way');
    }

    // -------------------------------------------------------------------- D-12
    /**
     * THE BOUND ON D-09 TO D-11b, and the one field in this group that really
     * is cosmetic. Theme colour drifts by exactly the same mechanism, and
     * nothing about the transaction changes when it does — an old bill
     * reprinted in a new house colour still asserts the same facts.
     *
     * THIS TEST STILL ASSERTS THE DRIFT, ON PURPOSE, AND STILL PASSES. That is
     * the whole point of it after the repair. Paper size, font tier and theme
     * colour describe the printer in front of you today, not the sale; pinning
     * them to a 2024 snapshot would be a defect rather than a repair. If a later
     * change makes this test fail, the repair has over-reached into rendering
     * and the change is wrong, not this test.
     *
     * Without it the group would read as "live reads are bad", which is the
     * overreach the directive warns against. With it the finding is the narrower
     * and defensible one: the drift is uniform, the CONSEQUENCE is not, and only
     * the fields a bill asserts something with need pinning.
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

    // -------------------------------------------------------------------- D-13
    /**
     * THE REGRESSION THAT WOULD OTHERWISE HIT EVERY OLD BILL IN THE ESTATE.
     *
     * D-06 covers an invoice with NO snapshot row at all. This covers the more
     * common and more dangerous case: a snapshot row that exists but was written
     * before the asserted keys were in it. Those rows are real — the 'billing'
     * section grew over time, and nothing rewrites the old ones.
     *
     * If the resolver read them with `??` instead of array_key_exists(), a
     * missing key and a recorded NULL would look identical, and the natural
     * "just use the snapshot value" implementation would print an EMPTY payment
     * block, empty terms and no GSTIN on every pre-existing bill — a blank
     * document, which is worse than the drift this finding is about. The
     * fallback has to be per KEY, and this pins that.
     *
     * The fixture strips the keys from the STORED payload to reproduce the old
     * shape. It never writes a fabricated value in: an invented snapshot is the
     * exact thing this whole finding argues against.
     */
    public function test_d13_a_snapshot_predating_the_asserted_keys_still_renders_from_live_settings(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, [
            'bank_name'            => 'Issued Bank',
            'bank_account_number'  => '11110000',
            'terms_and_conditions' => 'Exchange within 7 days.',
            'show_gstin'           => DB::raw('true'),
        ]);
        DB::table('shops')->where('id', $shop->id)->update(['gst_number' => '29AAAAA1111A1Z5']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->stripSnapshotKeys($invoice, [
            'billing' => ['show_gstin', 'terms_and_conditions', 'upi_id', 'bank_name',
                'bank_account_holder', 'bank_account_number', 'bank_ifsc',
                'bank_account_type', 'bank_branch', 'bank_details'],
            'shop' => ['gst_number'],
        ]);

        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringContainsString('11110000', $html,
            'with no record of the account, live settings are the only information that exists');
        $this->assertStringContainsString('Issued Bank', $html, 'same for the bank name');
        $this->assertStringContainsString('Exchange within 7 days.', $html, 'same for the terms');
        $this->assertStringContainsString('29AAAAA1111A1Z5', $html,
            'and the GSTIN, which show_gstin must default to VISIBLE for — a legacy bill that '
            .'recorded no toggle printed one');
        // The em-dash placeholder the template prints when it has no payment
        // details at all. Matched with its markup, not by the class name alone:
        // `.footer-empty` is also a CSS rule in the <style> block, so the bare
        // string is present on every render and would assert nothing.
        $this->assertStringNotContainsString('<div class="footer-empty">', $html,
            'the payment block must not come back blank; a blank reprint is worse than a drifting one');
    }

    /**
     * The bound on D-13's GSTIN assertion. D-13 proves a MISSING show_gstin
     * defaults to visible; this proves a snapshot that recorded FALSE is not
     * being read as missing. Without it, a resolver that ignored the key
     * entirely and always returned true would satisfy D-13.
     *
     * D-11b is the behavioural twin of this at the template level. This one
     * exists because the two failure modes — "missing key" and "recorded false"
     * — are one `??` apart in the resolver and collapse into each other
     * silently.
     */
    public function test_d13b_a_recorded_false_show_gstin_is_not_mistaken_for_a_missing_key(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        $this->setBilling($shop->id, ['show_gstin' => DB::raw('false')]);
        DB::table('shops')->where('id', $shop->id)->update(['gst_number' => '29AAAAA1111A1Z5']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        // Only the SHOP section loses its key. 'billing.show_gstin' stays, and
        // it is false, so the GSTIN must stay off the page even though the
        // number itself now falls back to the live shop row.
        $this->stripSnapshotKeys($invoice, ['shop' => ['gst_number']]);

        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringNotContainsString('29AAAAA1111A1Z5', $html,
            'a recorded false is a decision the bill made, not an absence of one');
    }

    // ------------------------------------------------------------------- D-13c
    /**
     * THE OTHER HALF OF THE PER-KEY FALLBACK, AND IT WAS MISSING.
     *
     * This test was added after the repair, because a mutation run contradicted
     * the coverage claim written above it. The table in this docblock asserted
     * that D-13 kills a resolver using `??` in place of array_key_exists(). It
     * does not. Running that mutation left all fifteen tests green, so the
     * per-key fallback — the thing BillPresentation's own docblock spends a
     * paragraph justifying — was described but never bound. This closes it.
     *
     * WHY D-13 CANNOT COVER THIS. `??` and array_key_exists() agree on a
     * MISSING key: both fall back to live. They disagree only on a key that is
     * PRESENT and NULL. D-13 strips its keys, so it exercises the case where
     * the two are identical. The two shapes mean opposite things:
     *
     *   key absent        this bill has no record of the field  → live settings
     *   key present, NULL this bill recorded printing NOTHING   → print nothing
     *
     * THE SCENARIO. A shop invoices for months with no bank account on the
     * bill, then opens one. Under `??` every one of those already-issued bills
     * reprints carrying an account number that was never on it — the same class
     * of defect as D-09, arriving through the fallback rather than through the
     * live read, and invisible because the reprint looks more complete than the
     * original rather than less.
     */
    public function test_d13c_a_bill_issued_with_no_payment_details_does_not_grow_them_on_reprint(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();

        // Issued with no payment instructions at all.
        $this->setBilling($shop->id, [
            'upi_id' => null, 'bank_name' => null, 'bank_account_holder' => null,
            'bank_account_number' => null, 'bank_ifsc' => null, 'bank_account_type' => null,
            'bank_branch' => null, 'bank_details' => null,
        ]);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        // Load-bearing precondition. The whole test turns on the key being
        // PRESENT and NULL; if finalization ever stopped capturing it, the
        // fixture would be testing the missing-key case D-13 already covers.
        $this->assertSnapshotRecordsNull($invoice, 'billing', 'bank_account_number');

        // The shop opens an account AFTER the bill was issued.
        $this->setBilling($shop->id, [
            'bank_name' => 'Later Bank', 'bank_account_number' => '77770000', 'upi_id' => 'later@upi',
        ]);

        $html = $this->printInvoice($owner, $invoice);

        $this->assertStringNotContainsString('77770000', $html,
            'S3-05: a bill issued with no account must not acquire one on reprint');
        $this->assertStringNotContainsString('Later Bank', $html, 'nor the bank that came with it');
        $this->assertStringNotContainsString('later@upi', $html, 'nor a UPI handle it never carried');
        $this->assertStringContainsString('<div class="footer-empty">', $html,
            'the payment block must reprint empty, the way the original printed');
    }

    // ------------------------------------------------------------------ helpers

    // ────────────────────────────────────────────────────────────────────
    // XR-06 — the parties' identity, as issued (asserted fields)
    // ────────────────────────────────────────────────────────────────────

    private function setShop(int $shopId, array $values): void
    {
        DB::table('shops')->where('id', $shopId)->update($values);
    }

    private function setCustomerOf(Invoice $invoice, array $values): void
    {
        DB::table('customers')->where('id', $invoice->customer_id)->update($values);
    }

    /** D-14 — the seller as the bill named them, and the state the supply was made from. */
    public function test_d14_seller_identity_on_a_reprint_is_the_one_on_the_bill(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->setShop($shop->id, ['name' => 'Asha Jewellers', 'address_line1' => '12 Old Market', 'city' => 'Jaipur',
            'state' => 'Rajasthan', 'state_code' => '08', 'pincode' => '302001', 'shop_registration_number' => 'REG-OLD-1']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);

        $this->setShop($shop->id, ['name' => 'Asha Gold House', 'address_line1' => '99 New Plaza', 'city' => 'Mumbai',
            'state' => 'Maharashtra', 'state_code' => '27', 'pincode' => '400001', 'shop_registration_number' => 'REG-NEW-2']);

        $html = $this->printInvoice($owner, $invoice);

        foreach (['Asha Jewellers', '12 Old Market', 'Jaipur', 'Rajasthan', '302001', 'REG-OLD-1'] as $asIssued) {
            $this->assertStringContainsString($asIssued, $html, "as issued: {$asIssued}");
        }
        foreach (['Asha Gold House', '99 New Plaza', 'Mumbai', 'Maharashtra', '400001', 'REG-NEW-2'] as $today) {
            $this->assertStringNotContainsString($today, $html, "today's value must not appear: {$today}");
        }
    }

    /** D-15 — the recipient as the bill named them. */
    public function test_d15_recipient_identity_on_a_reprint_is_the_one_on_the_bill(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($owner, $shop->id,
            ['first_name' => 'Meera', 'last_name' => 'Rao', 'address' => '4 Lake Road', 'mobile' => '9811111111', 'id_number' => 'ID-OLD', 'pan' => 'ABCDE1234F']);

        $this->setCustomerOf($invoice, ['first_name' => 'Meera R.', 'last_name' => 'Kapoor', 'address' => '77 Hill View', 'mobile' => '9822222222', 'id_number' => 'ID-NEW', 'pan' => 'ZZZZZ9999Z']);

        $html = $this->printInvoice($owner, $invoice);

        foreach (['Meera Rao', '4 Lake Road', '98111', 'ID-OLD', 'ABCDE1234F'] as $asIssued) {
            $this->assertStringContainsString($asIssued, $html, "as issued: {$asIssued}");
        }
        foreach (['Meera R. Kapoor', '77 Hill View', '98222', 'ID-NEW', 'ZZZZZ9999Z'] as $today) {
            $this->assertStringNotContainsString($today, $html, "today's value must not appear: {$today}");
        }
    }

    /** D-16 — a line the bill printed empty stays empty; a key the bill never recorded falls back. */
    public function test_d16_recorded_null_stays_empty_and_a_missing_key_falls_back(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->setShop($shop->id, ['address_line2' => null]);
        $invoice = $this->finalizedInvoice($owner, $shop->id);
        $this->assertSnapshotRecordsNull($invoice, 'shop', 'address_line2');

        $this->setShop($shop->id, ['address_line2' => 'Added Later Wing']);
        $this->assertStringNotContainsString('Added Later Wing', $this->printInvoice($owner, $invoice),
            'recorded NULL: the bill printed no second line and must not grow one');

        $this->stripSnapshotKeys($invoice, ['shop' => ['address_line2']]);
        $this->assertStringContainsString('Added Later Wing', $this->printInvoice($owner, $invoice),
            'a snapshot that never recorded the key falls back to the live value, as before');
    }

    /**
     * D-17 — UNRESOLVED PRODUCT DECISION, pinned as it stands: the seller's
     * contact details (phone, WhatsApp, email) stay LIVE on a reprint. Whether
     * a reprint should show how to reach the shop today or what the bill said
     * then is not decided; this records the current choice, not a fix.
     */
    public function test_d17_seller_contact_details_stay_live_pending_a_decision(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $this->setShop($shop->id, ['phone' => '0141-1111111']);
        $invoice = $this->finalizedInvoice($owner, $shop->id);
        $this->setShop($shop->id, ['phone' => '0141-2222222']);

        $this->assertStringContainsString('0141-2222222', $this->printInvoice($owner, $invoice));
    }

    /**
     * Assert a snapshot key is PRESENT and NULL — the shape D-13c turns on, and
     * the one array_key_exists() distinguishes from an absent key.
     */
    private function assertSnapshotRecordsNull(Invoice $invoice, string $section, string $key): void
    {
        $row = DB::table('invoice_render_snapshots')->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($row, 'finalization must have captured a snapshot');

        $payload = json_decode((string) $row->snapshot, true);

        $this->assertArrayHasKey($key, $payload[$section] ?? [],
            "{$section}.{$key} must be CAPTURED for this fixture to mean anything");
        $this->assertNull($payload[$section][$key],
            "{$section}.{$key} must be captured as NULL, not merely absent");
    }

    /**
     * Rewrite a stored render snapshot to the SHAPE it would have had before
     * some keys existed, by deleting them. Fixture-only, and deletion-only on
     * purpose: nothing here invents a value a bill never recorded.
     *
     * @param  array<string, list<string>>  $sections
     */
    private function stripSnapshotKeys(Invoice $invoice, array $sections): void
    {
        $row = DB::table('invoice_render_snapshots')->where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($row, 'finalization must have captured a snapshot to strip');

        $payload = json_decode((string) $row->snapshot, true);

        foreach ($sections as $section => $keys) {
            $this->assertIsArray($payload[$section] ?? null, "snapshot has no '{$section}' section to strip");

            foreach ($keys as $key) {
                $this->assertArrayHasKey(
                    $key,
                    $payload[$section],
                    "{$section}.{$key} is not captured at all — this fixture would be stripping nothing"
                );
                unset($payload[$section][$key]);
            }
        }

        DB::table('invoice_render_snapshots')
            ->where('invoice_id', $invoice->id)
            ->update(['snapshot' => json_encode($payload)]);
    }

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
    private function finalizedInvoice(User $owner, int $shopId, array $customerValues = []): Invoice
    {
        $customer = $this->createCustomer($shopId);
        if ($customerValues !== []) {
            DB::table('customers')->where('id', $customer->id)->update($customerValues);
        }

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
