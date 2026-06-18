<?php

namespace App\Services\Reporting\Reports;

use App\Models\Repair;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\FilterControl as Filter;
use App\Services\Reporting\Definition\FilterKey as FK;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;
use Illuminate\Support\Str;

/**
 * Repairs — repair jobs taken in (item, weight, estimated vs final cost,
 * status, due date). Operational data export, period filtered on created_at and
 * filterable by status.
 */
class RepairsDataset extends ReportDatasetService
{
    public const KEY = 'repairs';
    public const VERSION = 'repairs@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Repairs',
            classification: Cls::Operational,
            columns: [
                Col::mandatory('repair_number', 'Repair No.', T::String),
                Col::mandatory('created_at', 'Date', T::DateTime),
                Col::optional('customer', 'Customer', T::String),
                Col::mandatory('item_description', 'Item', T::String),
                Col::optional('metal_type', 'Metal', T::String),
                Col::optional('gross_weight', 'Gross Wt (g)', T::Weight),
                Col::optional('purity', 'Purity', T::Decimal),
                Col::optional('estimated_cost', 'Estimated', T::Money),
                Col::optional('final_cost', 'Final', T::Money),
                Col::mandatory('status', 'Status', T::String),
                Col::optional('due_date', 'Due', T::Date),
            ],
            profiles: [P::Summary, P::Detailed, P::Raw],
            filters: [
                Filter::for(FK::Period),
                Filter::for(FK::Status),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $repairs = $this->query($request)->with('customer')->get();

        $rows = [];
        $final = 0.0;
        foreach ($repairs as $r) {
            $rows[] = [
                'repair_number' => $r->repair_number,
                'created_at' => $r->created_at,
                'customer' => $r->customer?->name ?? 'Walk-in',
                'item_description' => $r->item_description,
                'metal_type' => $r->metal_type,
                'gross_weight' => (float) $r->gross_weight,
                'purity' => $r->purity !== null ? (float) $r->purity : null,
                'estimated_cost' => (float) $r->estimated_cost,
                'final_cost' => (float) $r->final_cost,
                'status' => Str::headline((string) $r->status),
                'due_date' => $r->due_date,
            ];
            $final += (float) $r->final_cost;
        }

        $keys = $request->columnKeys;
        $totals = in_array('final_cost', $keys, true) ? ['final_cost' => round($final, 2)] : [];

        $section = new ReportSection('repairs', 'Repairs', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = Repair::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('created_at', [$period['from'], $period['to']]);
        }
        if ($status = $request->filter('status')) {
            $q->where('status', $status);
        }

        return $q->orderBy('created_at')->orderBy('id');
    }
}
