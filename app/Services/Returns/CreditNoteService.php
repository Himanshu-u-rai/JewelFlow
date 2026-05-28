<?php

namespace App\Services\Returns;

use App\Models\CreditNote;
use App\Models\ReturnOrder;
use App\Services\BusinessIdentifierService;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDOException;

/**
 * Issues credit notes against settled return orders. The credit-note record
 * is the legal accounting document — its row in the `credit_notes` table is
 * what gets carried into GSTR-1 as a credit-note adjustment.
 *
 * Phase 1: only `issue()` is implemented. Credit-note void / forward-correction
 * is Phase 5+.
 */
class CreditNoteService
{
    /**
     * Aggregate the return order's line items into a credit-note row.
     * Called from ReturnService at the submitted → settled transition.
     */
    public function issue(ReturnOrder $returnOrder, int $userId, ?int $exchangeOrderId = null): CreditNote
    {
        return DB::transaction(function () use ($returnOrder, $userId, $exchangeOrderId) {
            $existing = CreditNote::where('return_order_id', $returnOrder->id)->first();
            if ($existing) {
                throw new LogicException(
                    "CreditNote already exists for return order {$returnOrder->id} (CN {$existing->credit_note_number})."
                );
            }

            $lines = $returnOrder->lineItems()->get();
            if ($lines->isEmpty()) {
                throw new LogicException(
                    "Cannot issue credit note for return order {$returnOrder->id} — no line items."
                );
            }

            $subtotal  = (float) $lines->sum('refund_subtotal');
            $gst       = (float) $lines->sum('refund_gst');
            $discount  = (float) $lines->sum('refund_discount');
            $roundOff  = (float) $lines->sum('refund_round_off');
            $total     = (float) $lines->sum('refund_total');

            // Load invoice before CGST derivation — required for ratio computation
            // and total/overflow checks below.
            $invoice  = $returnOrder->invoice;

            // Snapshot place of supply and buyer GSTIN from the original invoice.
            // CN inherits these from the invoice it reverses — they don't change.
            $cnPlaceOfSupply = $invoice->place_of_supply_state_code ?? null;
            $cnBuyerGstin    = $invoice->buyer_gstin ?? null;

            // Derive CGST/SGST from original invoice's split ratio.
            // Falls back to 50/50 if original has no split or zero GST.
            $invoiceCgst = (float) ($invoice->cgst_amount ?? 0);
            $invoiceGst  = (float) ($invoice->gst ?? 0);
            if ($invoiceGst > 0.001 && $invoiceCgst > 0) {
                $cnCgst = round($gst * ($invoiceCgst / $invoiceGst), 2);
            } else {
                $cnCgst = round($gst / 2, 2);
            }
            $cnSgst = round($gst - $cnCgst, 2);
            $cnIgst = 0.0;

            // Invariant check before the DB trigger fires — gives a clearer
            // error if the lines and aggregate diverged due to a bug upstream.
            $expectedTotal = round($subtotal + $gst - $discount + $roundOff, 2);
            if (abs($expectedTotal - round($total, 2)) > 0.01) {
                throw new LogicException(
                    "Credit note aggregate mismatch: sum(refund_total)={$total} but "
                    . "expected (sum + gst - discount + round_off)={$expectedTotal}."
                );
            }
            $identity = BusinessIdentifierService::nextCreditNoteIdentifier((int) $returnOrder->shop_id);

            // Legacy-drift handling. The DB overflow guard enforces:
            //   SUM(credit_notes.total WHERE invoice_id=X) <= invoices.total
            // Some legacy invoices have minor drift between header totals and
            // line sums (e.g. header.gst != SUM(line.gst_amount)). When the
            // cumulative CN total would exceed invoice header total, we absorb
            // the overflow into the CN's `discount` field (which is subtracted
            // in the identity). This keeps both invariants satisfied:
            //   - total = subtotal + gst - discount + round_off  (CN guard)
            //   - SUM(total) <= invoice.total                     (overflow guard)
            // The per-line refund_total fields stay at their locked values;
            // the absorbing happens only at the CN aggregate.
            $invoiceTotal     = (float) ($invoice->total ?? 0);
            $priorCnTotal     = (float) \App\Models\CreditNote::where('invoice_id', $returnOrder->invoice_id)->sum('total');
            $allowedRoom      = round($invoiceTotal - $priorCnTotal, 2);
            $overshoot        = round($total - $allowedRoom, 2);
            if ($overshoot > 0.01) {
                $discount += $overshoot;
                $total     = round($total - $overshoot, 2);
            }

            $creditNote = new CreditNote();
            $creditNote->forceFill([
                'shop_id'              => $returnOrder->shop_id,
                'return_order_id'      => $returnOrder->id,
                'invoice_id'           => $returnOrder->invoice_id,
                'customer_id'          => $returnOrder->customer_id,
                'credit_note_sequence' => $identity['sequence'],
                'credit_note_number'   => $identity['number'],
                'subtotal'             => round($subtotal, 2),
                'gst'                  => round($gst, 2),
                'gst_rate'             => $invoice?->gst_rate ?? 0,
                'wastage_charge'       => 0,
                'discount'             => round($discount, 2),
                'round_off'            => round($roundOff, 4),
                'total'                => round($total, 2),
                'status'               => CreditNote::STATUS_ISSUED,
                'issued_at'            => now(),
                'issued_by_user_id'    => $userId,
                'reason'               => $returnOrder->reason,
                'cgst_amount'                => $cnCgst,
                'sgst_amount'                => $cnSgst,
                'igst_amount'                => $cnIgst,
                'exchange_order_id'          => $exchangeOrderId ?? null,
                'place_of_supply_state_code' => $cnPlaceOfSupply,
                'buyer_gstin'                => $cnBuyerGstin,
            ]);

            try {
                $creditNote->save();
            } catch (PDOException $e) {
                // The credit_notes_accounting_guard_trigger raises a PG exception when
                // cumulative CN totals would exceed the original invoice total.
                // Convert to LogicException so controllers can show a user-friendly message.
                if (str_contains($e->getMessage(), 'credit_notes_accounting_guard')
                    || str_contains($e->getMessage(), 'exceeds invoice total')
                    || str_contains($e->getMessage(), 'P0001')) {
                    throw new LogicException(
                        'The total refund amount exceeds the original invoice total. '
                        . 'This return cannot be processed.',
                        0,
                        $e
                    );
                }
                throw $e;
            }

            return $creditNote->refresh();
        });
    }
}
