<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * GSTR-1-shaped sales data for a period.
 *
 * B2B  = invoices carrying a buyer GSTIN (registered buyer).
 * B2CS = everything else, aggregated rate-wise + place-of-supply-wise.
 * HSN summary and the credit-note section complete the GSTR-1 picture.
 *
 * All figures come from persisted invoice/credit-note columns via TaxService;
 * no GST is recomputed (CONSTITUTION §7).
 */
final class GstR1Data
{
    public function __construct(
        public readonly Collection $b2b,          // per-invoice rows for registered buyers
        public readonly Collection $b2cs,         // rate × place-of-supply aggregates
        public readonly Collection $hsnSummary,   // per-HSN taxable + gst
        public readonly Collection $creditNotes,  // CN rows (returns)
        public readonly float $taxable,
        public readonly float $cgst,
        public readonly float $sgst,
        public readonly float $igst,
        public readonly float $totalGst,
        public readonly int $invoiceCount,
        public readonly float $cnTaxable,
        public readonly float $cnGst,
        public readonly int $cnCount,
    ) {}
}
