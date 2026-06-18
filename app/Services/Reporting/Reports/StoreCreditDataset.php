<?php

namespace App\Services\Reporting\Reports;

use App\Models\StoreCreditMovement;
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
 * Store credit — the append-only customer store-credit ledger (signed amounts:
 * +credit issued, −credit consumed). Accounting data export, period filtered on
 * created_at. The net sum is the shop's outstanding store-credit liability.
 */
class StoreCreditDataset extends ReportDatasetService
{
    public const KEY = 'store-credit';
    public const VERSION = 'store-credit@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Store Credit',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('created_at', 'Date', T::DateTime),
                Col::mandatory('customer', 'Customer', T::String),
                Col::mandatory('amount', 'Amount', T::Money),
                Col::mandatory('source_type', 'Source', T::String),
                Col::optional('expires_at', 'Expires', T::DateTime),
                Col::optional('notes', 'Notes', T::String),
            ],
            profiles: [P::Detailed, P::Raw],
            filters: [
                Filter::for(FK::Period),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $movements = $this->query($request)->with('customer')->get();

        $rows = [];
        $net = 0.0;
        foreach ($movements as $m) {
            $rows[] = [
                'created_at' => $m->created_at,
                'customer' => $m->customer?->name ?? 'Walk-in',
                'amount' => (float) $m->amount,
                'source_type' => Str::headline((string) $m->source_type),
                'expires_at' => $m->expires_at,
                'notes' => $m->notes,
            ];
            $net += (float) $m->amount;
        }

        $keys = $request->columnKeys;
        $totals = in_array('amount', $keys, true) ? ['amount' => round($net, 2)] : [];

        $section = new ReportSection('store_credit', 'Store Credit', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = StoreCreditMovement::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('created_at', [$period['from'], $period['to']]);
        }

        return $q->orderBy('created_at')->orderBy('id');
    }
}
