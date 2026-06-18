<?php

namespace App\Services\Reporting\Reports;

use App\Models\InstallmentPlan;
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
 * Installment plans (EMI) — customer EMI plans with paid/remaining and next due
 * date. Receivables data export, filterable by status. Outstanding sum surfaces
 * the shop's EMI receivable.
 */
class InstallmentPlansDataset extends ReportDatasetService
{
    public const KEY = 'installment-plans';
    public const VERSION = 'installment-plans@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'EMI Plans',
            classification: Cls::Receivables,
            columns: [
                Col::mandatory('invoice_number', 'Invoice', T::String),
                Col::mandatory('customer', 'Customer', T::String),
                Col::optional('total_payable', 'Total Payable', T::Money),
                Col::optional('emi_amount', 'EMI', T::Money),
                Col::optional('emis_paid', 'Paid', T::Integer),
                Col::optional('total_emis', 'Of', T::Integer),
                Col::mandatory('remaining_amount', 'Remaining', T::Money),
                Col::optional('next_due_date', 'Next Due', T::Date),
                Col::mandatory('status', 'Status', T::String),
                Col::optional('created_at', 'Started', T::DateTime),
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
        $plans = $this->query($request)->with(['invoice', 'customer'])->get();

        $rows = [];
        $remaining = 0.0;
        foreach ($plans as $p) {
            $rows[] = [
                'invoice_number' => $p->invoice?->invoice_number ?? '—',
                'customer' => $p->customer?->name ?? 'Walk-in',
                'total_payable' => (float) $p->total_payable,
                'emi_amount' => (float) $p->emi_amount,
                'emis_paid' => (int) $p->emis_paid,
                'total_emis' => (int) $p->total_emis,
                'remaining_amount' => (float) $p->remaining_amount,
                'next_due_date' => $p->next_due_date,
                'status' => Str::headline((string) $p->status),
                'created_at' => $p->created_at,
            ];
            $remaining += (float) $p->remaining_amount;
        }

        $keys = $request->columnKeys;
        $totals = in_array('remaining_amount', $keys, true) ? ['remaining_amount' => round($remaining, 2)] : [];

        $section = new ReportSection('emi_plans', 'EMI Plans', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = InstallmentPlan::query();

        if ($status = $request->filter('status')) {
            $q->where('status', $status);
        }

        return $q->orderBy('created_at')->orderBy('id');
    }
}
