<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ReorderRule;
use App\Models\ShopPreferences;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The header bell's notices: the dashboard's warnings (repair backlog, low
 * stock, overdue EMIs) and every low-stock rule — the set the app's Shop
 * notices page shows. Everyone who could read them on the dashboard still
 * can; a notice links only where the user may go.
 */
class ShopNoticeService
{
    private const WARNING_NOTES = [
        'repair_backlog' => 'Repairs not delivered yet',
        'low_stock' => 'Below your low-stock level',
        'overdue_emis' => 'Instalments past their due date',
    ];

    /**
     * @return array{warnings: list<array>, reorder: list<array>, count: int, reorder_count: int, reorder_href: ?string}
     */
    public static function forUser(User $user): array
    {
        $shopId = (int) $user->shop_id;
        $isRetailer = (bool) $user->shop?->isRetailer();
        $canStock = $user->can('inventory.view');

        $warnings = array_map(fn (array $warning): array => $warning + [
            'note' => self::WARNING_NOTES[$warning['key']] ?? null,
            'href' => match ($warning['key']) {
                'repair_backlog' => $user->can('repairs.view') ? route('repairs.index') : null,
                'low_stock' => $canStock ? route('inventory.items.index') : null,
                'overdue_emis' => $user->can('sales.view') ? route('installments.index', ['status' => 'active']) : null,
                default => null,
            },
        ], self::warnings($shopId, $isRetailer));

        $alerts = $isRetailer ? app(ReorderAlertService::class)->cachedAlerts($shopId) : [];
        $categoryIds = $canStock && $alerts
            ? Category::where('shop_id', $shopId)->pluck('id', 'normalized_name')
            : collect();

        $reorder = array_map(fn (array $alert): array => [
            'title' => $alert['category']
                ? $alert['category'] . ($alert['sub_category'] ? ' · ' . $alert['sub_category'] : '')
                : 'All stock',
            'meta' => "{$alert['current_stock']} in stock · reorder level {$alert['threshold']}"
                . ($alert['vendor_name'] ? " · {$alert['vendor_name']}" : ''),
            'href' => $canStock ? self::stockLink($alert, $categoryIds) : null,
        ], $alerts);

        return [
            'warnings' => $warnings,
            'reorder' => $reorder,
            'count' => count($warnings) + count($reorder),
            'reorder_count' => count($reorder),
            'reorder_href' => $isRetailer && $canStock ? route('reorder.index') : null,
        ];
    }

    /** Cached like the dashboard's own figures (5 minutes). */
    private static function warnings(int $shopId, bool $isRetailer): array
    {
        return Cache::remember("shop_notice_warnings_{$shopId}", 300, fn () => DashboardMetricsService::warnings(
            DashboardMetricsService::noticeCounts($shopId, $isRetailer),
            (int) (ShopPreferences::where('shop_id', $shopId)->value('low_stock_threshold') ?? 20),
        ));
    }

    /** The in-stock items the rule counts; a category with no Category row opens the rules instead. */
    private static function stockLink(array $alert, $categoryIds): string
    {
        if (! $alert['category']) {
            return route('inventory.items.index');
        }

        $id = $categoryIds[ReorderRule::normalizeName($alert['category'])] ?? null;
        if (! $id) {
            return route('reorder.index');
        }

        return route('inventory.items.index', array_filter([
            'category' => $id,
            'sub_category' => $alert['sub_category'],
        ]));
    }
}
