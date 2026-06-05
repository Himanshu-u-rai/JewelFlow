<?php

namespace App\Services\Reporting\Reports;

use App\Models\MetalMovement;
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
 * Metal Movement Ledger — the audit-grade gold-movement trail (Addendum C §30,
 * Accounting). Wraps the SAME `metal_movements` source the vault reconciler reads
 * (`ReconcileVaultBalances`), so it reconciles BY CONSTRUCTION: the net fine
 * weight per lot derived from these rows (Σ into-lot − Σ out-of-lot) equals
 * `metal_lots.fine_weight_remaining` — exactly the `vault:reconcile` invariant.
 * Consumes persisted movements only; no recomputation.
 */
class MetalMovementLedgerDataset extends ReportDatasetService
{
    public const KEY = 'metal-ledger';
    public const VERSION = 'metal-ledger@1';

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Metal Movement Ledger',
            classification: Cls::Accounting,
            columns: [
                Col::mandatory('datetime', 'Date / Time', T::DateTime),
                Col::mandatory('movement_type', 'Movement', T::String),
                Col::mandatory('from_lot', 'From Lot', T::String),
                Col::mandatory('to_lot', 'To Lot', T::String),
                Col::mandatory('fine_weight', 'Fine Wt (g)', T::Weight),
                Col::mandatory('reference', 'Reference', T::String),
                Col::optional('metal_type', 'Metal', T::String),
                Col::optional('reason', 'Adjustment Reason', T::String),
                Col::sensitive('operator', 'Operator', T::String),
            ],
            profiles: [P::Detailed],
            filters: [
                Filter::for(FK::Period, true),
                Filter::for(FK::MetalType),
                Filter::for(FK::MovementType),
                Filter::for(FK::Lot),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $keys = $request->columnKeys;
        $movements = $this->query($request)->with(['fromLot', 'toLot', 'user'])->get();

        $rows = [];
        $fineTotal = 0.0;
        foreach ($movements as $m) {
            $rows[] = [
                'datetime' => $m->created_at,
                'movement_type' => Str::headline((string) $m->type),
                'from_lot' => $m->fromLot?->lot_number ?? '—',
                'to_lot' => $m->toLot?->lot_number ?? '—',
                'fine_weight' => (float) $m->fine_weight,
                'reference' => $this->reference($m),
                'metal_type' => $m->metal_type,
                'reason' => $m->adjustment_reason,
                'operator' => $m->user?->name ?? 'System',
            ];
            $fineTotal += (float) $m->fine_weight;
        }

        $totals = in_array('fine_weight', $keys, true) ? ['fine_weight' => round($fineTotal, 4)] : [];

        $section = new ReportSection('movements', 'Metal Movement Ledger', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->query($request)->count();
    }

    private function query(ReportRequest $request)
    {
        $q = MetalMovement::query();

        $period = $request->filter('period', []);
        if (! empty($period['from']) && ! empty($period['to'])) {
            $q->whereBetween('created_at', [$period['from'], $period['to']]);
        }
        if ($metal = $request->filter('metal_type')) {
            $q->where('metal_type', $metal);
        }
        if ($type = $request->filter('movement_type')) {
            $q->where('type', $type);
        }
        if ($lot = $request->filter('lot')) {
            $q->where(fn ($w) => $w->where('from_lot_id', $lot)->orWhere('to_lot_id', $lot));
        }

        return $q->orderBy('created_at')->orderBy('id');
    }

    private function reference(MetalMovement $m): string
    {
        if ($m->reference_type !== null && $m->reference_id !== null) {
            return Str::headline((string) $m->reference_type) . ' #' . $m->reference_id;
        }
        if ($m->invoice_id !== null) {
            return 'Invoice #' . $m->invoice_id;
        }

        return '—';
    }
}
