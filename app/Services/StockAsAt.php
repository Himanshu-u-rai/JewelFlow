<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the stock a shop held at the end of a past (IST) date from item_movements, the append-only ledger the
 * items triggers write. Never reads items.status, so it stays correct after the items have sold, returned or gone.
 */
final class StockAsAt
{
    /** Statuses where the shop still owns the piece (a piece out with a karigar or awaiting restock is still stock). */
    public const STOCK_STATUSES = ['in_stock', 'pending_restock', 'pending_listing', 'with_karigar'];

    public static function isStockStatus(?string $status): bool
    {
        return $status !== null && in_array($status, self::STOCK_STATUSES, true);
    }

    /** False when $date (Y-m-d) is before the shop's exact stock history began: the figures are rebuilt, not recorded. */
    public static function isExact(int $shopId, string $date): bool
    {
        return StockHistory::isExactAt($shopId, $date);
    }

    /**
     * One row per item in stock at 23:59:59 IST on $date (Y-m-d). Weights, purity and metal are the snapshot of the
     * last movement; acquisition_cost falls back to the live items row for costs filled in after intake.
     */
    public static function itemsInStock(int $shopId, string $date): Collection
    {
        $endExclusive = CarbonImmutable::parse($date, 'Asia/Kolkata')->startOfDay()->addDay()->format('Y-m-d H:i:s');
        $placeholders = implode(',', array_fill(0, count(self::STOCK_STATUSES), '?'));

        return collect(DB::select(<<<SQL
            SELECT l.item_id, l.shop_id, l.to_status AS status, l.gross_weight, l.net_metal_weight, l.purity, l.metal_type,
                   COALESCE(l.acquisition_cost, i.acquisition_cost) AS acquisition_cost, l.occurred_at AS as_of_movement_at
            FROM (
                SELECT DISTINCT ON (item_id) *
                FROM item_movements
                WHERE shop_id = ? AND occurred_at < ?
                ORDER BY item_id, occurred_at DESC, id DESC
            ) l
            LEFT JOIN items i ON i.id = l.item_id
            WHERE l.to_status IN ({$placeholders})
            ORDER BY l.item_id
        SQL, array_merge([$shopId, $endExclusive], self::STOCK_STATUSES)));
    }
}
