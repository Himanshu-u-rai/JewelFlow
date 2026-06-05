<?php

namespace App\Services\Reporting\Render;

use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Render\Excel\ReportWorkbook;
use InvalidArgumentException;
use Maatwebsite\Excel\Excel as ExcelWriter;

/**
 * Analysis-ready XLSX writer (frozen §5.2). One sheet per section — each a clean
 * single table with a frozen header row + auto-filter, real typed cells, and a
 * separated totals row — plus a leading "Report Info" sheet carrying all
 * provenance. NO banner rows in the data region.
 *
 * Builds the workbook in memory via maatwebsite/excel's `raw()` so Phase 0
 * renderers stay in-memory. Consumes the dataset ONLY (frozen §3.1, §13).
 */
final class ExcelRenderer implements ReportRenderer
{
    private const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function __construct(private readonly ExcelWriter $excel)
    {
    }

    public function supports(ExportFormat $format): bool
    {
        return $format === ExportFormat::Excel;
    }

    public function render(ReportDataset $dataset, ReportRequest $request): RenderedOutput
    {
        if (! $this->supports($request->format)) {
            throw new InvalidArgumentException(
                "ExcelRenderer cannot render format [{$request->format->value}]."
            );
        }

        $contents = $this->excel->raw(new ReportWorkbook($dataset), ExcelWriter::XLSX);

        return new RenderedOutput(
            filename: $this->filename($dataset),
            mimeType: self::MIME_XLSX,
            contents: $contents,
        );
    }

    private function filename(ReportDataset $dataset): string
    {
        return $this->slug($dataset->meta->reportKey)
            . '-' . $dataset->meta->generatedAt->format('Ymd-His') . '.xlsx';
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)) ?? $value;

        return trim($slug, '-') ?: 'report';
    }
}
