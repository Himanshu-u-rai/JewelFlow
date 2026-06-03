<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Metal / old-gold liability (Phase 2 M3, report #11). All figures are FINE
 * GRAMS, not rupees.
 *
 * The shop's gold liability to customers comes from ADVANCES (customers
 * depositing their own gold for custom work). Advances pool into a single
 * `customer_advance` metal lot, so the authoritative NET liability is that
 * lot's remaining fine weight; per-customer figures are GROSS deposited
 * (consumption is pooled and cannot be split per customer). Old gold accepted
 * at POS (`old_metal_in`) is shop stock, NOT a liability — shown informationally.
 */
final class MetalLiabilityData
{
    public function __construct(
        public readonly Collection $rows,          // per-customer: customer_name, fine_deposited (GROSS advance deposits)
        public readonly float $totalAdvanceLiability, // NET fine grams owed (pooled customer_advance lot remaining)
        public readonly float $totalDeposited,        // GROSS fine grams deposited (Σ advance transactions)
        public readonly float $oldGoldAcceptedFine,   // informational — old gold taken as payment (shop stock)
        public readonly float $vaultOnHandFine,       // context — total fine grams physically on hand
        public readonly int $customerCount,
    ) {}
}
