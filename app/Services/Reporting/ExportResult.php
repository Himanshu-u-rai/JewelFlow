<?php

namespace App\Services\Reporting;

use App\Services\Reporting\Render\RenderedOutput;

/**
 * Outcome of one rendered export: the file bytes plus the row count (recorded on
 * the export-audit row, §16).
 */
final class ExportResult
{
    public function __construct(
        public readonly RenderedOutput $output,
        public readonly int $rowCount,
    ) {
    }
}
