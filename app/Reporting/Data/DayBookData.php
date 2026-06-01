<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Day-book / journal — a chronological accounting event stream for a period
 * (Phase 2 M2). Export-first, CA-friendly, reconciliation-oriented.
 *
 * Each event preserves its source reference (invoice/CN/cash) so a CA can
 * trace any line back to the originating document.
 */
final class DayBookData
{
    public function __construct(
        public readonly Collection $events,   // occurred_at,event_type,reference,party,amount,direction,source
        public readonly float $salesTotal,
        public readonly float $refundTotal,   // credit notes
        public readonly float $cashIn,
        public readonly float $cashOut,
        public readonly int $eventCount,
    ) {}
}
