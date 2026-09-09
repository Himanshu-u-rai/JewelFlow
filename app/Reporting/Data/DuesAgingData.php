<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Customer dues aging (Phase 2 M3, report #8).
 *
 * A point-in-time snapshot (as of `asOf`) of money owed to the shop on
 * FINALIZED invoices — outstanding = invoice total − collected payments —
 * bucketed by the age of the invoice's accounting date. Only invoices with a
 * positive outstanding balance are included (so reversals / fully-paid /
 * over-collected rows fall out naturally). Canonical sale scope; reads
 * persisted invoice totals + invoice_payments only.
 *
 * HISTORICAL-mode fields (owner-agreed reporting semantics) are always
 * empty/0 for LIVE — `duesAging()` never passes them.
 */
final class DuesAgingData
{
    public function __construct(
        public readonly Collection $rows,   // per (linked) customer: customer_name, mobile, invoice_count, current, d3160, d6190, d90plus, total
        public readonly float $bucketCurrent,   // 0–30 days
        public readonly float $bucket3160,      // 31–60
        public readonly float $bucket6190,      // 61–90
        public readonly float $bucket90plus,    // 90+
        public readonly float $totalOutstanding,
        public readonly int $customerCount,
        public readonly int $invoiceCount,
        public readonly string $asOf,            // Y-m-d snapshot date
        // Documents with a NULL outstanding_amount_snapshot ("source didn't
        // say") — mathematically impossible to sum, excluded from every
        // total above and disclosed here so the total is never mistaken for
        // complete. The only genuine exclusion left in HISTORICAL mode.
        public readonly int $unknownOutstandingCount = 0,
        // Overlap-classification counts — DISCLOSURE ONLY, never subtracted
        // from `rows`/totals above. This report reads no opening balance, so
        // an overlap flag is never grounds to remove a historical snapshot
        // amount; these counts drive a "Notes" warning, nothing else.
        public readonly int $separateFromOpeningBalanceCount = 0,
        public readonly int $includedInOpeningBalanceCount = 0,
        public readonly int $unresolvedOverlapCount = 0,
        // Snapshot-only/unlinked customers (`customer_id IS NULL`) — shown in
        // their own section under their recorded identity (customer_snapshot
        // name/mobile), never merged into `rows` or guessed onto a live
        // customer. Same column shape as `rows`.
        public readonly Collection $unlinkedRows = new Collection,
        public readonly int $unlinkedDocumentCount = 0,
    ) {}
}
