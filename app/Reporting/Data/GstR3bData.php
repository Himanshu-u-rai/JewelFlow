<?php

namespace App\Reporting\Data;

/**
 * GSTR-3B support summary — the month's net output-tax position.
 *
 * ITC is not modelled here (out of scope for the retail boundary); the field is
 * carried as 0 so the structure is filing-shaped and can be extended later.
 */
final class GstR3bData
{
    public function __construct(
        // Outward supplies (sales)
        public readonly float $outwardTaxable,
        public readonly float $outwardCgst,
        public readonly float $outwardSgst,
        public readonly float $outwardIgst,
        public readonly float $outwardGst,
        // Credit-note reversals (returns)
        public readonly float $cnTaxable,
        public readonly float $cnGst,
        // Net
        public readonly float $netTaxable,
        public readonly float $netGst,
        public readonly float $itc, // 0 until modelled
    ) {}
}
