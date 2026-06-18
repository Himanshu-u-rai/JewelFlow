<?php

namespace App\Services\Reporting\Reports;

use App\Models\JobOrder;
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
 * Job Orders — gold issued to karigars for work (issued vs returned fine weight,
 * wastage, status). Operational data export, period filtered on issue_date,
 * filterable by karigar.
 */
class JobOrdersDataset extends ReportDatasetService
{
    public const KEY = 'job-orders';
    public const VERSION = 'job-orders@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Job Orders',
            classification: Cls::Operational,
            columns: [
                Col::mandatory('job_order_number', 'Job No.', T::String),
                Col::mandatory('issue_date', 'Issued', T::Date),
                Col::mandatory('karigar', 'Karigar', T::String),
                Col::optional('job_type', 'Type', T::String),
                Col::optional('metal_type', 'Metal', T::String),
                Col::optional('purity', 'Purity', T::Decimal),
                Col::mandatory('issued_fine_weight', 'Issued Fine (g)', T::Weight),
                Col::optional('returned_fine_weight', 'Returned Fine (g)', T::Weight),
                Col::optional('actual_wastage_fine', 'Wastage Fine (g)', T::Weight),
                Col::mandatory('status', 'Status', T::String),
                Col::optional('expected_return_date', 'Expected Back', T::Date),
                Col::optional('completed_at', 'Completed', T::DateTime),
            ],
            profiles: [P::Summary, P::Detailed, P::Raw],
            filters: [
                Filter::for(FK::Period),
                Filter::for(FK::Karigar),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $orders = $this->query($request)->with('karigar')->get();

        $rows = [];
        $issued = 0.0;
        foreach ($orders as $jo) {
            $rows[] = [
                'job_order_number' => $jo->job_order_number,
                'issue_date' => $jo->issue_date,
                'karigar' => $jo->karigar?->name ?? '—',
                'job_type' => Str::headline((string) $jo->job_type),
                'metal_type' => $jo->metal_type,
                'purity' => $jo->purity !== null ? (float) $jo->purity : null,
                'issued_fine_weight' => (float) $jo->issued_fine_weight,
                'returned_fine_weight' => (float) $jo->returned_fine_weight,
                'actual_wastage_fine' => (float) $jo->actual_wastage_fine,
                'status' => Str::headline((string) $jo->status),
                'expected_return_date' => $jo->expected_return_date,
                'completed_at' => $jo->completed_at,
            ];
            $issued += (float) $jo->issued_fine_weight;
        }

        $keys = $request->columnKeys;
        $totals = in_array('issued_fine_weight', $keys, true) ? ['issued_fine_weight' => round($issued, 3)] : [];

        $section = new ReportSection('job_orders', 'Job Orders', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = JobOrder::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('issue_date', [$period['from'], $period['to']]);
        }
        if ($karigar = $request->filter('karigar')) {
            $q->where('karigar_id', $karigar);
        }

        return $q->orderBy('issue_date')->orderBy('id');
    }
}
