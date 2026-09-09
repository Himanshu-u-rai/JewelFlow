<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalSalesPayment;

/**
 * Payment-row aggregation and settlement suggestions, HISTORICAL-BATCH-3-UX-
 * CONTRACT-V2 §7/§8 + the owner-locked mismatch-warning decision.
 *
 * STATELESS, no DB writes — `suggestedPaidTotal()` takes plain payment-row
 * data (or `HistoricalSalesPayment` models) and sums it; nothing here ever
 * appends to `invoice_payments`, `CashTransaction`, `StoreCreditMovement` or
 * any other live ledger (see `HistoricalSalesPayment`'s own class docblock —
 * that invariant belongs to the model/migration layer, this service only
 * computes numbers from what is already in memory).
 */
class HistoricalPaymentSettlementService
{
    public function __construct(
        private readonly HistoricalCalculationSuggester $suggester = new HistoricalCalculationSuggester(),
    ) {
    }

    /**
     * `paid_total` auto-sums payment rows (§7). Accepts either a plain list of
     * amounts or a list of `HistoricalSalesPayment` models/arrays with an
     * `amount` key, so callers can pass whatever shape is convenient.
     *
     * @param  iterable<HistoricalSalesPayment|array{amount: float}|float>  $payments
     */
    public function suggestedPaidTotal(iterable $payments): float
    {
        $total = 0.0;

        foreach ($payments as $payment) {
            $total += match (true) {
                $payment instanceof HistoricalSalesPayment => (float) $payment->amount,
                is_array($payment)                         => (float) ($payment['amount'] ?? 0),
                default                                    => (float) $payment,
            };
        }

        return round($total, 2);
    }

    /**
     * The owner-locked mismatch rule: "Payment-row sum provides the suggested
     * paid total; if a manually overridden aggregate differs from payment
     * rows, show a strong warning requiring confirmation." A one-cent
     * rounding gap is not a mismatch — anything beyond that is.
     */
    public function hasMismatch(float $rowSum, float $overriddenPaidTotal): bool
    {
        return abs(round($rowSum - $overriddenPaidTotal, 2)) > 0.01;
    }

    public function mismatchAmount(float $rowSum, float $overriddenPaidTotal): float
    {
        return round($overriddenPaidTotal - $rowSum, 2);
    }

    /**
     * `outstanding = max(grand_total − paid_total, 0)` /
     * `advance_credit = max(paid_total − grand_total, 0)` — never a negative
     * outstanding, overpayment routes to the record-only advance/credit field
     * instead (§5/§16, `historical_docs_non_negative_check`).
     *
     * @return array{outstanding: float, advance_credit: float}
     */
    public function settle(float $grandTotal, float $paidTotal): array
    {
        return [
            'outstanding'     => $this->suggester->suggestOutstanding($grandTotal, $paidTotal),
            'advance_credit'  => $this->suggester->suggestAdvanceCredit($grandTotal, $paidTotal),
        ];
    }

    /**
     * Derived paid-status label (§7 — "never stored as a separate choice").
     * `full`/`partial`/`unpaid`, purely computed from paid_total vs
     * grand_total, with the same ±0.01 tolerance used elsewhere in this
     * module for rounding noise.
     */
    public function paidStatus(float $grandTotal, float $paidTotal): string
    {
        if ($paidTotal <= 0.01) {
            return 'unpaid';
        }

        if ($paidTotal + 0.01 >= $grandTotal) {
            return 'full';
        }

        return 'partial';
    }
}
