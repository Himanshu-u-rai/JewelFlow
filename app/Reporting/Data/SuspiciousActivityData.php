<?php

namespace App\Reporting\Data;

use Illuminate\Support\Collection;

/**
 * Suspicious activity / compliance alerts (Phase 2 M4, report #17).
 *
 * Shop-facing surface of the compliance alerts the system already detects
 * (split transactions, missing PAN on high-value sales, threshold breaches) over
 * a period — so the owner can review and resolve them. Reads persisted
 * compliance_alerts only; no new heuristics, no tax exposure.
 */
final class SuspiciousActivityData
{
    public function __construct(
        public readonly Collection $rows,          // per-alert: alert_type, label, customer_name, invoice_number, created_at, resolved, detail
        public readonly Collection $countsByType,  // [alert_type => count]
        public readonly int $totalCount,
        public readonly int $unresolvedCount,
        public readonly string $periodLabel,
    ) {}
}
