<?php

namespace App\Services\Reporting\Render;

use App\Services\Reporting\Definition\ExportFormat;
use InvalidArgumentException;

/**
 * Resolves the correct file renderer for an export format (frozen §3.1, §5).
 * PDF/Excel/CSV map to the three {@see ReportRenderer} implementations; Screen
 * is interactive and is handled directly by {@see ScreenRenderer}, so requesting
 * it here is an error.
 *
 * The three renderers are injected (the lead wires the container later); this
 * factory only routes by format.
 */
final class RendererFactory
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly ExcelRenderer $excel,
        private readonly CsvRenderer $csv,
    ) {
    }

    public function for(ExportFormat $format): ReportRenderer
    {
        return match ($format) {
            ExportFormat::Pdf   => $this->pdf,
            ExportFormat::Excel => $this->excel,
            ExportFormat::Csv   => $this->csv,
            ExportFormat::Screen => throw new InvalidArgumentException(
                'Screen is not a file renderer — use ScreenRenderer::present() instead.'
            ),
        };
    }

    /** @return ReportRenderer[] the three file renderers, in canonical order. */
    public function fileRenderers(): array
    {
        return [$this->pdf, $this->excel, $this->csv];
    }
}
