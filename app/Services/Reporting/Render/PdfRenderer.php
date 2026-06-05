<?php

namespace App\Services\Reporting\Render;

use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ExportFormat;
use Illuminate\Contracts\View\Factory as ViewFactory;
use InvalidArgumentException;

/**
 * Renders the shared report-document Blade print template to PDF via the
 * injected {@see HtmlToPdf} engine (frozen §4). Numeric columns are
 * right-aligned with tabular-nums, table headers repeat per page and rows never
 * split (CSS in the layout). Section subtotals come from each section's totals;
 * a grand total is summed across same-keyed numeric columns. Provenance and the
 * watermark are driven entirely by {@see ReportMeta}.
 *
 * Consumes the dataset ONLY — no queries, no Eloquent (frozen §3.1, §13).
 */
final class PdfRenderer implements ReportRenderer
{
    private const VIEW = 'reporting.layouts.report-document';

    public function __construct(
        private readonly HtmlToPdf $engine,
        private readonly ViewFactory $views,
        private readonly ValueFormatter $formatter = new ValueFormatter(),
    ) {
    }

    public function supports(ExportFormat $format): bool
    {
        return $format === ExportFormat::Pdf;
    }

    public function render(ReportDataset $dataset, ReportRequest $request): RenderedOutput
    {
        if (! $this->supports($request->format)) {
            throw new InvalidArgumentException(
                "PdfRenderer cannot render format [{$request->format->value}]."
            );
        }

        // Resolve columns per section once, in section order.
        $columnsByKey = [];
        foreach ($dataset->sections as $section) {
            $columnsByKey[$section->key] = $section->columns;
        }

        $html = $this->views->make(self::VIEW, [
            'meta'        => $dataset->meta,
            'dataset'     => $dataset,
            'formatter'   => $this->formatter,
            'columnsFor'  => static fn (string $key): array => $columnsByKey[$key] ?? [],
            'grandTotals' => $this->grandTotals($dataset),
        ])->render();

        $pdf = $this->engine->convert($html);

        return new RenderedOutput(
            filename: $this->filename($dataset),
            mimeType: 'application/pdf',
            contents: $pdf,
        );
    }

    /**
     * Sum each numeric column across all sections that share its key. Returns a
     * map keyed by column key, ordered by first appearance, with the resolved
     * label, type, and summed value. Empty when there is nothing to total.
     *
     * @return array<string, array{label:string, type:\App\Services\Reporting\Definition\ColumnType, value:float}>
     */
    private function grandTotals(ReportDataset $dataset): array
    {
        if (! $dataset->isMultiSection()) {
            return [];
        }

        $totals = [];
        foreach ($dataset->sections as $section) {
            foreach ($section->columns as $column) {
                if (! $column->type->isNumeric()) {
                    continue;
                }
                $sum = $this->sumColumn($section, $column);
                if ($sum === null) {
                    continue;
                }
                if (! isset($totals[$column->key])) {
                    $totals[$column->key] = [
                        'label' => $column->label,
                        'type'  => $column->type,
                        'value' => 0.0,
                    ];
                }
                $totals[$column->key]['value'] += $sum;
            }
        }

        return $totals;
    }

    private function sumColumn(ReportSection $section, ColumnDefinition $column): ?float
    {
        if (array_key_exists($column->key, $section->totals)) {
            return $this->toFloat($section->totals[$column->key]);
        }

        $sum = 0.0;
        $seen = false;
        foreach ($section->rows as $row) {
            if (! array_key_exists($column->key, $row) || $row[$column->key] === null) {
                continue;
            }
            $sum += $this->toFloat($row[$column->key]);
            $seen = true;
        }

        return $seen ? $sum : null;
    }

    private function toFloat(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    private function filename(ReportDataset $dataset): string
    {
        return $this->slug($dataset->meta->reportKey) . '-' . $dataset->meta->generatedAt->format('Ymd-His') . '.pdf';
    }

    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($value)) ?? $value;

        return trim($slug, '-') ?: 'report';
    }
}
