<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\InventoryService;
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
 * Dead Stock — on-hand inventory by age bucket plus the oldest actionable items
 * (Operational; GAP 2). Wraps InventoryService::deadStock() VERBATIM — the
 * report layer never re-ages stock. Point-in-time (AsOf), so no period filter.
 *
 * Item cost is a confidential dimension (frozen §19/§22): the oldest-items
 * `cost` column is sensitive (permission-gated) and the document is watermarked
 * CONFIDENTIAL, matching the Inventory Valuation report's treatment.
 */
class DeadStockDataset extends ReportDatasetService
{
    public const KEY = 'dead-stock';
    public const VERSION = 'dead-stock@1';

    public function __construct(private readonly InventoryService $inventory)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Dead Stock',
            classification: Cls::Operational,
            columns: [
                // Aging-bucket section
                Col::mandatory('bucket', 'Age Bucket', T::String),
                Col::mandatory('count', 'Items', T::Integer),
                Col::optional('value', 'Tag Value', T::Money),
                // Oldest-items section
                Col::mandatory('barcode', 'Barcode', T::String),
                Col::mandatory('design', 'Design', T::String),
                Col::optional('category', 'Category', T::String),
                Col::optional('metal', 'Metal', T::String),
                Col::mandatory('age_days', 'Age (days)', T::Integer),
                Col::optional('stocked', 'Stocked', T::Date),
                Col::sensitive('cost', 'Cost', T::Money),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [Filter::for(FK::AsOf)],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
            watermarkBaseline: 'CONFIDENTIAL',
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        $data = $this->inventory->deadStock($request->shopId);

        // --- Aging buckets ----------------------------------------------
        $bucketCols = $this->cols($def, $this->keep(['bucket', 'count', 'value'], $keys));
        $bucketRows = [
            ['bucket' => 'Fresh (0–90d)', 'count' => $data->freshCount, 'value' => $data->freshValue],
            ['bucket' => 'Aging (91–180d)', 'count' => $data->agingCount, 'value' => $data->agingValue],
            ['bucket' => 'Stale (181–365d)', 'count' => $data->staleCount, 'value' => $data->staleValue],
            ['bucket' => 'Dead (365d+)', 'count' => $data->deadCount, 'value' => $data->deadValue],
        ];
        $byAge = new ReportSection('by_age', 'By Age Bucket', $bucketCols, $bucketRows, $this->sum($bucketRows, $bucketCols));

        // --- Oldest actionable items ------------------------------------
        $itemCols = $this->cols($def, $this->keep(['barcode', 'design', 'category', 'metal', 'age_days', 'stocked', 'cost'], $keys));
        $itemRows = [];
        foreach ($data->oldest as $i) {
            $itemRows[] = [
                'barcode' => (string) $i->barcode,
                'design' => (string) $i->design,
                'category' => (string) $i->category,
                'metal' => (string) $i->metal_type,
                'age_days' => (int) $i->age_days,
                'stocked' => $i->created_at ? \Carbon\Carbon::parse($i->created_at)->toDateString() : '',
                'cost' => (float) $i->cost_price,
            ];
        }
        $oldest = new ReportSection('oldest', 'Oldest Items', $itemCols, $itemRows, []);

        return new ReportDataset([$byAge, $oldest], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return 4 + $this->inventory->deadStock($request->shopId)->oldest->count();
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
            if (! $col->type->isNumeric()) {
                continue;
            }
            $sum = 0.0;
            foreach ($rows as $row) {
                $sum += (float) ($row[$col->key] ?? 0);
            }
            $totals[$col->key] = round($sum, 2);
        }

        return $totals;
    }
}
