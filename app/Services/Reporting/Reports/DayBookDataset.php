<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
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

/**
 * Day Book — the chronological accounting event stream for a period
 * (ACCOUNTING, not compliance: profiles are flexible, formal PDF included).
 *
 * Wraps LedgerService::dayBook() verbatim. Each event becomes a debit/credit
 * line with a running balance; the debit/credit grand totals are summed from
 * the rows and the running_balance total is the last running balance — so the
 * book reconciles to the service's own event stream by construction.
 */
class DayBookDataset extends ReportDatasetService
{
    public const KEY = 'day-book';
    public const VERSION = 'day-book@1';

    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Day Book',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('datetime', 'Date & Time', T::DateTime),
                Col::mandatory('type', 'Type', T::String),
                Col::mandatory('reference', 'Reference', T::String),
                Col::mandatory('party', 'Party', T::String),
                Col::mandatory('debit', 'Debit', T::Money),
                Col::mandatory('credit', 'Credit', T::Money),
                Col::mandatory('running_balance', 'Running Balance', T::Money),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard],
            filters: [
                Filter::for(FK::Period, true),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $data = $this->ledger->dayBook($request->shopId, $this->period($request));

        $rows = [];
        $running = 0.0;
        $debitTotal = 0.0;
        $creditTotal = 0.0;

        foreach ($data->events as $event) {
            $amount = (float) $event->amount;
            $isCredit = $event->direction === 'credit';
            $credit = $isCredit ? $amount : 0.0;
            $debit = $isCredit ? 0.0 : $amount;
            $running = round($running + $credit - $debit, 2);
            $debitTotal += $debit;
            $creditTotal += $credit;

            $rows[] = [
                'datetime' => $event->occurred_at,
                'type' => (string) $event->event_type,
                'reference' => $event->reference,
                'party' => $event->party,
                'debit' => $debit,
                'credit' => $credit,
                'running_balance' => $running,
            ];
        }

        $totals = [
            'debit' => round($debitTotal, 2),
            'credit' => round($creditTotal, 2),
            'running_balance' => $rows === [] ? null : $running,
        ];

        $section = new ReportSection('day_book', 'Day Book', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->ledger->dayBook($request->shopId, $this->period($request))->eventCount;
    }

    private function period(ReportRequest $request): ReportPeriod
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return ReportPeriod::range(
            $from ? $from->toDateString() : null,
            $to ? $to->toDateString() : null,
        );
    }
}
