<?php

namespace App\Services\Reporting\Definition;

/**
 * The class that sets a report's export rules (frozen §11).
 * Receivables is a first-class value (frozen §22 RECEIVABLES), not a sub-label,
 * so every matrix row maps to exactly one classification with no exception.
 * The Dhiran *family* is an orthogonal axis — see ReportFamily.
 */
enum ReportClassification: string
{
    case Owner = 'owner';
    case Operational = 'operational';
    case Accounting = 'accounting';
    case Compliance = 'compliance';
    case Audit = 'audit';
    case Receivables = 'receivables';

    /** Compliance is rigid: fixed columns, fixed layout, no profile choice (frozen §9). */
    public function isRigid(): bool
    {
        return $this === self::Compliance;
    }

    /** Accounting + Compliance must offer the formal PDF (frozen §11). */
    public function requiresFormalPdf(): bool
    {
        return $this === self::Accounting || $this === self::Compliance;
    }

    /** Column toggles allowed at all? (none for Compliance — frozen §9). */
    public function allowsColumnFlex(): bool
    {
        return $this !== self::Compliance;
    }

    public function isAudit(): bool
    {
        return $this === self::Audit;
    }
}
