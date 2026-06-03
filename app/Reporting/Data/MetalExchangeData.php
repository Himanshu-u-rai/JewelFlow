<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Metal-exchange transaction report (legacy report extracted to the Reporting
 * layer, M5).
 *
 * Old-gold / old-silver taken in as payment over a date range, with per-metal
 * summaries (gross / fine / value / count). Aggregation moved out of
 * MetalExchangeReportController with no behaviour change. Reads invoice_payments
 * (old_gold / old_silver modes) only.
 */
final class MetalExchangeData
{
    public function __construct(
        public readonly Collection $rows,    // InvoicePayment rows with invoice.customer
        public readonly array $goldSummary,  // ['gross','fine','value','count']
        public readonly array $silverSummary,
    ) {}
}
