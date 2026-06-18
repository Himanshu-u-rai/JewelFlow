<?php

namespace App\Services\Reporting\Reports;

use App\Models\CustomerGoldTransaction;
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
 * Old gold (customer gold) — the append-only ledger of customers' own gold
 * received/applied (fine-weight movements), with the linked invoice. Accounting
 * data export, period filtered on created_at. The net fine sum is the customers'
 * gold balance the shop holds.
 */
class OldGoldDataset extends ReportDatasetService
{
    public const KEY = 'old-gold';
    public const VERSION = 'old-gold@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Old Gold (Customer Gold)',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('created_at', 'Date', T::DateTime),
                Col::mandatory('customer', 'Customer', T::String),
                Col::mandatory('type', 'Type', T::String),
                Col::optional('gross_weight', 'Gross Wt (g)', T::Weight),
                Col::optional('purity', 'Purity', T::Decimal),
                Col::mandatory('fine_gold', 'Fine Gold (g)', T::Weight),
                Col::optional('invoice_number', 'Invoice', T::String),
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
        $txns = $this->query($request)->with(['customer', 'invoice'])->get();

        $rows = [];
        $net = 0.0;
        foreach ($txns as $t) {
            $rows[] = [
                'created_at' => $t->created_at,
                'customer' => $t->customer?->name ?? 'Walk-in',
                'type' => Str::headline((string) $t->type),
                'gross_weight' => (float) $t->gross_weight,
                'purity' => $t->purity !== null ? (float) $t->purity : null,
                'fine_gold' => (float) $t->fine_gold,
                'invoice_number' => $t->invoice?->invoice_number ?? '—',
            ];
            $net += (float) $t->fine_gold;
        }

        $keys = $request->columnKeys;
        $totals = in_array('fine_gold', $keys, true) ? ['fine_gold' => round($net, 4)] : [];

        $section = new ReportSection('old_gold', 'Old Gold (Customer Gold)', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = CustomerGoldTransaction::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('created_at', [$period['from'], $period['to']]);
        }

        return $q->orderBy('created_at')->orderBy('id');
    }
}
