<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\ClosingService;
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
use Carbon\CarbonImmutable;

/**
 * Daily Closing — the cross-phase trust report (Accounting, frozen §22). For one
 * date it presents finalized sales + GST and cash movement, wrapping the
 * canonical ClosingService (which aggregates GstReportingService +
 * LedgerService::cashFlow). It re-derives NOTHING: sales/GST tie to the GST
 * Report and the Sales Register, and cash ties to the Cash Flow report, all by
 * construction. Gated by `reports.daily_closing`.
 */
class DailyClosingDataset extends ReportDatasetService
{
    public const KEY = 'daily-closing';
    public const VERSION = 'daily-closing@1';

    public function __construct(private readonly ClosingService $closing)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Daily Closing',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('metric', 'Particular', T::String),
                Col::mandatory('amount', 'Amount', T::Money),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca],
            filters: [
                Filter::for(FK::AsOf, true),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default()->withView('reports.daily_closing'),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $data = $this->closing->dailyClosing($request->shopId, $this->date($request));

        $salesTax = new ReportSection('sales_tax', 'Sales & Tax', $request->columns(), [
            ['metric' => 'Total Sales', 'amount' => $data->totalSales],
            ['metric' => 'Discount', 'amount' => $data->discount],
            ['metric' => 'Taxable Value', 'amount' => $data->taxable],
            ['metric' => 'CGST', 'amount' => $data->cgst],
            ['metric' => 'SGST', 'amount' => $data->sgst],
            ['metric' => 'IGST', 'amount' => $data->igst],
            ['metric' => 'GST Collected', 'amount' => $data->gstCollected],
        ]);

        $cash = new ReportSection('cash', 'Cash', $request->columns(), [
            ['metric' => 'Opening Balance', 'amount' => $data->cashOpening],
            ['metric' => 'Cash In', 'amount' => $data->cashIn],
            ['metric' => 'Cash Out', 'amount' => $data->cashOut],
            ['metric' => 'Closing Balance', 'amount' => $data->cashClosing],
        ]);

        return new ReportDataset([$salesTax, $cash], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return 11; // fixed summary — sales/tax (7) + cash (4)
    }

    private function date(ReportRequest $request): string
    {
        $period = $request->filter('period', []);
        $date = $period['to'] ?? $period['from'] ?? null;

        return $date ? $date->toDateString() : CarbonImmutable::now()->toDateString();
    }
}
