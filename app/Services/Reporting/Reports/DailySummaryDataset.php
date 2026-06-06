<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\GstReportingService;
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
use Carbon\CarbonImmutable;

/**
 * Daily (Sales Summary) — one day's headline sales/GST plus the day's metal
 * movement (Accounting, frozen §22). Lightweight by design: the trust chain is
 * already established by the Sales Register, GST Report, Daily Closing and
 * Payment Reconciliation.
 *
 * Wraps two canonical aggregations VERBATIM — it re-derives NOTHING:
 *  - GstReportingService::summary(day) → the Sales Summary (sales/count/GST),
 *    which ties to the Sales Register and GST Report by construction;
 *  - LedgerService::metalMovementDay(date) → the day's fine-weight movement
 *    (preserves the legacy "Daily Gold Movement" content of report.daily).
 */
class DailySummaryDataset extends ReportDatasetService
{
    public const KEY = 'daily-summary';
    public const VERSION = 'daily-summary@1';

    /** Movement type code → owner-friendly label (simple English). */
    private const MOVEMENT_LABELS = [
        'purchase' => 'Purchased',
        'opening' => 'Opening Stock',
        'buyback' => 'Bought from Customer',
        'old_metal_in' => 'Old Gold Taken In',
        'exchange' => 'Exchange',
        'sale' => 'Sold',
        'manufacture' => 'Made (Manufacture)',
        'job_issue' => 'Issued to Karigar',
        'job_return' => 'Returned by Karigar',
        'wastage' => 'Wastage',
        'return_melt_recovery' => 'Melted from Returns',
        'credit_note_melt' => 'Melted from Returns',
        'vault_adjustment' => 'Stock Correction',
        'customer_advance' => 'Customer Gold Deposit',
    ];

    public function __construct(
        private readonly GstReportingService $gst,
        private readonly LedgerService $ledger,
    ) {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Daily Sales Summary',
            classification: Cls::Accounting,
            columns: [
                // Sales Summary section (one row — the day's headline figures)
                Col::mandatory('date', 'Date', T::Date),
                Col::mandatory('bills', 'Bills', T::Integer),
                Col::mandatory('total_sales', 'Total Sales', T::Money),
                Col::mandatory('discount', 'Discount', T::Money),
                Col::mandatory('taxable', 'Taxable Value', T::Money),
                Col::mandatory('cgst', 'CGST', T::Money),
                Col::mandatory('sgst', 'SGST', T::Money),
                Col::mandatory('igst', 'IGST', T::Money),
                Col::mandatory('gst', 'GST', T::Money),
                // Metal Movement section
                Col::mandatory('movement', 'Movement', T::String),
                Col::mandatory('grams', 'Fine Weight (g)', T::Weight),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca],
            filters: [
                Filter::for(FK::AsOf, true),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $date = $this->date($request);
        $day = ReportPeriod::day($date);
        $summary = $this->gst->summary($request->shopId, $day);

        // --- Sales Summary (sales/count/GST, all from the canonical service) ---
        $salesCols = $this->cols($def, ['date', 'bills', 'total_sales', 'discount', 'taxable', 'cgst', 'sgst', 'igst', 'gst']);
        $salesRows = [[
            'date' => $date,
            'bills' => $summary->invoiceCount,
            'total_sales' => $summary->totalSales,
            'discount' => $summary->totalDiscount,
            'taxable' => $summary->taxableAmount,
            'cgst' => $summary->cgstCollected,
            'sgst' => $summary->sgstCollected,
            'igst' => $summary->igstCollected,
            'gst' => $summary->gstCollected,
        ]];
        $sales = new ReportSection('sales', 'Sales Summary', $salesCols, $salesRows);

        // --- Metal Movement for the day (preserves legacy report.daily) ---
        $metalCols = $this->cols($def, ['movement', 'grams']);
        $metalRows = [];
        foreach ($this->ledger->metalMovementDay($request->shopId, $date) as $m) {
            $metalRows[] = [
                'movement' => self::MOVEMENT_LABELS[$m->type] ?? ucwords(str_replace('_', ' ', (string) $m->type)),
                'grams' => (float) $m->total,
            ];
        }
        $metal = new ReportSection('metal', 'Metal Movement', $metalCols, $metalRows);

        return new ReportDataset([$sales, $metal], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return 1 + $this->ledger->metalMovementDay($request->shopId, $this->date($request))->count();
    }

    private function date(ReportRequest $request): string
    {
        $period = $request->filter('period', []);
        $date = $period['to'] ?? $period['from'] ?? null;

        return $date ? $date->toDateString() : CarbonImmutable::now()->toDateString();
    }

    /**
     * @param  string[]  $keys
     * @return \App\Services\Reporting\Definition\ColumnDefinition[]
     */
    private function cols(ReportDefinition $def, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $column = $def->column($key);
            if ($column !== null) {
                $out[] = $column;
            }
        }

        return $out;
    }
}
