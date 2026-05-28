<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 — Migration 3/9 — Silent Wrongness Elimination
 *
 * Composite index for per-metal aggregation queries used by:
 *   - ClosingController (daily per-metal flow)
 *   - PnlController (per-metal revenue/cost)
 *   - ReconcileVaultBalances (per-metal lot-vs-ledger checks)
 *   - BullionVaultService::vaultBalances (per-metal vault snapshot)
 *
 * Without this index, every per-metal aggregation does a full scan with
 * filter, which is fine at pilot scale but slow at the volumes a
 * multi-shop deployment will hit. Adding the index now is constitutional
 * forward-fill — the index does not change correctness, only response time.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metal_movements')) {
            return;
        }
        if (! Schema::hasColumn('metal_movements', 'metal_type')) {
            throw new RuntimeException(
                'metal_movements.metal_type column missing. Run migrations 010000 and 020000 first.'
            );
        }

        Schema::table('metal_movements', function (Blueprint $table): void {
            // Idempotent guard — Laravel's hasIndex isn't reliable across drivers,
            // so check via information_schema for Postgres.
            $exists = \DB::selectOne(
                "SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'metal_movements_shop_metal_created_idx'"
            );
            if (! $exists) {
                $table->index(
                    ['shop_id', 'metal_type', 'created_at'],
                    'metal_movements_shop_metal_created_idx'
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('metal_movements')) {
            return;
        }
        Schema::table('metal_movements', function (Blueprint $table): void {
            $exists = \DB::selectOne(
                "SELECT 1 FROM pg_indexes WHERE schemaname = 'public' AND indexname = 'metal_movements_shop_metal_created_idx'"
            );
            if ($exists) {
                $table->dropIndex('metal_movements_shop_metal_created_idx');
            }
        });
    }
};
