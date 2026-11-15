<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ReorderRule;
use App\Models\Reporting\ReportExport;
use App\Models\ShopPreferences;
use App\Models\User;
use App\Notifications\Reporting\ExportReadyNotification;
use App\Services\Reporting\ExportAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The one bell (owner request, 2026-11-14): the dashboard's warnings (repair
 * backlog, low stock, overdue EMIs), items needing a price review, the user's
 * finished exports ready to download, and every low-stock rule. Everyone who
 * could read a notice before still can; a row shows or links only where the
 * user may go. The app's Shop notices page shows the warnings and reorder
 * rules; pricing and downloads are for the app to follow.
 */
class ShopNoticeService
{
    private const WARNING_NOTES = [
        'repair_backlog' => 'Repairs not delivered yet',
        'low_stock' => 'Below your low-stock level',
        'overdue_emis' => 'Instalments past their due date',
    ];

    /**
     * @return array{warnings: list<array>, pricing: ?array, downloads: list<array>, reorder: list<array>, count: int, reorder_count: int, reorder_href: ?string}
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
                'overdue_emis' => $user->can('installments.view') ? route('installments.index', ['status' => 'active']) : null,
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

        // Items needing a price review: one row, however many items (the stock list's review drawer lists them).
        $pricingCount = self::pricingReviewCount($user);
        $pricing = $pricingCount > 0 ? [
            'title' => $pricingCount === 1 ? '1 item needs a price review' : "{$pricingCount} items need a price review",
            'meta' => 'Not repriced after the last rate change',
            'href' => route('inventory.items.index', ['alerts' => 'pricing']),
        ] : null;

        $downloads = self::readyDownloads($user);

        return [
            'warnings' => $warnings,
            'pricing' => $pricing,
            'downloads' => $downloads,
            'reorder' => $reorder,
            // The badge counts the rows the bell shows.
            'count' => count($warnings) + ($pricing ? 1 : 0) + count($downloads) + count($reorder),
            'reorder_count' => count($reorder),
            'reorder_href' => $isRetailer && $canStock ? route('reorder.index') : null,
        ];
    }

    /** In-stock items needing a price review, for retail users with stock access (0 otherwise). Web bell and app. */
    public static function pricingReviewCount(User $user): int
    {
        return $user->shop?->isRetailer() && $user->can('inventory.view')
            ? app(ShopPricingService::class)->pricingAlertCount((int) $user->shop_id)
            : 0;
    }

    /**
     * Finished background exports this user asked for and has not downloaded or cleared yet. A message, not a state:
     * downloading (the web's signed link or the app's token download) or clearing marks it read. A link past its
     * expiry is never offered. `href` is the web's signed link; the app downloads by `export_id`.
     *
     * @return list<array{title: string, meta: ?string, href: string, export_id: int, file_name: string, expires_at: ?string}>
     */
    public static function readyDownloads(User $user): array
    {
        $notes = app(ExportAuditService::class)->readyNotificationsFor($user)
            ->filter(fn ($note) => empty($note->data['expires_at']) || Carbon::parse($note->data['expires_at'])->isFuture())
            ->filter(fn ($note) => ! empty($note->data['download_url']) && ! empty($note->data['export_id']));
        $files = ReportExport::whereIn('id', $notes->map(fn ($note) => (int) $note->data['export_id']))->pluck('file_path', 'id');

        return $notes
            ->filter(fn ($note) => ! empty($files[(int) $note->data['export_id']]))
            ->map(fn ($note) => [
                'title' => Str::headline($note->data['report_key'] ?? 'Export') . ' is ready to download',
                'meta' => empty($note->data['expires_at']) ? null
                    : 'Link expires ' . Carbon::parse($note->data['expires_at'])->diffForHumans(),
                'href' => $note->data['download_url'],
                'export_id' => (int) $note->data['export_id'],
                'file_name' => basename((string) $files[(int) $note->data['export_id']]),
                'expires_at' => $note->data['expires_at'] ?? null,
            ])
            ->values()->all();
    }

    /** Marks this user's ready-download messages read (the bell's "Clear"); downloading one marks just that one. */
    public static function clearDownloads(User $user): void
    {
        $user->unreadNotifications()->where('type', ExportReadyNotification::class)->update(['read_at' => now()]);
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
