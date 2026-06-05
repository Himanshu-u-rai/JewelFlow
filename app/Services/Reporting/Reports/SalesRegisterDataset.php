<?php

namespace App\Services\Reporting\Reports;

use App\Models\Invoice;
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
 * Sales / Invoice Register — the Phase 1 canonical report (Addendum C §27).
 *
 * Reconciliation contract: this report's taxable/CGST/SGST/IGST/total grand
 * totals MUST equal the GST report and the finalized `invoices` table for the
 * same period (plan §1.7). It therefore reuses the single source of truth for
 * "what is a sale" (Invoice::accountingBetween + status=finalized, i.e. the
 * salesIn scope) and the identical CGST/SGST/IGST COALESCE the
 * GstReportingService uses — so screen, exports, GST report, and the raw table
 * can never disagree.
 */
class SalesRegisterDataset extends ReportDatasetService
{
    public const KEY = 'sales-register';
    public const VERSION = 'sales-register@1';

    /** Numeric columns that carry a grand total. */
    private const TOTALLED = ['taxable', 'cgst', 'sgst', 'igst', 'total_gst', 'total', 'discount', 'round_off', 'cost', 'margin', 'item_count', 'net_weight'];

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Sales / Invoice Register',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('invoice_no', 'Invoice No', T::String),
                Col::mandatory('date', 'Date', T::Date),
                Col::mandatory('customer', 'Customer', T::String),
                Col::mandatory('taxable', 'Taxable Value', T::Money),
                Col::mandatory('cgst', 'CGST', T::Money),
                Col::mandatory('sgst', 'SGST', T::Money),
                Col::mandatory('igst', 'IGST', T::Money),
                Col::mandatory('total_gst', 'Total GST', T::Money),
                Col::mandatory('total', 'Invoice Total', T::Money),
                Col::mandatory('status', 'Status', T::String),
                Col::optional('hsn', 'HSN', T::String),
                Col::optional('item_count', 'Items', T::Integer),
                Col::optional('net_weight', 'Net Wt (g)', T::Weight),
                Col::optional('discount', 'Discount', T::Money),
                Col::optional('round_off', 'Round Off', T::Money),
                Col::optional('place_of_supply', 'Place of Supply', T::String),
                Col::optional('buyer_gstin', 'Buyer GSTIN', T::String),
                Col::optional('payment_mode', 'Payment Mode', T::String),
                Col::sensitive('customer_mobile', 'Customer Mobile', T::String),
                Col::sensitive('customer_address', 'Customer Address', T::String),
                Col::sensitive('operator', 'Operator', T::String),
                Col::sensitive('cost', 'Cost', T::Money),
                Col::sensitive('margin', 'Margin', T::Money),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca, P::CaStandard, P::Raw],
            filters: [
                Filter::for(FK::Period, true),
                Filter::for(FK::Operator),
                Filter::for(FK::Customer),
                Filter::for(FK::MetalType),
                Filter::for(FK::Status),
                Filter::for(FK::PaymentMode),
                Filter::for(FK::Branch), // reserved hook — never rendered (frozen §3.2)
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $keys = $request->columnKeys;
        $needItems = (bool) array_intersect($keys, ['hsn', 'item_count', 'net_weight', 'cost', 'margin']);
        $needPayments = in_array('payment_mode', $keys, true) || $request->filter('payment_mode') !== null;
        $needCost = (bool) array_intersect($keys, ['cost', 'margin']);

        $with = ['customer', 'user'];
        if ($needItems) {
            $with[] = $needCost ? 'items.item' : 'items';
        }
        if ($needPayments) {
            $with[] = 'payments';
        }

        $invoices = $this->query($request)->with($with)->get();

        $rows = [];
        $totals = array_fill_keys(array_intersect(self::TOTALLED, $keys), 0.0);

        foreach ($invoices as $invoice) {
            $row = $this->row($invoice, $keys, $needCost);
            $rows[] = $row;
            foreach ($totals as $key => $_) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
        }
        $totals = array_map(static fn ($v) => round($v, 2), $totals);

        $section = new ReportSection('sales', 'Sales / Invoice Register', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    /** The reconciling base query: accounting-dated + status (default finalized). */
    private function query(ReportRequest $request)
    {
        $period = $this->period($request);
        $status = (string) ($request->filter('status') ?: Invoice::STATUS_FINALIZED);

        $q = Invoice::query()->accountingBetween($period)->where('status', $status);

        if ($operator = $request->filter('operator')) {
            $q->where('user_id', $operator);
        }
        if ($customer = $request->filter('customer')) {
            $q->where('customer_id', $customer);
        }
        if ($metal = $request->filter('metal_type')) {
            $q->whereHas('items', fn ($i) => $i->where('metal_type', $metal));
        }
        if ($paymentMode = $request->filter('payment_mode')) {
            $q->whereHas('payments', fn ($p) => $p->where('mode', $paymentMode));
        }

        return $q->orderByRaw('COALESCE(finalized_at, created_at)')->orderBy('id');
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

    /**
     * One invoice → one row of native typed values. CGST/SGST/IGST use the exact
     * COALESCE the GstReportingService uses, so per-row (and thus grand-total)
     * figures reconcile by construction (plan §1.7).
     *
     * @param  string[]  $keys
     * @return array<string, mixed>
     */
    private function row(Invoice $invoice, array $keys, bool $needCost): array
    {
        $gst = (float) $invoice->gst;
        $cgst = $invoice->cgst_amount !== null ? (float) $invoice->cgst_amount : round($gst / 2, 2);
        $sgst = $invoice->sgst_amount !== null ? (float) $invoice->sgst_amount : round($gst - round($gst / 2, 2), 2);
        $igst = $invoice->igst_amount !== null ? (float) $invoice->igst_amount : 0.0;
        $cost = $needCost ? (float) $invoice->items->sum(fn ($ii) => (float) ($ii->item->cost_price ?? 0)) : 0.0;

        $all = [
            'invoice_no' => $invoice->invoice_number,
            'date' => $invoice->finalized_at ?? $invoice->created_at,
            'customer' => $invoice->customer?->full_name ?: 'Walk-in',
            'taxable' => (float) $invoice->subtotal,
            'cgst' => $cgst,
            'sgst' => $sgst,
            'igst' => $igst,
            'total_gst' => $gst,
            'total' => (float) $invoice->total,
            'status' => ucfirst((string) $invoice->status),
            'hsn' => $invoice->items->pluck('hsn_code')->filter()->unique()->values()->implode(', '),
            'item_count' => $invoice->items->count(),
            'net_weight' => (float) $invoice->items->sum('weight'),
            'discount' => (float) $invoice->discount,
            'round_off' => (float) $invoice->round_off,
            'place_of_supply' => $invoice->place_of_supply_state_code,
            'buyer_gstin' => $invoice->buyer_gstin,
            'payment_mode' => $invoice->payments->pluck('mode')->filter()->unique()->values()->implode(', ') ?: null,
            'customer_mobile' => $invoice->customer?->mobile,
            'customer_address' => $invoice->customer?->address,
            'operator' => $invoice->user?->name ?? 'System',
            'cost' => $cost,
            'margin' => round((float) $invoice->subtotal - $cost, 2),
        ];

        // Emit only the resolved columns (the gate already ran).
        return array_intersect_key($all, array_flip($keys));
    }
}
