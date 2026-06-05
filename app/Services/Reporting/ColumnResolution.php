<?php

namespace App\Services\Reporting;

/**
 * Outcome of the column gate (frozen §7). The final, ordered column keys the
 * dataset will populate, plus the audit facts the export-audit row records
 * (§16): whether any sensitive column survived the gate, and whether masked
 * values may be revealed in full.
 */
final class ColumnResolution
{
    /**
     * @param string[] $columnKeys final, ordered (catalogue order) column keys
     */
    public function __construct(
        public readonly array $columnKeys,
        public readonly bool $sensitiveIncluded,
        public readonly bool $revealMasked,
    ) {
    }
}
