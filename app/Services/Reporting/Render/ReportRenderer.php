<?php

namespace App\Services\Reporting\Render;

use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;

/**
 * The contract implemented by the three file renderers — PDF, Excel, CSV
 * (frozen §3.1, §5). Each consumes the canonical {@see ReportDataset} ONLY and
 * never queries the database: Screen total = PDF total = Excel total = CSV total
 * because they all read the same RowSet. Screen is interactive and produces no
 * file, so it lives in {@see ScreenRenderer} outside this interface.
 */
interface ReportRenderer
{
    /** Whether this renderer handles the given export format. */
    public function supports(ExportFormat $format): bool;

    /** Render the dataset into an in-memory file artifact. */
    public function render(ReportDataset $dataset, ReportRequest $request): RenderedOutput;
}
