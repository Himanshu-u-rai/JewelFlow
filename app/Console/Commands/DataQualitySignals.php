<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase R10 — Detect early-warning data quality drift patterns.
 *
 * Four signals:
 *   Q1 — Karigar receipts with exact weight match (possible copy-paste)
 *   Q2 — Condition field fill rate on return lines
 *   Q3 — Draft return order abandonment rate
 *   Q4 — Manual GST override frequency (if gst_override column exists)
 *
 * Safe to run on production (all read-only queries).
 *
 * Exit codes: 0 = no signals detected, 1 = at least one signal found.
 */
class DataQualitySignals extends Command
{
    protected $signature = 'shop:quality-signals
                            {--shop= : Specific shop ID}';

    protected $description = 'Detect early-warning data quality drift patterns. Read-only — never auto-corrects.';

    public function handle(): int
    {
        $shopOption = $this->option('shop');
        $shopIds    = $shopOption !== null
            ? [(int) $shopOption]
            : DB::table('shops')->whereRaw('is_active = TRUE')->pluck('id')->all();

        if (empty($shopIds)) {
            $this->warn('No active shops found.');
            return 0;
        }

        $totalSignals = 0;

        foreach ($shopIds as $shopId) {
            $signals = $this->detectForShop((int) $shopId);
            $totalSignals += $signals;
        }

        $this->newLine();

        if ($totalSignals === 0) {
            $this->info('All data quality checks passed. No signals detected.');
            return 0;
        }

        $this->warn("{$totalSignals} data quality signal(s) detected. Review the output above.");
        return 1;
    }

    private function detectForShop(int $shopId): int
    {
        $this->newLine();
        $this->info("━━━  Shop #{$shopId}  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        $totalSignals = 0;

        // ── Q1: Karigar receipts with exact weight match ─────────────────────
        $this->newLine();
        $this->info('Check Q1 — Karigar receipts with exact weight match (possible copy-paste):');

        $q1 = DB::selectOne("
            SELECT COUNT(*) AS cnt
            FROM job_orders jo
            WHERE jo.shop_id = ?
              AND jo.status IN ('completed', 'partial_return')
              AND jo.returned_fine_weight IS NOT NULL
              AND jo.issued_fine_weight IS NOT NULL
              AND ABS(jo.returned_fine_weight - jo.issued_fine_weight) < 0.001
              AND jo.actual_wastage_fine IS NOT NULL
              AND jo.actual_wastage_fine = 0
              AND jo.updated_at > NOW() - INTERVAL '30 days'
        ", [$shopId]);

        $q1Count = (int) ($q1->cnt ?? 0);

        if ($q1Count <= 3) {
            $this->line('  <fg=green>✓</> ' . ($q1Count === 0 ? 'None found.' : "{$q1Count} found (below threshold of 3)."));
        } else {
            $this->warn("  Q1 — Karigar receipts with exact weight match (possible copy-paste): {$q1Count} in last 30 days");
            $this->line('  <fg=yellow>Note:</> Weight variance of 0 AND wastage of 0 may indicate weight was not verified on scale.');
            $totalSignals++;
        }

        // ── Q2: Condition field fill rate on return lines ────────────────────
        $this->newLine();
        $this->info('Check Q2 — Return condition field fill rate:');

        $q2 = DB::selectOne("
            SELECT
                COUNT(*) AS total_lines,
                COUNT(CASE WHEN condition IS NULL OR condition = '' THEN 1 END) AS missing_condition,
                ROUND(100.0 * COUNT(CASE WHEN condition IS NULL OR condition = '' THEN 1 END) / NULLIF(COUNT(*), 0), 1) AS missing_pct
            FROM return_line_items rli
            JOIN return_orders ro ON ro.id = rli.return_order_id
            WHERE ro.shop_id = ?
              AND ro.created_at > NOW() - INTERVAL '30 days'
        ", [$shopId]);

        $totalLines       = (int) ($q2->total_lines ?? 0);
        $missingCondition = (int) ($q2->missing_condition ?? 0);
        $missingPct       = (float) ($q2->missing_pct ?? 0);

        if ($totalLines < 5 || $missingPct <= 30) {
            $this->line('  <fg=green>✓</> ' . ($totalLines === 0 ? 'No return lines in last 30 days.' : "Condition fill rate acceptable ({$missingPct}% missing across {$totalLines} lines)."));
        } else {
            $this->warn("  Q2 — Return condition field empty: {$missingPct}% of lines ({$missingCondition}/{$totalLines}) in last 30 days");
            $this->line('  <fg=yellow>Note:</> Missing condition data reduces Queue 2 disposition suggestions.');
            $totalSignals++;
        }

        // ── Q3: Draft return order abandonment rate ──────────────────────────
        $this->newLine();
        $this->info('Check Q3 — Stale draft return orders (>3 days old, never submitted):');

        $q3 = DB::selectOne("
            SELECT
                COUNT(*) AS total_drafts,
                COUNT(CASE WHEN created_at < NOW() - INTERVAL '3 days' THEN 1 END) AS stale_drafts
            FROM return_orders
            WHERE shop_id = ?
              AND status = 'draft'
              AND created_at > NOW() - INTERVAL '30 days'
        ", [$shopId]);

        $staleDrafts = (int) ($q3->stale_drafts ?? 0);

        if ($staleDrafts < 3) {
            $this->line('  <fg=green>✓</> ' . ($staleDrafts === 0 ? 'None found.' : "{$staleDrafts} found (below threshold of 3)."));
        } else {
            $this->warn("  Q3 — Stale draft returns (>3 days old, never submitted): {$staleDrafts} in last 30 days");
            $this->line('  <fg=yellow>Note:</> Draft returns that are never submitted may indicate cashier confusion or abandoned transactions.');
            $totalSignals++;
        }

        // ── Q4: Manual GST override frequency ───────────────────────────────
        $this->newLine();
        $this->info('Check Q4 — Manual GST rate override frequency:');

        try {
            $q4 = DB::selectOne("
                SELECT COUNT(*) AS override_count
                FROM invoices
                WHERE shop_id = ?
                  AND gst_override = TRUE
                  AND created_at > NOW() - INTERVAL '30 days'
            ", [$shopId]);

            $overrideCount = (int) ($q4->override_count ?? 0);

            if ($overrideCount <= 10) {
                $this->line('  <fg=green>✓</> ' . ($overrideCount === 0 ? 'None found.' : "{$overrideCount} found (below threshold of 10)."));
            } else {
                $this->warn("  Q4 — Manual GST rate overrides: {$overrideCount} in last 30 days");
                $this->line('  <fg=yellow>Note:</> Frequent manual GST overrides bypass the category-based default rate.');
                $totalSignals++;
            }
        } catch (\Exception $e) {
            $this->line('  <fg=gray>–</> Q4 skipped (gst_override column not present).');
        }

        return $totalSignals;
    }
}
