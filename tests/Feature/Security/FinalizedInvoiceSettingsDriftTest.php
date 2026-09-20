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
 * S3-05 (NEW, OPEN) — a finalized invoice reprints using TODAY'S shop settings.
 *
 * WHAT THIS IS, AND WHAT IT IS NOT
 * --------------------------------
 * These are CHARACTERIZATION tests. They pass on first run, deliberately, and
 * they assert the behaviour that exists rather than the behaviour that should.
 * They are not TDD and must not be read as such: no production code is being
 * driven here. Their job is to make a finding reproducible and to fail loudly on
 * the day someone fixes it, so the fix cannot land unnoticed.
 *
 * WHY IT MATTERS, AND WHY IT IS NARROWER THAN IT SOUNDS
 * ----------------------------------------------------
 * I previously claimed the signature work made historical invoice rendering
 * immutable. That claim was too broad and is narrowed here. The signature
 * snapshot fixes the SIGNATURE. It fixes nothing else. invoice_print.blade.php
 * reads shop_billing_settings live in 45 places (counted 2026-09-21; an earlier
 * report said "~25", which was wrong), and every one of them re-resolves at
 * reprint time.
 *
 * Most of those 45 are cosmetic — theme colour, font tier, paper size, subtitle,
 * tagline. Drift there is untidy, not dangerous. Two are not cosmetic, and they
 * are the reason this file exists:
 *
 *   igst_mode         line 42, used at line 752. Decides whether the invoice
 *                     prints ONE IGST row or TWO CGST/SGST rows.
 *   hsnForMetal()     line 160, used at line 601. Resolves the HSN code printed
 *                     against each line item.
 *
 * MONEY IS NOT AFFECTED, and saying otherwise would overstate this. The amounts
 * come off the invoice itself — $invoice->gst at line 103, $invoice->gst_rate at
 * line 109 — and $cgst/$sgst are simply $gst/2 at lines 107-108. Total tax is
 * identical either way. T-02 below pins that, because a reader who sees "GST
 * presentation changes after finalization" will reasonably assume the totals
 * moved, and they do not.
 *
 * What changes is the tax CHARACTERIZATION on a statutory document. An invoice
 * issued as an intra-state supply (CGST+SGST) reprints as an inter-state supply
 * (IGST) at the same total, and its HSN codes can change to whatever the shop
 * last configured. Two prints of one invoice number can therefore disagree about
 * what kind of supply occurred.
 *
 * STATUS. Finding: OPEN. Local fix: NONE — no fix is attempted here. This is
 * outside the signature work's scope and is filed so it is not silently absorbed
 * into a claim about historical rendering that it does not support. A fix would
 * extend the finalized invoice's snapshot to cover igst_mode and the HSN map,
 * with the same legacy-snapshot compatibility rules the signature snapshot uses.
 */
class FinalizedInvoiceSettingsDriftTest extends TestCase
{
    use CreatesTestTenant;
    use RefreshDatabase;

    // -------------------------------------------------------------------- T-01
    /**
     * Flipping igst_mode after finalization changes how the SAME finalized
     * invoice describes its own tax.
     */
    public function test_t01_flipping_igst_mode_changes_the_tax_rows_of_an_already_finalized_invoice(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id);

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('false')]);
        $intra = $this->printAs($owner, $invoice);

        $this->assertStringContainsString('CGST', $intra, 'baseline: an intra-state shop prints CGST');
        $this->assertStringContainsString('SGST', $intra, 'baseline: and SGST');
        $this->assertStringNotContainsString('IGST', $intra, 'baseline: and not IGST');

        // Nothing about the invoice changes. Only the shop setting.
        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $inter = $this->printAs($owner, $invoice);

        $this->assertStringContainsString(
            'IGST',
            $inter,
            'S3-05: the same finalized invoice now describes itself as an inter-state supply'
        );
        $this->assertStringNotContainsString(
            'CGST',
            $inter,
            'S3-05: and no longer shows the CGST/SGST split it was issued with'
        );
    }

    // -------------------------------------------------------------------- T-02
    /**
     * The bound on T-01, asserted rather than assumed.
     *
     * The presentation drifts; the figures do not. Without this, T-01 reads as
     * though finalized totals were mutable, which would be a far more serious
     * claim than the evidence supports.
     */
    public function test_t02_the_drift_does_not_change_any_stored_figure(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id);

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('false')]);
        $this->printAs($owner, $invoice);

        $this->setBilling($shop->id, ['igst_mode' => DB::raw('true')]);
        $after = $this->printAs($owner, $invoice);

        $fresh = Invoice::withoutTenant()->find($invoice->id);

        $this->assertSame('30.00', (string) $fresh->gst, 'the stored tax figure must not move');
        $this->assertSame('1030.00', (string) $fresh->total, 'the stored total must not move');

        // And the printed total still agrees with the stored one.
        $this->assertStringContainsString('1,030.00', $after, 'the printed total still matches the invoice');
    }

    // -------------------------------------------------------------------- T-03
    /**
     * The HSN code on a finalized line item is resolved from current settings.
     *
     * Separate from T-01 because it is a different mechanism — a method call on
     * the live settings model rather than a boolean branch — and a fix for one
     * would not automatically cover the other.
     */
    public function test_t03_hsn_codes_on_a_finalized_invoice_follow_current_settings(): void
    {
        [$owner, $shop] = $this->createRetailerTenant();
        $invoice = $this->finalizedInvoice($shop->id, withItem: true);

        $this->setBilling($shop->id, ['hsn_gold' => '7113']);
        $before = $this->printAs($owner, $invoice);

        $this->setBilling($shop->id, ['hsn_gold' => '9999']);
        $after = $this->printAs($owner, $invoice);

        $this->assertNotSame(
            $before,
            $after,
            'S3-05: changing the shop HSN map changes what a finalized invoice prints'
        );
        $this->assertStringContainsString(
            '9999',
            $after,
            'S3-05: the reprint carries the NEW HSN code, not the one the invoice was issued under'
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Money columns on Invoice are GUARDED by design (CONSTITUTION Article I),
     * so fixtures write them with forceFill exactly as the signature suite does.
     *
     * Line items are inserted while the invoice is still a DRAFT, then the
     * invoice is finalized. invoice_items_finalized_guard (Art. IX.A) refuses any
     * insert against an already-finalized invoice, and rightly so — the fixture
     * has to follow the same order the application does. The trigger is not
     * dropped, disabled or altered.
     */
    private function finalizedInvoice(int $shopId, bool $withItem = false): Invoice
    {
        $customer = $this->createCustomer($shopId);

        return TenantContext::runFor($shopId, function () use ($shopId, $customer, $withItem) {
            $invoice = new Invoice();
            $invoice->forceFill([
                'shop_id'        => $shopId,
                'customer_id'    => $customer->id,
                'invoice_number' => 'INV-DRIFT-'.fake()->unique()->numberBetween(1000, 99999),
                'gold_rate'      => 7200,
                'subtotal'       => 1000,
                'gst'            => 30,
                'gst_rate'       => 3,
                'wastage_charge' => 0,
                'discount'       => 0,
                'round_off'      => 0,
                'total'          => 1030,
                'status'         => Invoice::STATUS_DRAFT,
            ])->save();

            if ($withItem) {
                // invoice_items has no shop_id of its own; it is scoped through
                // its invoice. item_id is NOT NULL, so the line must point at a
                // real inventory item rather than a synthetic name.
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
            }

            $invoice->forceFill([
                'status'       => Invoice::STATUS_FINALIZED,
                'finalized_at' => now(),
            ])->save();

            return $invoice;
        });
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
     * invoice_print.blade.php reads `auth()->user()->shop->billingSettings`.
     * actingAs() keeps ONE User instance alive across both renders in a test, so
     * the relation loaded during the first render is still cached during the
     * second and the view would never see the settings change. A real request
     * builds a fresh User, so this caching does not exist in production.
     *
     * Unsetting the relation reproduces the real per-request state. Without it
     * T-01 fails for a reason that has nothing to do with the finding, which is
     * exactly how a genuine bug gets dismissed as a flaky test.
     */
    private function printAs(User $user, Invoice $invoice): string
    {
        $user->unsetRelation('shop');
        $this->actingAs($user);

        return TenantContext::runFor(
            (int) $user->shop_id,
            fn () => $this->get(route('invoices.print', $invoice))->assertOk()->getContent()
        );
    }
}
