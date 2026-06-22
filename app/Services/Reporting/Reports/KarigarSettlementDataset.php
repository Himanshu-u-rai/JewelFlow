<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\KarigarService;
use App\Services\Reporting\Dataset\ReportDataset;
use App\Services\Reporting\Dataset\ReportDatasetService;
use App\Services\Reporting\Dataset\ReportMeta;
use App\Services\Reporting\Dataset\ReportRequest;
use App\Services\Reporting\Dataset\ReportSection;
use App\Services\Reporting\Definition\ColumnDefinition;
use App\Services\Reporting\Definition\ColumnDefinition as Col;
use App\Services\Reporting\Definition\ColumnType as T;
use App\Services\Reporting\Definition\ExportFormat as F;
use App\Services\Reporting\Definition\ReportClassification as Cls;
use App\Services\Reporting\Definition\ReportDefinition;
use App\Services\Reporting\Definition\ReportPermissions as Perm;
use App\Services\Reporting\Definition\ReportProfile as P;

/**
 * Karigar Settlement — per-karigar material accountability (issued/received/
 * wastage/outstanding fine grams) and money (invoiced/paid/payable)
 * (Operational; GAP 2 tail). Wraps KarigarService::settlement() VERBATIM.
 * Point-in-time, so no period filter. Reconciles BY CONSTRUCTION: Σ outstanding
 * fine = totalOutstandingFine; Σ payable = totalOutstandingPayable.
 */
class KarigarSettlementDataset extends ReportDatasetService
{
    public const KEY = 'karigar-settlement';
    public const VERSION = 'karigar-settlement@1';

    public function __construct(private readonly KarigarService $karigar)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Karigar Settlement',
            classification: Cls::Operational,
            columns: [
                Col::mandatory('karigar', 'Karigar', T::String),
                Col::optional('open_jobs', 'Open Jobs', T::Integer),
                Col::mandatory('issued', 'Issued (g)', T::Weight),
                Col::mandatory('received', 'Received (g)', T::Weight),
                Col::optional('wastage', 'Wastage (g)', T::Weight),
                Col::mandatory('outstanding', 'Outstanding (g)', T::Weight),
                Col::optional('invoiced', 'Invoiced', T::Money),
                Col::optional('paid', 'Paid', T::Money),
                Col::mandatory('payable', 'Payable', T::Money),
            ],
            profiles: [P::Summary, P::Detailed],
            filters: [],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $data = $this->karigar->settlement($request->shopId);

        $cols = $this->cols($def, $this->keep(
            ['karigar', 'open_jobs', 'issued', 'received', 'wastage', 'outstanding', 'invoiced', 'paid', 'payable'],
            $request->columnKeys,
        ));

        $rows = [];
        foreach ($data->rows as $r) {
            $rows[] = [
                'karigar' => (string) $r->karigar_name,
                'open_jobs' => (int) $r->open_jobs,
                'issued' => (float) $r->issued_fine,
                'received' => (float) $r->received_fine,
                'wastage' => (float) $r->wastage_fine,
                'outstanding' => (float) $r->outstanding_fine,
                'invoiced' => (float) $r->invoiced,
                'paid' => (float) $r->paid,
                'payable' => (float) $r->outstanding_payable,
            ];
        }

        $section = new ReportSection('karigars', 'By Karigar', $cols, $rows, $this->sum($rows, $cols));

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->karigar->settlement($request->shopId)->rows->count();
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

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  ColumnDefinition[]  $cols
     * @return array<string, float|int>
     */
    private function sum(array $rows, array $cols): array
    {
        $totals = [];
        foreach ($cols as $col) {
            if (! $col->type->isNumeric()) {
                continue;
            }
            $sum = 0.0;
            foreach ($rows as $row) {
                $sum += (float) ($row[$col->key] ?? 0);
            }
            $totals[$col->key] = round($sum, $col->type === T::Weight ? 4 : 2);
        }

        return $totals;
    }
}
