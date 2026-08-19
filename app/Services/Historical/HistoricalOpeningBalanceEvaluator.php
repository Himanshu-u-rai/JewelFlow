<?php

namespace App\Services\Historical;

use App\Models\CustomerOpeningBalance;
use App\Models\Historical\HistoricalSalesDocument;

/**
 * Answers one question: could this historical bill's receivable already be
 * counted inside the linked customer's opening balance? Nothing here writes
 * a resolution — that is a separate, explicit operator decision recorded on
 * the document (see HistoricalDocumentLifecycleService::resolveOpeningBalance).
 *
 * Unlinked documents (customer_id === null) are always NONE: there is no
 * customer-specific opening balance to overlap with. Matching stays
 * suggestion-only (Commit 2), so this never chases a suggested candidate —
 * only an actual, operator-confirmed link.
 *
 *   NONE   — no receivable opening-balance row, or the bill is dated strictly
 *            after the latest one. Nothing to resolve.
 *   MEDIUM — on/before the cutoff, but outstanding is zero or unknown.
 *            Informational, never blocks.
 *   HIGH   — on/before the cutoff AND outstanding > 0. Blocks publish until
 *            resolved.
 */
class HistoricalOpeningBalanceEvaluator
{
    public const NONE   = 'none';
    public const MEDIUM = 'medium';
    public const HIGH   = 'high';

    public function evaluate(HistoricalSalesDocument $document): string
    {
        if ($document->customer_id === null || $document->document_date === null) {
            return self::NONE;
        }

        $cutoff = CustomerOpeningBalance::withoutTenant()
            ->where('shop_id', $document->shop_id)
            ->where('customer_id', $document->customer_id)
            ->where('direction', CustomerOpeningBalance::DIRECTION_RECEIVABLE)
            ->max('as_of_date');

        if ($cutoff === null) {
            return self::NONE;
        }

        if ($document->document_date->toDateString() > $cutoff) {
            return self::NONE;
        }

        $outstanding = $document->outstanding_amount_snapshot;

        return ($outstanding !== null && (float) $outstanding > 0) ? self::HIGH : self::MEDIUM;
    }

    /** Convenience for callers that only need the boolean flag stored on the document. */
    public function overlaps(HistoricalSalesDocument $document): bool
    {
        return $this->evaluate($document) !== self::NONE;
    }
}
