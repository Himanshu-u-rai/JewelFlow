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
 */
final class DuesAgingData
{
    public function __construct(
        public readonly Collection $rows,   // per-customer: customer_name, mobile, invoice_count, current, d3160, d6190, d90plus, total
        public readonly float $bucketCurrent,   // 0–30 days
        public readonly float $bucket3160,      // 31–60
        public readonly float $bucket6190,      // 61–90
        public readonly float $bucket90plus,    // 90+
        public readonly float $totalOutstanding,
        public readonly int $customerCount,
        public readonly int $invoiceCount,
        public readonly string $asOf,            // Y-m-d snapshot date
    ) {}
}
