<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Safety backfill so the new mandatory opening-setup gate never locks out a shop
 * that is already trading.
 *
 * A shop with ANY transactional history — finalized invoices, cash transactions,
 * metal movements, or customer gold transactions — is treated as "started fresh"
 * by stamping opening_setup_skipped_at (only if still null). Zero-history shops
 * and brand-new shops are left untouched so they see the setup decision.
 *
 * Idempotent: only stamps rows where opening_setup_skipped_at IS NULL, and skips
 * shops that already carry a locked onboarding batch (already migration_completed).
 * No financial rows are read-mutated — this only writes shop_preferences.
 *
 * Tenant-safe: the DB query builder carries no global tenant scope, and every
 * query is keyed by explicit shop_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $historyShopIds = collect()
            ->merge(DB::table('invoices')->where('status', 'finalized')->distinct()->pluck('shop_id'))
            ->merge(DB::table('cash_transactions')->distinct()->pluck('shop_id'))
            ->merge(DB::table('metal_movements')->distinct()->pluck('shop_id'))
            ->merge(DB::table('customer_gold_transactions')->distinct()->pluck('shop_id'))
            ->filter()
            ->unique();

        if ($historyShopIds->isEmpty()) {
            return;
        }

        // Shops with a locked batch are already migration_completed — leave them.
        $lockedShopIds = DB::table('onboarding_batches')
            ->where('status', 'locked')
            ->distinct()
            ->pluck('shop_id')
            ->all();

        foreach ($historyShopIds as $shopId) {
            if (in_array($shopId, $lockedShopIds, false)) {
                continue;
            }

            $pref = DB::table('shop_preferences')->where('shop_id', $shopId)->first();

            if ($pref === null) {
                DB::table('shop_preferences')->insert([
                    'shop_id'                  => $shopId,
                    'opening_setup_skipped_at' => $now,
                    'created_at'               => $now,
                    'updated_at'               => $now,
                ]);
                continue;
            }

            // Already decided (started fresh earlier) — do not touch.
            if ($pref->opening_setup_skipped_at !== null) {
                continue;
            }

            DB::table('shop_preferences')
                ->where('shop_id', $shopId)
                ->update([
                    'opening_setup_skipped_at' => $now,
                    'updated_at'               => $now,
                ]);
        }
    }

    public function down(): void
    {
        // One-way safety backfill. Reversing it would re-lock active shops out of
        // the ERP, so down() is intentionally a no-op.
    }
};
