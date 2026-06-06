<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\ReportPeriod;
use App\Reporting\SalesService;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition;
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
 * Payment Reconciliation — the financial-integrity report (Accounting, frozen §22).
 *
 * Per invoice: billed (invoices.total) vs collected (Σ invoice_payments), and the
 * outstanding = billed − collected. Wraps the canonical
 * SalesService::paymentReconciliation() VERBATIM — the report never re-derives
 * totals or variances. Reconciles BY CONSTRUCTION: the Summary's Invoice Total /
 * Collected / Outstanding are the service aggregates, and each detail row's
 * outstanding is the service's per-invoice pending. Customer is a sensitive
 * (permission-gated) column.
 */
class PaymentReconciliationDataset extends ReportDatasetService
{
    public const KEY = 'payment-reconciliation';
    public const VERSION = 'payment-reconciliation@1';

    /** Service status → owner-friendly label (simple English). */
    private const STATUS_LABELS = [
        'paid' => 'Paid',
        'partial' => 'Part Paid',
        'unpaid' => 'Unpaid',
        'over_collected' => 'Over Collected',
    ];

    public function __construct(private readonly SalesService $sales)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Payment Reconciliation',
            classification: Cls::Accounting,
            columns: [
                // Summary section
                Col::mandatory('particular', 'Particular', T::String),
                Col::mandatory('value', 'Amount', T::Money),
                // Detail section (per invoice)
                Col::mandatory('invoice_no', 'Bill No.', T::String),
                Col::mandatory('date', 'Date', T::Date),
                Col::mandatory('total', 'Bill Amount', T::Money),
                Col::mandatory('collected', 'Received', T::Money),
                Col::mandatory('outstanding', 'Outstanding', T::Money),
                Col::mandatory('status', 'Status', T::String),
                Col::sensitive('customer', 'Customer', T::String),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca],
            filters: [
                Filter::for(FK::Period, true),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        $data = $this->sales->paymentReconciliation($request->shopId, $this->period($request));

        // --- Summary (billed − collected = outstanding, all from the service) ---
        $summaryCols = $this->cols($def, $this->keep(['particular', 'value'], $keys));
        $summaryRows = [
            ['particular' => 'Bill Amount', 'value' => $data->invoiceTotal],
            ['particular' => 'Received', 'value' => $data->collected],
            ['particular' => 'Outstanding', 'value' => round($data->invoiceTotal - $data->collected, 2)],
        ];
        $summary = new ReportSection('summary', 'Summary', $summaryCols, $summaryRows);

        // --- Per-invoice detail -----------------------------------------
        $detailCols = $this->cols($def, $this->keep(
            ['invoice_no', 'date', 'customer', 'total', 'collected', 'outstanding', 'status'],
            $keys
        ));
        $detailRows = [];
        foreach ($data->rows as $r) {
            $detailRows[] = [
                'invoice_no' => $r->invoice_number,
                'date' => $r->doc_date,
                'customer' => $r->customer_name,
                'total' => (float) $r->total,
                'collected' => (float) $r->collected,
                'outstanding' => (float) $r->pending,
                'status' => self::STATUS_LABELS[$r->status] ?? $r->status,
            ];
        }
        $detail = new ReportSection('invoices', 'Invoices', $detailCols, $detailRows);

        return new ReportDataset([$summary, $detail], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->sales->paymentReconciliation($request->shopId, $this->period($request))->invoiceCount;
    }

    private function period(ReportRequest $request): ReportPeriod
    {
        $period = $request->filter('period', []);
        $from = $period['from'] ?? null;
        $to = $period['to'] ?? null;

        return ReportPeriod::range($from ? $from->toDateString() : null, $to ? $to->toDateString() : null);
    }

    /**
     * @param  string[]  $candidate
     * @param  string[]  $allowed
     * @return string[]
     */
    private function keep(array $candidate, array $allowed): array
    {
        return array_values(array_filter($candidate, static fn ($k) => in_array($k, $allowed, true)));
    }

    /**
     * @param  string[]  $keys
     * @return ColumnDefinition[]
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
