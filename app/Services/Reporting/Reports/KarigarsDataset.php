<?php

namespace App\Services\Reporting\Reports;

use App\Models\Karigar;
use App\Models\KarigarInvoice;
use App\Models\KarigarPayment;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;

/**
 * Karigars — the worker master with money owed. Outstanding is derived per
 * karigar as (opening_balance + Σ invoiced after-tax − Σ payments). Operational
 * data export. Contact details (mobile / email / address / GST / PAN) are
 * SENSITIVE (PII) so they're excluded unless the user holds
 * reports.export_sensitive and opts in.
 */
class KarigarsDataset extends ReportDatasetService
{
    public const KEY = 'karigars';
    public const VERSION = 'karigars@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Karigars',
            classification: Cls::Operational,
            columns: [
                Col::mandatory('name', 'Name', T::String),
                Col::optional('shop_name', 'Workshop', T::String),
                Col::optional('contact_person', 'Contact Person', T::String),
                Col::sensitive('mobile', 'Mobile', T::String),
                Col::sensitive('email', 'Email', T::String),
                Col::sensitive('address', 'Address', T::String),
                Col::sensitive('gst_number', 'GSTIN', T::String),
                Col::sensitive('pan_number', 'PAN', T::String),
                Col::optional('opening_balance', 'Opening Balance', T::Money),
                Col::optional('invoiced_total', 'Total Invoiced', T::Money),
                Col::optional('paid_total', 'Total Paid', T::Money),
                Col::mandatory('outstanding', 'Outstanding', T::Money),
                Col::optional('is_active', 'Active', T::Boolean),
            ],
            profiles: [P::Summary, P::Detailed, P::Raw],
            filters: [],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $karigars = Karigar::query()->orderBy('name')->orderBy('id')->get();

        $invoiced = KarigarInvoice::query()
            ->selectRaw('karigar_id, COALESCE(SUM(total_after_tax), 0) as invoiced_total')
            ->groupBy('karigar_id')->get()->keyBy('karigar_id');

        $paid = KarigarPayment::query()
            ->selectRaw('karigar_id, COALESCE(SUM(amount), 0) as paid_total')
            ->groupBy('karigar_id')->get()->keyBy('karigar_id');

        $rows = [];
        $outstandingTotal = 0.0;
        foreach ($karigars as $k) {
            $opening = (float) ($k->opening_balance ?? 0);
            $inv = (float) ($invoiced->get($k->id)->invoiced_total ?? 0);
            $pay = (float) ($paid->get($k->id)->paid_total ?? 0);
            $outstanding = round($opening + $inv - $pay, 2);
            $outstandingTotal += $outstanding;

            $rows[] = [
                'name' => $k->name,
                'shop_name' => $k->shop_name,
                'contact_person' => $k->contact_person,
                'mobile' => $k->mobile,
                'email' => $k->email,
                'address' => $k->address,
                'gst_number' => $k->gst_number,
                'pan_number' => $k->pan_number,
                'opening_balance' => $opening,
                'invoiced_total' => $inv,
                'paid_total' => $pay,
                'outstanding' => $outstanding,
                'is_active' => (bool) $k->is_active,
            ];
        }

        $keys = $request->columnKeys;
        $totals = in_array('outstanding', $keys, true) ? ['outstanding' => round($outstandingTotal, 2)] : [];

        $section = new ReportSection('karigars', 'Karigars', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return Karigar::query()->count();
    }
}
