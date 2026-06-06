<?php

namespace App\Services\Reporting\Reports;

use App\Reporting\ReceivablesService;
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
 * Metal Liability — customer-advance gold owed vs gold on hand (Accounting /
 * Receivables sub-set, frozen §22). Point-in-time.
 *
 * Wraps the canonical ReceivablesService::metalLiability() VERBATIM — the report
 * layer derives no liability of its own. The Summary's owed/deposited/on-hand
 * figures and every customer row are the service's own values, so the report
 * reconciles to the service (and the on-hand figure to vault:reconcile's
 * SUM(metal_lots.fine_weight_remaining)) by construction. Customer is a
 * sensitive (permission-gated) column.
 */
class MetalLiabilityDataset extends ReportDatasetService
{
    public const KEY = 'metal-liability';
    public const VERSION = 'metal-liability@1';

    public function __construct(private readonly ReceivablesService $receivables)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Metal Liability',
            classification: Cls::Accounting,
            columns: [
                // Summary section
                Col::mandatory('particular', 'Particular', T::String),
                Col::mandatory('grams', 'Fine Gold (g)', T::Weight),
                // Customer breakdown section
                Col::mandatory('deposited', 'Fine Gold Deposited (g)', T::Weight),
                Col::sensitive('customer', 'Customer', T::String),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca],
            filters: [
                Filter::for(FK::AsOf),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $def = $request->definition;
        $keys = $request->columnKeys;
        $data = $this->receivables->metalLiability($request->shopId);

        // --- Summary (owed vs on-hand, all from the canonical service) ---
        $summaryCols = $this->cols($def, $this->keep(['particular', 'grams'], $keys));
        $summaryRows = [
            ['particular' => 'Gold You Owe Customers', 'grams' => $data->totalAdvanceLiability],
            ['particular' => 'Total Gold Deposited', 'grams' => $data->totalDeposited],
            ['particular' => 'Old Gold Taken In', 'grams' => $data->oldGoldAcceptedFine],
            ['particular' => 'Gold On Hand (Vault)', 'grams' => $data->vaultOnHandFine],
        ];
        $summary = new ReportSection('summary', 'Summary', $summaryCols, $summaryRows);

        // --- Per-customer breakdown -------------------------------------
        $custCols = $this->cols($def, $this->keep(['customer', 'deposited'], $keys));
        $custRows = [];
        foreach ($data->rows as $r) {
            $custRows[] = [
                'customer' => $r->customer_name,
                'deposited' => (float) $r->fine_deposited,
            ];
        }
        // Grand total of the breakdown == the service's totalDeposited by construction.
        $custTotals = ['deposited' => round($data->totalDeposited, 4)];
        $customers = new ReportSection('customers', 'Customer Deposits', $custCols, $custRows, $custTotals);

        return new ReportDataset([$summary, $customers], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return 4 + $this->receivables->metalLiability($request->shopId)->customerCount;
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
