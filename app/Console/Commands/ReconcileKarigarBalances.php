<?php

namespace App\Console\Commands;

use App\Models\VaultReconciliationRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase R3 — Karigar gram accountability reconciliation.
 *
 * Reports outstanding gram balances per karigar, overdue job orders,
 * orphaned with_karigar items, and a vault-level gram cross-check.
 *
 * Safe to run on production (all read-only queries, plus one optional
 * vault_reconciliation_runs audit record).
 *
 * Exit codes: 0 = all clean, 1 = overdue jobs, orphaned items, or zero total grams.
 */
class ReconcileKarigarBalances extends Command
{
    protected $signature = 'karigar:reconcile
                            {--shop= : Specific shop ID}
                            {--days-overdue=30 : Days threshold for overdue jobs}';

    protected $description = 'Report karigar gram accountability and detect stuck/overdue job orders. Read-only — never auto-corrects.';

    public function handle(): int
    {
        $shopOption = $this->option('shop');
        $shopIds    = $shopOption !== null
            ? [(int) $shopOption]
            : DB::table('job_orders')->distinct()->pluck('shop_id')->all();

        if (empty($shopIds)) {
            $this->warn('No shops found in job_orders table.');
            return 0;
        }

        $daysOverdue   = (int) ($this->option('days-overdue') ?? 30);
        $totalProblems = 0;

        foreach ($shopIds as $shopId) {
            $problems = $this->reconcileShop((int) $shopId, $daysOverdue);
            $totalProblems += $problems;
        }

        $this->newLine();

        if ($totalProblems === 0) {
            $this->info('All karigar checks passed. No issues detected.');
            return 0;
        }

        $this->warn("{$totalProblems} issue(s) detected across all shops. Review the output above.");
        return 1;
    }

    private function reconcileShop(int $shopId, int $daysOverdue): int
    {
        $this->newLine();
        $this->info("━━━  Shop #{$shopId}  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // Environment context (display only — never affects detection or exit code).
        $env = DB::table('shops')->where('id', $shopId)->value('environment');
        if ($env !== null && $env !== 'production') {
            $this->line("  Note: {$env} shop — figures may reflect seeded data, not live operations.");
        }

        // ── Section A: Per-(karigar, metal_type) outstanding gram balance ───
        //
        // Phase 0 doctrine: a single "outstanding grams" total per karigar
        // is meaningless when the karigar handles multiple metals — it
        // adds gold grams to silver grams. We now report one row per
        // (karigar, metal_type) pair. Legacy job orders with metal_type
        // IS NULL are reported under bucket 'unknown' and never coerced.
        $this->info('Section A — Per-(karigar, metal) outstanding gram balance:');

        $karigarBalances = DB::select("
            SELECT k.id, k.name,
                   COALESCE(jo.metal_type, 'unknown') AS metal_type,
                   COUNT(jo.id) AS open_jobs,
                   COALESCE(SUM(jo.issued_fine_weight
                       - COALESCE(jo.returned_fine_weight, 0)
                       - COALESCE(jo.leftover_returned_fine_weight, 0)
                       - COALESCE(jo.actual_wastage_fine, 0)), 0) AS outstanding_fine
            FROM karigars k
            JOIN job_orders jo ON jo.karigar_id = k.id
            WHERE jo.shop_id = ?
              AND jo.status IN ('issued', 'partial_return')
            GROUP BY k.id, k.name, jo.metal_type
            ORDER BY k.name ASC, metal_type ASC
        ", [$shopId]);

        if (empty($karigarBalances)) {
            $this->line('  <fg=green>✓</> No open job orders found.');
        } else {
            $this->table(
                ['Karigar ID', 'Name', 'Metal', 'Open Jobs', 'Outstanding Fine (g)'],
                array_map(fn ($r) => [
                    $r->id,
                    $r->name,
                    $r->metal_type,
                    $r->open_jobs,
                    number_format((float) $r->outstanding_fine, 4),
                ], $karigarBalances)
            );
        }

        // Per-metal total across all karigars (replaces the cross-metal sum).
        $withKarigarByMetal = [];
        foreach ($karigarBalances as $row) {
            $metal = (string) $row->metal_type;
            $withKarigarByMetal[$metal] = ($withKarigarByMetal[$metal] ?? 0.0) + (float) $row->outstanding_fine;
        }
        $totalWithKarigar = array_sum($withKarigarByMetal);

        // ── Section B: Overdue jobs ──────────────────────────────────────────
        $this->newLine();
        $this->info("Section B — Overdue job orders (> {$daysOverdue} days):");

        $overdueJobs = DB::select("
            SELECT jo.id, jo.job_order_number, jo.job_type, jo.status,
                   k.name AS karigar_name,
                   jo.issued_fine_weight,
                   EXTRACT(DAY FROM NOW() - jo.issue_date)::int AS days_open
            FROM job_orders jo
            JOIN karigars k ON k.id = jo.karigar_id
            WHERE jo.shop_id = ?
              AND jo.status IN ('issued', 'partial_return')
              AND jo.issue_date < NOW() - (INTERVAL '1 day' * ?)
            ORDER BY jo.issue_date ASC
        ", [$shopId, $daysOverdue]);

        if (empty($overdueJobs)) {
            $this->line('  <fg=green>✓</> None found.');
        } else {
            $this->warn('  ' . count($overdueJobs) . ' overdue job order(s):');
            $this->table(
                ['ID', 'Job #', 'Type', 'Status', 'Karigar', 'Issued Fine (g)', 'Days Open'],
                array_map(fn ($r) => [
                    $r->id,
                    $r->job_order_number,
                    $r->job_type,
                    $r->status,
                    $r->karigar_name,
                    number_format((float) $r->issued_fine_weight, 4),
                    $r->days_open,
                ], $overdueJobs)
            );
        }

        // ── Section C: Orphaned with_karigar items ───────────────────────────
        $this->newLine();
        $this->info('Section C — Orphaned with_karigar items (status=with_karigar, no open job order):');

        $orphanedItems = DB::select("
            SELECT i.id, i.barcode, i.design, i.status,
                   i.updated_at
            FROM items i
            WHERE i.shop_id = ?
              AND i.status = 'with_karigar'
              AND NOT EXISTS (
                  SELECT 1 FROM job_orders jo
                  WHERE jo.source_item_id = i.id
                    AND jo.status IN ('issued', 'partial_return')
              )
            ORDER BY i.updated_at ASC
        ", [$shopId]);

        if (empty($orphanedItems)) {
            $this->line('  <fg=green>✓</> None found.');
        } else {
            $this->warn('  ' . count($orphanedItems) . ' orphaned item(s):');
            $this->table(
                ['ID', 'Barcode', 'Design', 'Status', 'Updated At'],
                array_map(fn ($r) => [
                    $r->id,
                    $r->barcode,
                    $r->design,
                    $r->status,
                    $r->updated_at,
                ], $orphanedItems)
            );
        }

        // ── Section D: Gram cross-check (per-metal) ──────────────────────────
        //
        // Phase 0: cross-check is now performed per-metal so the operator
        // can spot a metal-specific imbalance. The cross-metal grand total
        // is also displayed but as a sanity-only figure.
        $this->newLine();
        $this->info('Section D — Per-metal gram cross-check:');

        $vaultByMetal = DB::select("
            SELECT COALESCE(metal_type, 'unknown') AS metal_type,
                   COALESCE(SUM(fine_weight_remaining), 0) AS vault_fine
            FROM metal_lots
            WHERE shop_id = ?
            GROUP BY metal_type
            ORDER BY metal_type
        ", [$shopId]);

        $vaultByMetalMap = [];
        foreach ($vaultByMetal as $row) {
            $vaultByMetalMap[(string) $row->metal_type] = (float) $row->vault_fine;
        }

        $metalKeys = array_unique(array_merge(array_keys($vaultByMetalMap), array_keys($withKarigarByMetal)));
        sort($metalKeys);

        if (! empty($metalKeys)) {
            $this->table(
                ['Metal', 'Vault Fine (g)', 'With Karigars Fine (g)', 'Total (g)'],
                array_map(function ($metal) use ($vaultByMetalMap, $withKarigarByMetal) {
                    $vault = $vaultByMetalMap[$metal] ?? 0.0;
                    $karig = $withKarigarByMetal[$metal] ?? 0.0;
                    return [
                        $metal,
                        number_format($vault, 4),
                        number_format($karig, 4),
                        number_format($vault + $karig, 4),
                    ];
                }, $metalKeys)
            );
        }

        $vaultFine = array_sum($vaultByMetalMap);
        $totalAccounted = $vaultFine + $totalWithKarigar;

        $this->line(sprintf(
            '  Cross-metal sanity: vault=%sg | with karigars=%sg | total=%sg',
            number_format($vaultFine, 4),
            number_format($totalWithKarigar, 4),
            number_format($totalAccounted, 4)
        ));

        if ($totalAccounted === 0.0) {
            $this->warn('  Total accounted grams is zero — suggests no data or empty shop.');
        }

        // ── Optional run record ───────────────────────────────────────────────
        VaultReconciliationRun::create([
            'shop_id'          => $shopId,
            'run_at'           => now(),
            'run_by'           => null,
            'status'           => (empty($overdueJobs) && empty($orphanedItems) && $totalAccounted > 0.0)
                                    ? VaultReconciliationRun::STATUS_CLEAN
                                    : VaultReconciliationRun::STATUS_DISCREPANCY_FOUND,
            'discrepancy_lots' => empty($overdueJobs) && empty($orphanedItems) ? null : [
                'overdue_jobs'    => count($overdueJobs),
                'orphaned_items'  => count($orphanedItems),
                'total_with_karigar_fine' => $totalWithKarigar,
            ],
            'notes'            => 'karigar_reconcile',
        ]);

        // ── Determine problems for this shop ─────────────────────────────────
        $problems = 0;

        if (!empty($overdueJobs)) {
            $problems += count($overdueJobs);
        }

        if (!empty($orphanedItems)) {
            $problems += count($orphanedItems);
        }

        if ($totalAccounted === 0.0) {
            $problems++;
        }

        return $problems;
    }
}
