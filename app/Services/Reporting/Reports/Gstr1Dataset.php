<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\ReportPeriod;
use App\Reporting\TaxService;
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
 * GSTR-1 — the outward-supply filing layout for a period (COMPLIANCE).
 *
 * Four sections (B2B, B2CS, HSN, Credit Notes), each with its own column subset.
 * Wraps TaxService::gstr1() verbatim: the B2B + B2CS taxable/cgst/sgst/igst/gst
 * sums equal the DTO scalars (taxable, cgst, sgst, igst, totalGst) and the HSN
 * and CN sections sum their own rows — all by construction, no re-query.
 *
 * Rigid (frozen §9): single Fixed profile, every column mandatory. The
 * definition catalogue is the UNION of all four section column subsets.
 */
class Gstr1Dataset extends ReportDatasetService
{
    public const KEY = 'gstr1';
    public const VERSION = 'gstr1@1';

    public function __construct(private readonly TaxService $tax)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'GSTR-1',
            classification: Cls::Compliance,
            columns: [
                // B2B
                Col::mandatory('invoice_no', 'Invoice No', T::String),
                Col::mandatory('date', 'Date', T::Date),
                Col::mandatory('buyer_gstin', 'Buyer GSTIN', T::String),
                Col::mandatory('place_of_supply', 'Place of Supply', T::String),
                Col::mandatory('rate', 'GST Rate', T::String),
                Col::mandatory('taxable', 'Taxable Value', T::Money),
                Col::mandatory('cgst', 'CGST', T::Money),
                Col::mandatory('sgst', 'SGST', T::Money),
                Col::mandatory('igst', 'IGST', T::Money),
                Col::mandatory('total', 'Total', T::Money),
                // B2CS adds (rate, place_of_supply, taxable, cgst, sgst, igst, …)
                Col::mandatory('total_gst', 'Total GST', T::Money),
                Col::mandatory('count', 'Invoices', T::Integer),
                // HSN
                Col::mandatory('hsn_code', 'HSN', T::String),
                Col::mandatory('lines', 'Lines', T::Integer),
                // Credit notes
                Col::mandatory('cn_no', 'Credit Note No', T::String),
                Col::mandatory('original_invoice', 'Original Invoice', T::String),
            ],
            profiles: [P::Fixed],
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
        $data = $this->tax->gstr1($request->shopId, $this->period($request));

        // --- B2B ---------------------------------------------------------
        $b2bKeys = ['invoice_no', 'date', 'buyer_gstin', 'place_of_supply', 'rate', 'taxable', 'cgst', 'sgst', 'igst', 'total'];
        $b2bRows = [];
        foreach ($data->b2b as $r) {
            $b2bRows[] = [
                'invoice_no' => (string) $r->invoice_number,
                'date' => $r->doc_date,
                'buyer_gstin' => $r->buyer_gstin,
                'place_of_supply' => $r->place_of_supply_state_code,
                'rate' => (string) $r->gst_rate . '%',
                'taxable' => (float) $r->taxable,
                'cgst' => (float) $r->cgst,
                'sgst' => (float) $r->sgst,
                'igst' => (float) $r->igst,
                'total' => (float) $r->total,
            ];
        }
        $b2b = new ReportSection('b2b', 'B2B Invoices', $this->cols($def, $b2bKeys), $b2bRows, $this->sumNumeric($b2bRows, ['taxable', 'cgst', 'sgst', 'igst', 'total']));

        // --- B2CS --------------------------------------------------------
        $b2csKeys = ['rate', 'place_of_supply', 'taxable', 'cgst', 'sgst', 'igst', 'total_gst', 'count'];
        $b2csRows = [];
        foreach ($data->b2cs as $r) {
            $b2csRows[] = [
                'rate' => (string) $r->gst_rate . '%',
                'place_of_supply' => $r->place_of_supply_state_code,
                'taxable' => (float) $r->taxable,
                'cgst' => (float) $r->cgst,
                'sgst' => (float) $r->sgst,
                'igst' => (float) $r->igst,
                'total_gst' => (float) $r->gst,
                'count' => (int) $r->count,
            ];
        }
        $b2cs = new ReportSection('b2cs', 'B2C (Small)', $this->cols($def, $b2csKeys), $b2csRows, $this->sumNumeric($b2csRows, ['taxable', 'cgst', 'sgst', 'igst', 'total_gst', 'count']));

        // --- HSN ---------------------------------------------------------
        $hsnKeys = ['hsn_code', 'taxable', 'total_gst', 'lines'];
        $hsnRows = [];
        foreach ($data->hsnSummary as $r) {
            $hsnRows[] = [
                'hsn_code' => (string) $r->hsn_code,
                'taxable' => (float) $r->taxable,
                'total_gst' => (float) $r->gst,
                'lines' => (int) $r->lines,
            ];
        }
        $hsn = new ReportSection('hsn', 'HSN Summary', $this->cols($def, $hsnKeys), $hsnRows, $this->sumNumeric($hsnRows, ['taxable', 'total_gst', 'lines']));

        // --- Credit Notes ------------------------------------------------
        $cnKeys = ['cn_no', 'date', 'original_invoice', 'taxable', 'total_gst', 'total'];
        $cnRows = [];
        foreach ($data->creditNotes as $r) {
            $cnRows[] = [
                'cn_no' => (string) $r->credit_note_number,
                'date' => $r->issued_at,
                'original_invoice' => $r->original_invoice_number,
                'taxable' => (float) $r->cn_subtotal,
                'total_gst' => (float) $r->cn_gst,
                'total' => (float) $r->cn_total,
            ];
        }
        $cn = new ReportSection('credit_notes', 'Credit Notes (Returns)', $this->cols($def, $cnKeys), $cnRows, $this->sumNumeric($cnRows, ['taxable', 'total_gst', 'total']));

        return new ReportDataset([$b2b, $b2cs, $hsn, $cn], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        $data = $this->tax->gstr1($request->shopId, $this->period($request));

        return $data->b2b->count() + $data->b2cs->count() + $data->hsnSummary->count() + $data->creditNotes->count();
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  string[]  $keys
     * @return array<string, float|int>
     */
    private function sumNumeric(array $rows, array $keys): array
    {
        $totals = array_fill_keys($keys, 0.0);
        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $totals[$key] += (float) ($row[$key] ?? 0);
            }
        }

        return array_map(static fn ($v) => round($v, 2), $totals);
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
