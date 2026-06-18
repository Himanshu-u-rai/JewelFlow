<?php

namespace App\Services\Reporting\Reports;

use App\Models\SchemeEnrollment;
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
 * Scheme enrolments — customer savings-scheme participation (paid in, redeemed,
 * installments, maturity). Receivables/liability data export, filterable by
 * status. The total-paid sum is the shop's scheme liability.
 */
class SchemeEnrollmentsDataset extends ReportDatasetService
{
    public const KEY = 'scheme-enrollments';
    public const VERSION = 'scheme-enrollments@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Scheme Enrolments',
            classification: Cls::Receivables,
            columns: [
                Col::mandatory('scheme', 'Scheme', T::String),
                Col::mandatory('customer', 'Customer', T::String),
                Col::optional('start_date', 'Started', T::Date),
                Col::optional('monthly_amount', 'Monthly', T::Money),
                Col::optional('installments_paid', 'Paid', T::Integer),
                Col::optional('total_installments', 'Of', T::Integer),
                Col::mandatory('total_paid', 'Total Paid', T::Money),
                Col::optional('redeemed_amount', 'Redeemed', T::Money),
                Col::optional('maturity_date', 'Maturity', T::Date),
                Col::mandatory('status', 'Status', T::String),
            ],
            profiles: [P::Summary, P::Detailed, P::Raw],
            filters: [
                Filter::for(FK::Status),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $enrolments = $this->query($request)->with(['scheme', 'customer'])->get();

        $rows = [];
        $paid = 0.0;
        foreach ($enrolments as $e) {
            $rows[] = [
                'scheme' => $e->scheme?->name ?? '—',
                'customer' => $e->customer?->name ?? 'Walk-in',
                'start_date' => $e->start_date,
                'monthly_amount' => (float) $e->monthly_amount,
                'installments_paid' => (int) $e->installments_paid,
                'total_installments' => (int) $e->total_installments,
                'total_paid' => (float) $e->total_paid,
                'redeemed_amount' => (float) $e->redeemed_amount,
                'maturity_date' => $e->maturity_date,
                'status' => Str::headline((string) $e->status),
            ];
            $paid += (float) $e->total_paid;
        }

        $keys = $request->columnKeys;
        $totals = in_array('total_paid', $keys, true) ? ['total_paid' => round($paid, 2)] : [];

        $section = new ReportSection('scheme_enrollments', 'Scheme Enrolments', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = SchemeEnrollment::query();

        if ($status = $request->filter('status')) {
            $q->where('status', $status);
        }

        return $q->orderBy('id');
    }
}
