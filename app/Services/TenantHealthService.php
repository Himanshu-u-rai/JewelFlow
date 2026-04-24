<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Invoice;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\ShopSubscription;
use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class TenantHealthService
{
    public function scores(?Carbon $reference = null): Collection
    {
        $reference = $reference ?: now();
        $recentStart = $reference->copy()->subDays(7)->startOfDay();

        $shops = Shop::query()
            ->select('id', 'name', 'shop_type', 'access_mode')
            ->orderBy('name')
            ->get();

        $latestSubscriptions = ShopSubscription::query()
            ->select('id', 'shop_id', 'status', 'ends_at', 'grace_ends_at')
            ->whereIn('id', function ($query) {
                $query->from('shop_subscriptions')
                    ->selectRaw('MAX(id)')
                    ->groupBy('shop_id');
            })
            ->get()
            ->keyBy('shop_id');

        $recentInvoices = Invoice::withoutTenant()
            ->selectRaw('shop_id, count(*) as count')
            ->where('created_at', '>=', $recentStart)
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $importFailures = Import::withoutTenant()
            ->selectRaw('shop_id, count(*) as count')
            ->where('status', Import::STATUS_FAILED)
            ->where('finished_at', '>=', $recentStart)
            ->groupBy('shop_id')
            ->pluck('count', 'shop_id');

        $billingBlocks = PlatformAuditLog::query()
            ->selectRaw('target_id as shop_id, count(*) as count')
            ->where('action', 'subscription.write_blocked')
            ->where('created_at', '>=', $recentStart)
            ->groupBy('target_id')
            ->pluck('count', 'shop_id');

        return $shops->map(function (Shop $shop) use ($latestSubscriptions, $recentInvoices, $importFailures, $billingBlocks) {
            $score = 100;
            $notes = [];

            $subscription = $latestSubscriptions[$shop->id] ?? null;
            $status = $subscription?->status ?? 'none';

            if (in_array($shop->access_mode, ['suspended'], true)) {
                $score -= 40;
                $notes[] = 'Shop suspended';
            } elseif ($shop->access_mode === 'read_only') {
                $score -= 20;
                $notes[] = 'Read-only mode';
            }

            if (!in_array($status, ['active', 'grace'], true)) {
                $score -= 25;
                $notes[] = 'Subscription not active';
            }

            $invoiceCount = (int) ($recentInvoices[$shop->id] ?? 0);
            if ($invoiceCount === 0) {
                $score -= 10;
                $notes[] = 'No invoices in 7 days';
            }

            $failedImports = (int) ($importFailures[$shop->id] ?? 0);
            if ($failedImports > 0) {
                $score -= 10;
                $notes[] = 'Import failures detected';
            }

            $blockedWrites = (int) ($billingBlocks[$shop->id] ?? 0);
            if ($blockedWrites > 0) {
                $score -= 10;
                $notes[] = 'Write blocks due to billing';
            }

            $score = max(0, min(100, $score));
            $band = $score >= 80 ? 'healthy' : ($score >= 50 ? 'warning' : 'critical');

            return [
                'shop' => $shop,
                'score' => $score,
                'band' => $band,
                'subscription_status' => $status,
                'notes' => $notes,
                'invoice_count' => $invoiceCount,
                'failed_imports' => $failedImports,
            ];
        });
    }
}
