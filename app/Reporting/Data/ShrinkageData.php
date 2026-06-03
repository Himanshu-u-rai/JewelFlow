<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Metal shrinkage / loss-variance report (Phase 2, report #15).
 *
 * Over COMPLETED job orders in the period, reconciles the gram trail:
 *   issued_fine = items_returned_fine + leftover_returned_fine + declared_wastage + UNACCOUNTED
 * "Declared wastage" is the metal the karigar legitimately consumed/lost while
 * making the piece. "Unaccounted variance" is the residual that the receive
 * path could not explain — it should be ~0; anything else is a process gap to
 * investigate, NOT a finance adjustment. Reads completed job_orders only; the
 * receive path is never touched.
 */
final class ShrinkageData
{
    public function __construct(
        public readonly Collection $rows,        // per-karigar: karigar_name, job_count, issued_fine, returned_fine, leftover_fine, wastage_fine, wastage_pct, unaccounted_fine
        public readonly Collection $byMetal,     // per metal_type: metal_type, issued_fine, wastage_fine, wastage_pct, unaccounted_fine
        public readonly float $totalIssued,
        public readonly float $totalReturned,
        public readonly float $totalLeftover,
        public readonly float $totalWastage,
        public readonly float $totalUnaccounted,
        public readonly float $wastagePct,       // total wastage as % of total issued
        public readonly int $jobCount,
        public readonly string $periodLabel,
    ) {}
}
