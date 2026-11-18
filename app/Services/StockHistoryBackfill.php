<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One-time backfill for the financial-year stock work (migration 2026_11_16_300000). Kept in a class so a test can
 * run the exact same SQL against pre-existing data. Both methods are idempotent.
 *
 * CONSTITUTION §5: acquisition_cost is only ever taken from a stock purchase line. cost_price is the CURRENT
 * replacement cost (rewritten on every daily-rate save) and is never copied; everything else stays NULL / 'unknown'.
 */
final class StockHistoryBackfill
{
    /** Items that came from a purchase: acquisition_cost = purchase line net weight x purchase rate per gram. */
    public static function acquisitionCosts(): void
    {
        DB::statement(<<<'SQL'
            UPDATE items i
            SET acquisition_cost = ROUND(spi.net_metal_weight * spi.purchase_rate_per_gram, 2),
                acquisition_cost_source = 'purchase'
            FROM (
                SELECT DISTINCT ON (item_id) item_id, net_metal_weight, purchase_rate_per_gram
                FROM stock_purchase_items
                WHERE item_id IS NOT NULL AND purchase_rate_per_gram > 0 AND net_metal_weight > 0
                ORDER BY item_id, id DESC
            ) spi
            WHERE spi.item_id = i.id
              AND i.stock_purchase_id IS NOT NULL
              AND i.acquisition_cost IS NULL
        SQL);

        DB::statement("UPDATE items SET acquisition_cost_source = 'unknown' WHERE acquisition_cost_source IS NULL");
    }

    /**
     * One 'intake' movement per item at its created_at, plus one status movement for items no longer in_stock at
     * their updated_at. Rows are flagged is_backfilled: their dates are the best the old schema recorded.
     */
    public static function movements(string $table = 'item_movements'): void
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
            throw new InvalidArgumentException('Bad table name');
        }

        DB::statement(<<<SQL
            INSERT INTO {$table} (shop_id, item_id, movement_type, from_status, to_status, gross_weight, net_metal_weight,
                                  purity, metal_type, acquisition_cost, reference_type, reference_id, occurred_at,
                                  created_at, is_backfilled)
            SELECT i.shop_id, i.id, 'intake', NULL, 'in_stock', i.gross_weight, i.net_metal_weight, i.purity, i.metal_type,
                   i.acquisition_cost,
                   CASE WHEN i.stock_purchase_id IS NOT NULL THEN 'stock_purchase' WHEN i.job_order_id IS NOT NULL THEN 'job_order' END,
                   COALESCE(i.stock_purchase_id, i.job_order_id),
                   COALESCE(i.created_at, i.updated_at, now() AT TIME ZONE 'Asia/Kolkata'), now(), true
            FROM items i
            WHERE NOT EXISTS (SELECT 1 FROM {$table} m WHERE m.item_id = i.id AND m.movement_type = 'intake')
        SQL);

        DB::statement(<<<SQL
            INSERT INTO {$table} (shop_id, item_id, movement_type, from_status, to_status, gross_weight, net_metal_weight,
                                  purity, metal_type, acquisition_cost, occurred_at, created_at, is_backfilled)
            SELECT i.shop_id, i.id, item_movement_type('in_stock', i.status), 'in_stock', i.status, i.gross_weight,
                   i.net_metal_weight, i.purity, i.metal_type, i.acquisition_cost,
                   GREATEST(COALESCE(i.updated_at, i.created_at), COALESCE(i.created_at, i.updated_at)), now(), true
            FROM items i
            WHERE i.status <> 'in_stock'
              AND NOT EXISTS (SELECT 1 FROM {$table} m WHERE m.item_id = i.id AND m.movement_type <> 'intake')
        SQL);
    }
}
