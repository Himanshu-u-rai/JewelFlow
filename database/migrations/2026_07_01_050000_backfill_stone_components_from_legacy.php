<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A — Migration 5/N — Backfill stone_components from legacy columns.
 *
 * For every:
 *   - invoice_items row with stone_amount > 0 → one stone_components row
 *     (stone_type='other', count=1, unit_value=stone_amount,
 *      total_value=stone_amount, migrated_from_legacy=true)
 *   - items row with stone_charges > 0 AND not yet sold (linked to a finalized
 *     invoice_item) → one stone_components row linked to the item
 *
 * Constitutional rules honored:
 *   - invoice_items.stone_amount is locked by the finalized-guard trigger
 *     (and we never UPDATE it here)
 *   - items.stone_charges is mutable on in-stock items so creating a
 *     mirror stone_components row is fine; the items.stone_charges value
 *     is preserved untouched
 *   - migrated_from_legacy=true marks the row for forensic identification
 *     forever
 *
 * Idempotent: skips invoice_items / items that already have linked
 * stone_components rows. Re-running creates no duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stone_components')) {
            return;
        }

        $now = now();
        $invoiceItemBackfilled = 0;
        $itemBackfilled = 0;

        // ── invoice_items.stone_amount > 0 ─────────────────────────────
        // Snapshot stones tied to invoice lines (sold transactions).
        DB::table('invoice_items')
            ->whereNotNull('stone_amount')
            ->where('stone_amount', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($lines) use ($now, &$invoiceItemBackfilled): void {
                foreach ($lines as $line) {
                    // Skip if already has stone_components linked.
                    $exists = DB::table('stone_components')
                        ->where('invoice_item_id', $line->id)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    // Resolve shop_id via the invoice (invoice_items doesn't
                    // carry shop_id directly).
                    $shopId = DB::table('invoices')
                        ->where('id', $line->invoice_id)
                        ->value('shop_id');

                    if ($shopId === null) {
                        continue; // orphan, skip
                    }

                    DB::table('stone_components')->insert([
                        'shop_id'              => (int) $shopId,
                        'item_id'              => null,
                        'invoice_item_id'      => (int) $line->id,
                        'return_line_item_id'  => null,
                        'stone_type'           => 'other',
                        'carat_weight'         => null,
                        'count'                => 1,
                        'unit_value'           => round((float) $line->stone_amount, 2),
                        'total_value'          => round((float) $line->stone_amount, 2),
                        'notes'                => 'Phase 2A backfill — legacy stone_amount mirror. Original column preserved.',
                        'migrated_from_legacy' => DB::getDriverName() === 'pgsql' ? DB::raw('true') : true,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ]);

                    $invoiceItemBackfilled++;
                }
            });

        // ── items.stone_charges > 0 AND in_stock ──────────────────────────
        // Snapshot stones tied to inventory items (not yet sold). Skip items
        // that are 'sold' / 'reversed' / etc. — those are mirrored at the
        // invoice_item level instead.
        DB::table('items')
            ->whereNotNull('stone_charges')
            ->where('stone_charges', '>', 0)
            ->where('status', 'in_stock')
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($now, &$itemBackfilled): void {
                foreach ($items as $item) {
                    $exists = DB::table('stone_components')
                        ->where('item_id', $item->id)
                        ->whereNull('invoice_item_id')
                        ->whereNull('return_line_item_id')
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    DB::table('stone_components')->insert([
                        'shop_id'              => (int) $item->shop_id,
                        'item_id'              => (int) $item->id,
                        'invoice_item_id'      => null,
                        'return_line_item_id'  => null,
                        'stone_type'           => 'other',
                        'carat_weight'         => $item->stone_weight ?: null,
                        'count'                => 1,
                        'unit_value'           => round((float) $item->stone_charges, 2),
                        'total_value'          => round((float) $item->stone_charges, 2),
                        'notes'                => 'Phase 2A backfill — legacy items.stone_charges mirror. Original column preserved.',
                        'migrated_from_legacy' => DB::getDriverName() === 'pgsql' ? DB::raw('true') : true,
                        'created_at'           => $now,
                        'updated_at'           => $now,
                    ]);

                    $itemBackfilled++;
                }
            });

        error_log(sprintf(
            '[Phase2A/050000] Backfilled %d invoice_item + %d inventory item stone_components rows.',
            $invoiceItemBackfilled,
            $itemBackfilled
        ));
    }

    public function down(): void
    {
        if (! Schema::hasTable('stone_components')) {
            return;
        }

        // Only remove rows marked as backfilled. Operator-added rows are preserved.
        // PostgreSQL: migrated_from_legacy IS TRUE
        $where = DB::getDriverName() === 'pgsql' ? 'migrated_from_legacy IS TRUE' : 'migrated_from_legacy = 1';
        DB::statement("DELETE FROM stone_components WHERE {$where}");
    }
};
