<?php

namespace App\Services\Reporting;

use App\Services\Reporting\Definition\ExportFormat;

/**
 * Routes an export to the sync or queued path by estimated row count (frozen §20).
 * PDF carries a LOWER threshold than CSV/Excel because Chromium pagination is the
 * expensive operation. Thresholds come from config/reporting.php (single source
 * of truth) and are tunable, never hard-coded.
 *
 * NOTE (deploy gate, plan §1.6.1 / M-3): on a `sync` queue connection the queued
 * job runs inline; callers must hold large exports at the sync guardrail until a
 * worker exists. This router only decides the band — it does not dispatch.
 */
class ExportSizeRouter
{
    public function __construct(
        private readonly int $syncMaxRows,
        private readonly int $pdfSyncMaxRows,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            (int) config('reporting.size_thresholds.sync_max_rows', 5000),
            (int) config('reporting.size_thresholds.pdf_sync_max_rows', 1500),
        );
    }

    public function mode(ExportFormat $format, int $estimatedRows): ExportMode
    {
        return $estimatedRows <= $this->thresholdFor($format) ? ExportMode::Sync : ExportMode::Queued;
    }

    public function shouldQueue(ExportFormat $format, int $estimatedRows): bool
    {
        return $this->mode($format, $estimatedRows) === ExportMode::Queued;
    }

    public function thresholdFor(ExportFormat $format): int
    {
        return $format->isExpensiveToRender() ? $this->pdfSyncMaxRows : $this->syncMaxRows;
    }
}
