<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\KarigarService;
use App\Reporting\ReportPeriod;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\FilterControl as Filter;
use App\Services\Reporting\Definition\FilterKey as FK;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;

/**
 * Karigar Shrinkage — manufacturing metal loss per karigar and per metal over a
 * period: issued vs in-items vs leftover vs wastage, with unaccounted grams
 * (Operational; GAP 2 tail). Wraps KarigarService::shrinkage() VERBATIM. The
 * unaccounted column is the integrity signal (issued − returned − leftover −
 * wastage) and is preserved exactly from the service.
 */
class ShrinkageDataset extends ReportDatasetService
{
    public const KEY = 'shrinkage';
    public const VERSION = 'shrinkage@1';

    public function __construct(private readonly KarigarService $karigar)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Karigar Shrinkage',
            classification: Cls::Operational,
            columns: [
                // per-karigar section
                Col::mandatory('karigar', 'Karigar', T::String),
                Col::optional('jobs', 'Jobs', T::Integer),
                Col::mandatory('issued', 'Issued (g)', T::Weight),
                Col::mandatory('in_items', 'In Items (g)', T::Weight),
                Col::optional('leftover', 'Leftover (g)', T::Weight),
                Col::mandatory('wastage', 'Wastage (g)', T::Weight),
                Col::optional('wastage_pct', 'Wastage %', T::Percent),
                Col::mandatory('unaccounted', 'Unaccounted (g)', T::Weight),
                // per-metal section
                Col::mandatory('metal', 'Metal', T::String),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::Period, true)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        $data = $this->karigar->shrinkage($request->shopId, $this->period($request));

        // --- per karigar ------------------------------------------------
        $kCols = $this->cols($def, $this->keep(
            ['karigar', 'jobs', 'issued', 'in_items', 'leftover', 'wastage', 'wastage_pct', 'unaccounted'],
            $keys,
        ));
        $kRows = [];
        foreach ($data->rows as $r) {
            $kRows[] = [
                'karigar' => (string) $r->karigar_name,
                'jobs' => (int) $r->job_count,
                'issued' => (float) $r->issued_fine,
                'in_items' => (float) $r->returned_fine,
                'leftover' => (float) $r->leftover_fine,
                'wastage' => (float) $r->wastage_fine,
                'wastage_pct' => (float) $r->wastage_pct,
                'unaccounted' => (float) $r->unaccounted_fine,
            ];
        }
        $byKarigar = new ReportSection('by_karigar', 'By Karigar', $kCols, $kRows, $this->sum($kRows, $kCols));

        // --- per metal --------------------------------------------------
        $mCols = $this->cols($def, $this->keep(['metal', 'issued', 'wastage', 'wastage_pct', 'unaccounted'], $keys));
        $mRows = [];
        foreach ($data->byMetal as $r) {
            $mRows[] = [
                'metal' => (string) $r->metal_type,
                'issued' => (float) $r->issued_fine,
                'wastage' => (float) $r->wastage_fine,
                'wastage_pct' => (float) $r->wastage_pct,
                'unaccounted' => (float) $r->unaccounted_fine,
            ];
        }
        $byMetal = new ReportSection('by_metal', 'By Metal', $mCols, $mRows, $this->sum($mRows, $mCols));

        return new ReportDataset([$byKarigar, $byMetal], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        $data = $this->karigar->shrinkage($request->shopId, $this->period($request));

        return $data->rows->count() + $data->byMetal->count();
    }

    private function period(ReportRequest $request): ReportPeriod
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return ReportPeriod::range($from ? $from->toDateString() : null, $to ? $to->toDateString() : null);
    }

    /**
     * @param  string[]  $candidate
     * @param  string[]  $allowed
     * @return string[]
     */
    private function keep(array $candidate, array $allowed): array
    {
        return array_values(array_filter($candidate, static fn ($k) => in_array($k, $allowed, true)));
    }

    /**
     * @param  string[]  $keys
     * @return ColumnDefinition[]
     */
    private function cols(ReportDefinition $def, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $column = $def->column($key);
            if ($column !== null) {
                $out[] = $column;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  ColumnDefinition[]  $cols
     * @return array<string, float|int>
     */
    private function sum(array $rows, array $cols): array
    {
        $totals = [];
        foreach ($cols as $col) {
            // Wastage % is not additive — never total it.
            if (! $col->type->isNumeric() || $col->type === T::Percent) {
                continue;
            }
            $sum = 0.0;
            foreach ($rows as $row) {
                $sum += (float) ($row[$col->key] ?? 0);
            }
            $totals[$col->key] = round($sum, $col->type === T::Weight ? 4 : 2);
        }

        return $totals;
    }
}
