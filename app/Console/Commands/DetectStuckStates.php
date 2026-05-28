<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase R4 — Detect items and workflows stuck in transient states.
 *
 * Five checks:
 *   S1 — Items stuck in pending_restock > 14 days
 *   S2 — Items stuck in with_karigar without open job order > 30 days
 *   S3 — Job orders in partial_return status > 21 days
 *   S4 — Draft return orders > 7 days
 *   S5 — Repair jobs stuck in received/in_repair status > 21 days
 *
 * Safe to run on production (all read-only queries).
 *
 * Exit codes: 0 = all clean, 1 = at least one check found stuck items.
 */
class DetectStuckStates extends Command
{
    protected $signature = 'shop:detect-stuck
                            {--shop= : Specific shop ID}';

    protected $description = 'Detect items and workflows stuck in transient states beyond reasonable thresholds. Read-only — never auto-corrects.';

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

        $totalStuck = 0;

        foreach ($shopIds as $shopId) {
            $stuck = $this->detectForShop((int) $shopId);
            $totalStuck += $stuck;
        }

        $this->newLine();

        if ($totalStuck === 0) {
            $this->info('All stuck-state checks passed. No issues detected.');
            return 0;
        }

        $this->warn("{$totalStuck} stuck item(s)/workflow(s) detected across all shops. Review the output above.");
        return 1;
    }

    private function detectForShop(int $shopId): int
    {
        $this->newLine();
        $this->info("━━━  Shop #{$shopId}  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        $totalStuck = 0;

        // ── S1: Items stuck in pending_restock > 14 days ────────────────────
        $this->newLine();
        $this->info('Check S1 — Items stuck in pending_restock > 14 days:');

        $pendingRestock = DB::select("
            SELECT i.id, i.barcode, i.design, i.updated_at,
                   EXTRACT(DAY FROM NOW() - i.updated_at)::int AS days_stuck
            FROM items i
            WHERE i.shop_id = ?
              AND i.status = 'pending_restock'
              AND i.updated_at < NOW() - INTERVAL '14 days'
            ORDER BY i.updated_at ASC
        ", [$shopId]);

        if (empty($pendingRestock)) {
            $this->line('  <fg=green>✓</> None found.');
        } else {
            $this->warn('  ' . count($pendingRestock) . ' item(s) stuck in pending_restock:');
            $this->table(
                ['ID', 'Barcode', 'Design', 'Updated At', 'Days Stuck'],
                array_map(fn ($r) => [
                    $r->id,
                    $r->barcode,
                    $r->design,
                    $r->updated_at,
                    $r->days_stuck,
                ], $pendingRestock)
            );
            $totalStuck += count($pendingRestock);
        }

        // ── S2: Items stuck in with_karigar without open job > 30 days ──────
        $this->newLine();
        $this->info('Check S2 — Items stuck in with_karigar without open job order > 30 days:');

        $withKarigarOrphaned = DB::select("
            SELECT i.id, i.barcode, i.design, i.status,
                   i.updated_at,
                   EXTRACT(DAY FROM NOW() - i.updated_at)::int AS days_stuck
            FROM items i
            WHERE i.shop_id = ?
              AND i.status = 'with_karigar'
              AND i.updated_at < NOW() - INTERVAL '30 days'
              AND NOT EXISTS (
                  SELECT 1 FROM job_orders jo
                  WHERE jo.source_item_id = i.id
                    AND jo.status IN ('issued', 'partial_return')
              )
            ORDER BY i.updated_at ASC
        ", [$shopId]);

        if (empty($withKarigarOrphaned)) {
            $this->line('  <fg=green>✓</> None found.');
        } else {
            $this->warn('  ' . count($withKarigarOrphaned) . ' item(s) orphaned in with_karigar > 30 days:');
            $this->table(
                ['ID', 'Barcode', 'Design', 'Status', 'Updated At', 'Days Stuck'],
                array_map(fn ($r) => [
                    $r->id,
                    $r->barcode,
                    $r->design,
                    $r->status,
                    $r->updated_at,
                    $r->days_stuck,
                ], $withKarigarOrphaned)
            );
            $totalStuck += count($withKarigarOrphaned);
        }

        // ── S3: Job orders in partial_return status > 21 days ───────────────
        $this->newLine();
        $this->info('Check S3 — Job orders in partial_return status > 21 days:');

        $partialReturnStuck = DB::select("
            SELECT jo.id, jo.job_order_number, jo.status, k.name AS karigar_name,
                   EXTRACT(DAY FROM NOW() - jo.updated_at)::int AS days_stuck
            FROM job_orders jo
            JOIN karigars k ON k.id = jo.karigar_id
            WHERE jo.shop_id = ?
              AND jo.status = 'partial_return'
              AND jo.updated_at < NOW() - INTERVAL '21 days'
            ORDER BY jo.updated_at ASC
        ", [$shopId]);

        if (empty($partialReturnStuck)) {
            $this->line('  <fg=green>✓</> None found.');
        } else {
            $this->warn('  ' . count($partialReturnStuck) . ' job order(s) stuck in partial_return:');
            $this->table(
                ['ID', 'Job #', 'Status', 'Karigar', 'Days Stuck'],
                array_map(fn ($r) => [
                    $r->id,
                    $r->job_order_number,
                    $r->status,
                    $r->karigar_name,
                    $r->days_stuck,
                ], $partialReturnStuck)
            );
            $totalStuck += count($partialReturnStuck);
        }

        // ── S4: Draft return orders > 7 days ────────────────────────────────
        $this->newLine();
        $this->info('Check S4 — Draft return orders > 7 days:');

        $draftReturns = DB::select("
            SELECT ro.id, ro.created_at,
                   COALESCE(c.first_name || ' ' || c.last_name, 'Walk-in') AS customer_name
            FROM return_orders ro
            LEFT JOIN customers c ON c.id = ro.customer_id
            WHERE ro.shop_id = ?
              AND ro.status = 'draft'
              AND ro.created_at < NOW() - INTERVAL '7 days'
            ORDER BY ro.created_at ASC
        ", [$shopId]);

        if (empty($draftReturns)) {
            $this->line('  <fg=green>✓</> None found.');
        } else {
            $this->warn('  ' . count($draftReturns) . ' draft return order(s) > 7 days:');
            $this->table(
                ['ID', 'Created At', 'Customer'],
                array_map(fn ($r) => [
                    $r->id,
                    $r->created_at,
                    $r->customer_name,
                ], $draftReturns)
            );
            $totalStuck += count($draftReturns);
        }

        // ── S5: Repair jobs stuck in received/in_repair > 21 days ──────────
        $this->newLine();
        $this->info('Check S5 — Repair jobs stuck in received or in_repair status > 21 days:');

        $stuckRepairs = DB::select("
            SELECT r.id, r.repair_number, r.item_description, r.status,
                   r.updated_at,
                   COALESCE(c.first_name || ' ' || c.last_name, 'Walk-in') AS customer_name,
                   EXTRACT(DAY FROM NOW() - r.updated_at)::int AS days_stuck
            FROM repairs r
            LEFT JOIN customers c ON c.id = r.customer_id
            WHERE r.shop_id = ?
              AND r.status IN ('received', 'in_repair')
              AND r.updated_at < NOW() - INTERVAL '21 days'
            ORDER BY r.updated_at ASC
        ", [$shopId]);

        if (empty($stuckRepairs)) {
            $this->line('  <fg=green>✓</> None found.');
        } else {
            $this->warn('  ' . count($stuckRepairs) . ' repair job(s) stuck with no progress > 21 days:');
            $this->table(
                ['ID', 'Repair #', 'Item', 'Status', 'Customer', 'Updated At', 'Days Stuck'],
                array_map(fn ($r) => [
                    $r->id,
                    $r->repair_number,
                    $r->item_description,
                    $r->status,
                    $r->customer_name,
                    $r->updated_at,
                    $r->days_stuck,
                ], $stuckRepairs)
            );
            $totalStuck += count($stuckRepairs);
        }

        return $totalStuck;
    }
}
