<?php

namespace App\Services\Reporting\Render;

use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;

/**
 * Adapts the canonical dataset into Blade-ready view data for the interactive
 * report screen (frozen §3.1). It is intentionally NOT a {@see ReportRenderer}:
 * the screen produces no file. Display values are formatted through the same
 * {@see ValueFormatter} the PDF uses, so on-screen totals match the PDF to the
 * paisa/milligram.
 *
 * Consumes the dataset ONLY — no queries (frozen §3.1, §13).
 */
final class ScreenRenderer
{
    public function __construct(
        private readonly ValueFormatter $formatter = new ValueFormatter(),
    ) {
    }

    /**
     * @return array{
     *     meta: \App\Services\Reporting\Dataset\ReportMeta,
     *     isEmpty: bool,
     *     isMultiSection: bool,
     *     totalRowCount: int,
     *     sections: array<int, array<string, mixed>>
     * }
     */
    public function present(ReportDataset $dataset, ReportRequest $request): array
    {
        $sections = [];
        foreach ($dataset->sections as $section) {
            $sections[] = $this->presentSection($section);
        }

        return [
            'meta'           => $dataset->meta,
            'isEmpty'        => $dataset->isEmpty(),
            'isMultiSection' => $dataset->isMultiSection(),
            'totalRowCount'  => $dataset->totalRowCount(),
            'sections'       => $sections,
        ];
    }

    /**
     * @return array{
     *     key: string,
     *     title: string,
     *     rowCount: int,
     *     columns: array<int, array{key:string, label:string, numeric:bool}>,
     *     rows: array<int, array<int, array{key:string, display:string, numeric:bool}>>,
     *     totals: array<string, string>,
     *     hasTotals: bool
     * }
     */
    private function presentSection(ReportSection $section): array
    {
        $columns = [];
        foreach ($section->columns as $column) {
            $columns[] = [
                'key'     => $column->key,
                'label'   => $column->label,
                'numeric' => $column->type->isNumeric(),
            ];
        }

        $rows = [];
        foreach ($section->rows as $row) {
            $cells = [];
            foreach ($section->columns as $column) {
                $cells[] = [
                    'key'     => $column->key,
                    'display' => $this->formatter->format($row[$column->key] ?? null, $column->type),
                    'numeric' => $column->type->isNumeric(),
                    // Unformatted value for client-side sorting (numbers/dates sort
                    // correctly instead of by their formatted string). Screen-only;
                    // PDF/CSV/Excel renderers are unaffected.
                    'raw'     => $row[$column->key] ?? null,
                ];
            }
            $rows[] = $cells;
        }

        $totals = [];
        foreach ($section->columns as $column) {
            if (array_key_exists($column->key, $section->totals)) {
                $totals[$column->key] = $this->formatter->format($section->totals[$column->key], $column->type);
            }
        }

        return [
            'key'       => $section->key,
            'title'     => $section->title,
            'rowCount'  => $section->rowCount(),
            'columns'   => $columns,
            'rows'      => $rows,
            'totals'    => $totals,
            'hasTotals' => $section->hasTotals(),
        ];
    }
}
