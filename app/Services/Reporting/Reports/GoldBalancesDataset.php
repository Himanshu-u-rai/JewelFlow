<?php

namespace App\Services\Reporting\Reports;

use App\Services\BullionVaultService;
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
 * Gold Balances — fine-weight vault holdings by metal and purity (OWNER class,
 * frozen §22 / P4). Point-in-time.
 *
 * Wraps the canonical balance engine BullionVaultService::vaultBalances() — it
 * does NOT create a second balance engine. The per-(metal, purity) on-hand
 * figure is the service's in_vault_fine (Σ metal_lots.fine_weight_remaining for
 * the group), so the grand total equals SUM(metal_lots.fine_weight_remaining) —
 * the exact authoritative figure vault:reconcile certifies — by construction.
 * Vault holdings are confidential, so the document is watermarked CONFIDENTIAL.
 */
class GoldBalancesDataset extends ReportDatasetService
{
    public const KEY = 'gold-balances';
    public const VERSION = 'gold-balances@1';

    public function __construct(private readonly BullionVaultService $vault)
    {
    }

    public function definition(): ReportDefinition
    {
        return new ReportDefinition(
            key: self::KEY,
            version: self::VERSION,
            title: 'Gold Balances',
            classification: Cls::Owner,
            columns: [
                Col::mandatory('metal', 'Metal', T::String),
                Col::mandatory('purity', 'Purity', T::Decimal),
                Col::mandatory('on_hand', 'Fine Gold (g)', T::Weight),
                Col::mandatory('lots', 'Lots', T::Integer),
            ],
            profiles: [P::Summary, P::Detailed, P::Ca],
            filters: [
                Filter::for(FK::AsOf),
            ],
            formats: [F::Pdf, F::Excel, F::Csv, F::Screen],
            permissions: Perm::default(),
            watermarkBaseline: 'CONFIDENTIAL',
        );
    }

    public function build(ReportRequest $request, ReportMeta $meta): ReportDataset
    {
        $balances = $this->vault->vaultBalances($request->shopId);

        $ordered = $balances
            ->sort(fn ($a, $b) => [(string) ($a['metal_type'] ?? 'zzz'), -$a['purity']]
                <=> [(string) ($b['metal_type'] ?? 'zzz'), -$b['purity']])
            ->values();

        $rows = [];
        foreach ($ordered as $b) {
            $rows[] = [
                'metal' => ucfirst((string) ($b['metal_type'] ?? 'unknown')),
                'purity' => (float) $b['purity'],
                'on_hand' => (float) $b['in_vault_fine'],
                'lots' => (int) $b['lots_count'],
            ];
        }

        // Grand total == Σ in_vault_fine == SUM(metal_lots.fine_weight_remaining)
        // == vault:reconcile authoritative total, by construction.
        $totals = ['on_hand' => round((float) $balances->sum('in_vault_fine'), 4)];

        $section = new ReportSection('balances', 'Vault Balances', $request->columns(), $rows, $totals);

        return new ReportDataset([$section], $meta);
    }

    public function estimateRowCount(ReportRequest $request): ?int
    {
        return $this->vault->vaultBalances($request->shopId)->count();
    }
}
