<?php

namespace App\Services;

use App\Support\FinancialYear;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * When the stock history of a shop became exact (owner-approved 2026-11-16).
 *
 * item_movements is exact only from the day the ledger was deployed; older stock was rebuilt from each piece's
 * created_at / updated_at (is_backfilled = true). `stock_history_starts.exact_from` stores that day once per shop, as
 * an Indian wall-clock timestamp (the same convention as item_movements.occurred_at). A shop with NO row was created
 * after the ledger existed, so its history is exact from creation. The value is written once and never moved.
 *
 * A stock figure "as at" a date is exact when that date's last second (23:59:59 IST) is not before exact_from;
 * otherwise it is reconstructed: usable, but to be checked against a physical count.
 */
final class StockHistory
{
    public const EXACT = 'exact';
    public const RECONSTRUCTED = 'reconstructed';

    public static function exactFrom(int $shopId): ?CarbonImmutable
    {
        $v = DB::table('stock_history_starts')->where('shop_id', $shopId)->value('exact_from');

        return $v === null ? null : CarbonImmutable::parse((string) $v, FinancialYear::TIMEZONE);
    }

    /** Is the stock as at 23:59:59 IST on $date (Y-m-d) built from exact history only? */
    public static function isExactAt(int $shopId, string $date): bool
    {
        $from = self::exactFrom($shopId);
        if ($from === null) {
            return true;
        }
        $end = CarbonImmutable::parse($date, FinancialYear::TIMEZONE)->setTime(23, 59, 59);

        return $from->lessThanOrEqualTo($end);
    }

    public static function basisForDate(int $shopId, string $date): string
    {
        return self::isExactAt($shopId, $date) ? self::EXACT : self::RECONSTRUCTED;
    }

    public static function basisForYear(int $shopId, FinancialYear $fy): string
    {
        return self::basisForDate($shopId, $fy->endDate());
    }

    /** The sentence shown wherever reconstructed figures appear (page warning and report notes). */
    public static function warning(int $shopId): string
    {
        $from = self::exactFrom($shopId);

        return 'Reconstructed: the app started keeping exact stock history on '
            . ($from ? $from->format('d M Y') : 'the day this shop was created')
            . '. Figures before then are rebuilt from each piece\'s added/updated dates and may be approximate. '
            . 'Check them against your physical stock count.';
    }

    /**
     * Migration step, kept in a class so a test can run the same SQL: every shop that has no row gets the earlier of
     * "now" and its first exact (non-backfilled) movement. Idempotent; an existing row is never changed.
     */
    public static function registerExistingShops(?CarbonImmutable $now = null): void
    {
        $now = ($now ?? CarbonImmutable::now())->setTimezone(FinancialYear::TIMEZONE)->format('Y-m-d H:i:s');
        DB::statement(<<<'SQL'
            INSERT INTO stock_history_starts (shop_id, exact_from, created_at)
            SELECT s.id,
                   LEAST(?::timestamp, COALESCE((SELECT MIN(m.created_at) FROM item_movements m
                                                 WHERE m.shop_id = s.id AND m.is_backfilled = false), ?::timestamp)),
                   ?::timestamp
            FROM shops s
            ON CONFLICT (shop_id) DO NOTHING
        SQL, [$now, $now, $now]);
    }
}
