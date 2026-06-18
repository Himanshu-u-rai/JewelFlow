<?php

namespace App\Services\Reporting\Reports;

use App\Models\LoyaltyTransaction;
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
 * Loyalty points — the customer points ledger (earn / redeem), with the balance
 * after each movement and the linked invoice. Operational data export, period
 * filtered on created_at.
 */
class LoyaltyDataset extends ReportDatasetService
{
    public const KEY = 'loyalty';
    public const VERSION = 'loyalty@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Loyalty Points',
            classification: Cls::Operational,
            columns: [
                Col::mandatory('created_at', 'Date', T::DateTime),
                Col::mandatory('customer', 'Customer', T::String),
                Col::mandatory('type', 'Type', T::String),
                Col::mandatory('points', 'Points', T::Integer),
                Col::optional('balance_after', 'Balance After', T::Integer),
                Col::optional('invoice_number', 'Invoice', T::String),
                Col::optional('description', 'Description', T::String),
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
        foreach ($txns as $t) {
            $rows[] = [
                'created_at' => $t->created_at,
                'customer' => $t->customer?->name ?? 'Walk-in',
                'type' => Str::headline((string) $t->type),
                'points' => (int) $t->points,
                'balance_after' => (int) $t->balance_after,
                'invoice_number' => $t->invoice?->invoice_number ?? '—',
                'description' => $t->description,
            ];
        }

        $section = new ReportSection('loyalty', 'Loyalty Points', $request->columns(), $rows, []);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = LoyaltyTransaction::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('created_at', [$period['from'], $period['to']]);
        }

        return $q->orderBy('created_at')->orderBy('id');
    }
}
