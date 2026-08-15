<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Support\Historical\HistoricalMessages;
use App\Support\Historical\HistoricalMoney;

/**
 * Decides what a historical bill's tax figures MEAN, without ever changing them.
 *
 * THE RULE THIS FILE EXISTS TO ENFORCE: a missing tax figure is `unknown`, never
 * zero. Zero is a claim — "this sale carried no tax" — and the source did not
 * make it. Writing zero would turn a gap in a 2019 paper bill into a confident
 * assertion in a 2026 report.
 *
 * The shop's CURRENT GST configuration is never consulted. Rates changed, the
 * shop's registration changed, composition status changed. Recomputing a 2018
 * bill at today's rate produces a number that was never on any piece of paper.
 *
 * Nothing produced here is a GST filing. It is analytics over a paper archive.
 */
class HistoricalTaxNormalizer
{
    /** Independent rounding of two halves of a bill lands within a rupee. */
    public const ROUNDING_TOLERANCE = 1.00;

    public const CODE_TAX_UNKNOWN          = 'tax_unknown';
    public const CODE_TAX_SUMMARY_ONLY     = 'tax_summary_only';
    public const CODE_TAX_ZERO_UNCONFIRMED = 'tax_zero_unconfirmed';
    public const CODE_TAX_SPLIT_CONFLICT   = 'tax_split_conflict';
    public const CODE_TAX_COMPONENT_DRIFT  = 'tax_component_drift';
    public const CODE_TAX_MODE_UNKNOWN     = 'tax_mode_unknown';
    public const CODE_TOTAL_MISMATCH       = 'total_mismatch';
    public const CODE_TOTAL_ROUNDING       = 'total_rounding';
    public const CODE_TOTAL_EXACT          = 'total_exact';
    public const CODE_SETTLEMENT_MISMATCH  = 'settlement_mismatch';
    public const CODE_SETTLEMENT_ROUNDING  = 'settlement_rounding';
    public const CODE_SETTLEMENT_PARTIAL   = 'settlement_partial';

    /**
     * @param  array{taxable_amount?: ?float, tax_total?: ?float, cgst?: ?float, sgst?: ?float,
     *               igst?: ?float, cess?: ?float, discount?: ?float, rounding?: ?float,
     *               grand_total: float, paid_amount?: ?float, outstanding_amount?: ?float}  $source
     * @return array{completeness: string, mode: string, tax_total: ?float, snapshot: array<string, mixed>}
     */
    public function normalize(
        array $source,
        string $mode,
        bool $zeroTaxConfirmed,
        HistoricalMessages $messages,
    ): array {
        $cgst = $source['cgst'] ?? null;
        $sgst = $source['sgst'] ?? null;
        $igst = $source['igst'] ?? null;
        $cess = $source['cess'] ?? null;

        $hasSplit = $cgst !== null || $sgst !== null || $igst !== null || $cess !== null;

        // An intrastate sale is CGST+SGST. An interstate sale is IGST. A bill
        // carrying both positive is either two bills or a broken column mapping,
        // and either way importing it silently doubles the tax in every report.
        if ((($cgst ?? 0) > 0 || ($sgst ?? 0) > 0) && ($igst ?? 0) > 0) {
            $messages->error(
                self::CODE_TAX_SPLIT_CONFLICT,
                'This bill has both CGST/SGST and IGST. A sale is either intrastate or interstate, never both. '
                . 'Check the column mapping, or correct the source data.',
                'igst'
            );
        }

        $componentSum = $hasSplit
            ? round(($cgst ?? 0) + ($sgst ?? 0) + ($igst ?? 0) + ($cess ?? 0), 2)
            : null;

        $declaredTotal = $source['tax_total'] ?? null;

        // The components are the more specific statement, so they win when both
        // are present — but a disagreement is reported, never averaged away.
        if ($componentSum !== null && $declaredTotal !== null
            && ! HistoricalMoney::equal($componentSum, $declaredTotal)) {
            $messages->error(
                self::CODE_TAX_COMPONENT_DRIFT,
                sprintf(
                    'The tax breakdown adds up to %s but the bill\'s tax total says %s. Correct the mapping or the source data.',
                    number_format($componentSum, 2),
                    number_format($declaredTotal, 2)
                ),
                'tax_total'
            );
        }

        $taxTotal = $componentSum ?? $declaredTotal;

        $completeness = $this->completeness($hasSplit, $taxTotal, $zeroTaxConfirmed, $messages);

        if ($completeness === HistoricalSalesDocument::TAX_NOT_APPLICABLE) {
            $mode = HistoricalSalesDocument::TAX_MODE_NOT_APPLICABLE;
        }

        if ($taxTotal !== null && $taxTotal > 0 && $mode === HistoricalSalesDocument::TAX_MODE_UNKNOWN) {
            $messages->warning(
                self::CODE_TAX_MODE_UNKNOWN,
                'This bill has tax but the import profile does not say whether the amounts include it. '
                . 'The totals cannot be reconciled until tax-inclusive or tax-exclusive is chosen.',
                'tax_mode'
            );
        }

        $this->reconcileTotal($source, $mode, $taxTotal, $messages);
        $this->reconcileSettlement($source, $messages);

        return [
            'completeness' => $completeness,
            'mode'         => $mode,
            'tax_total'    => $taxTotal,
            'snapshot'     => [
                // Rule 6: what the paper said, and what we made of it, side by side
                // and permanently. A future correction to our interpretation must
                // never require re-reading a file that may no longer exist.
                'source' => [
                    'tax_total' => $declaredTotal,
                    'cgst'      => $cgst,
                    'sgst'      => $sgst,
                    'igst'      => $igst,
                    'cess'      => $cess,
                ],
                'normalized' => [
                    'tax_total'     => $taxTotal,
                    'component_sum' => $componentSum,
                    'mode'          => $mode,
                    'completeness'  => $completeness,
                ],
                'disclaimer' => 'Historical analytics only. Not a GST filing.',
            ],
        ];
    }

    private function completeness(
        bool $hasSplit,
        ?float $taxTotal,
        bool $zeroTaxConfirmed,
        HistoricalMessages $messages,
    ): string {
        if ($hasSplit) {
            if ($taxTotal !== null && $taxTotal <= 0 && $zeroTaxConfirmed) {
                return HistoricalSalesDocument::TAX_NOT_APPLICABLE;
            }

            return HistoricalSalesDocument::TAX_COMPLETE;
        }

        if ($taxTotal === null) {
            $messages->warning(
                self::CODE_TAX_UNKNOWN,
                'This bill carries no tax figures. It is recorded as tax unknown — not as zero tax.',
                'tax_total'
            );

            return HistoricalSalesDocument::TAX_UNKNOWN;
        }

        if ($taxTotal <= 0) {
            if ($zeroTaxConfirmed) {
                return HistoricalSalesDocument::TAX_NOT_APPLICABLE;
            }

            // The source DID say zero, so this is not `unknown`. But "zero" and
            // "no tax applied" are different claims and only the operator can
            // promote one to the other.
            $messages->warning(
                self::CODE_TAX_ZERO_UNCONFIRMED,
                'The source reports zero tax on this bill. Confirm that no tax applied before publishing, '
                . 'otherwise it stays recorded as a summary figure of zero.',
                'tax_total'
            );

            return HistoricalSalesDocument::TAX_SUMMARY_ONLY;
        }

        $messages->warning(
            self::CODE_TAX_SUMMARY_ONLY,
            'This bill has a total tax figure but no CGST/SGST/IGST breakdown.',
            'tax_total'
        );

        return HistoricalSalesDocument::TAX_SUMMARY_ONLY;
    }

    /**
     * Does the arithmetic on the paper close?
     *
     * The grand total is never adjusted to make it close. It is the number the
     * customer paid and the number printed on the bill; if our arithmetic
     * disagrees, our arithmetic — or the mapping feeding it — is what is wrong.
     */
    private function reconcileTotal(array $source, string $mode, ?float $taxTotal, HistoricalMessages $messages): void
    {
        $taxable = $source['taxable_amount'] ?? null;

        if ($taxable === null) {
            return; // Nothing to reconcile against; the header-only warning covers it.
        }

        if ($mode === HistoricalSalesDocument::TAX_MODE_UNKNOWN && ($taxTotal ?? 0) > 0) {
            return; // Already warned; reconciling on a guessed mode proves nothing.
        }

        $grandTotal = (float) $source['grand_total'];
        $discount   = $source['discount'] ?? 0.0;
        $rounding   = $source['rounding'] ?? 0.0;

        // Inclusive means the taxable figure already contains the tax; adding it
        // again is the single most common historical-import error.
        $expected = $mode === HistoricalSalesDocument::TAX_MODE_EXCLUSIVE
            ? $taxable - $discount + ($taxTotal ?? 0.0) + $rounding
            : $taxable - $discount + $rounding;

        $difference = HistoricalMoney::differsBy($expected, $grandTotal);

        if (HistoricalMoney::equal($expected, $grandTotal)) {
            $messages->info(
                self::CODE_TOTAL_EXACT,
                'The bill\'s amounts reconcile exactly to its printed total.',
                'grand_total'
            );

            return;
        }

        if ($difference <= self::ROUNDING_TOLERANCE) {
            $messages->warning(
                self::CODE_TOTAL_ROUNDING,
                sprintf(
                    'The amounts reconcile to %s against a printed total of %s — a difference of %s. '
                    . 'The printed total is kept as-is.',
                    number_format($expected, 2),
                    number_format($grandTotal, 2),
                    number_format($difference, 2)
                ),
                'grand_total'
            );

            return;
        }

        $messages->error(
            self::CODE_TOTAL_MISMATCH,
            sprintf(
                'The amounts add up to %s but the printed total is %s — a difference of %s. '
                . 'Correct the column mapping or the source data. The printed total will not be adjusted to fit.',
                number_format($expected, 2),
                number_format($grandTotal, 2),
                number_format($difference, 2)
            ),
            'grand_total'
        );
    }

    /**
     * Paid + outstanding must equal the bill, but only when the source told us
     * both. One half known and one half missing is preserved as incomplete —
     * deriving the missing half would invent a receivable that no ledger has.
     */
    private function reconcileSettlement(array $source, HistoricalMessages $messages): void
    {
        $paid        = $source['paid_amount'] ?? null;
        $outstanding = $source['outstanding_amount'] ?? null;

        if ($paid === null && $outstanding === null) {
            return;
        }

        if ($paid === null || $outstanding === null) {
            $messages->warning(
                self::CODE_SETTLEMENT_PARTIAL,
                'Only one of paid / outstanding is available for this bill. The missing figure stays unknown; '
                . 'it is not calculated from the total.',
                'paid_amount'
            );

            return;
        }

        $grandTotal = (float) $source['grand_total'];
        $difference = HistoricalMoney::differsBy($paid + $outstanding, $grandTotal);

        if ($difference < 0.005) {
            return;
        }

        if ($difference <= self::ROUNDING_TOLERANCE) {
            $messages->warning(
                self::CODE_SETTLEMENT_ROUNDING,
                sprintf(
                    'Paid plus outstanding is %s against a total of %s — a difference of %s.',
                    number_format($paid + $outstanding, 2),
                    number_format($grandTotal, 2),
                    number_format($difference, 2)
                ),
                'paid_amount'
            );

            return;
        }

        $messages->error(
            self::CODE_SETTLEMENT_MISMATCH,
            sprintf(
                'Paid (%s) plus outstanding (%s) is %s, but the bill total is %s. Correct the source figures.',
                number_format($paid, 2),
                number_format($outstanding, 2),
                number_format($paid + $outstanding, 2),
                number_format($grandTotal, 2)
            ),
            'paid_amount'
        );
    }
}
