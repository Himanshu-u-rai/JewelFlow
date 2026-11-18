<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\OnboardingBatch;
use App\Models\ShopPreferences;
use App\Services\DashboardMetricsService;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index()
    {
        $shopId = auth()->user()->shop_id;
        $today = now()->toDateString();
        $cacheKey = "dashboard:{$shopId}:{$today}";
        $cacheTtl = (int) config('performance.cache.dashboard_ttl_seconds', 300);

        $data = Cache::remember(
            $cacheKey,
            now()->addSeconds($cacheTtl),
            fn () => DashboardMetricsService::build((int) $shopId)
        );

        $preferences = ShopPreferences::where('shop_id', $shopId)->first();
        $data['lowStockThreshold'] = $preferences->low_stock_threshold ?? 20;

        // First-run opening-balance prompt (owner-only): shown until the owner
        // either migrates (a non-cancelled batch exists), starts clean (flag set),
        // or has real sales. Cheap exists() checks, gated behind the owner check.
        $data['showOnboardingPrompt'] = false;
        if (auth()->user()->isOwner()) {
            $hasBatch = OnboardingBatch::where('status', '!=', OnboardingBatch::STATUS_CANCELLED)->exists();
            $startedClean = (bool) ($preferences?->opening_setup_skipped_at);
            $hasSales = Invoice::where('status', Invoice::STATUS_FINALIZED)->exists();
            $data['showOnboardingPrompt'] = ! $hasBatch && ! $startedClean && ! $hasSales;
        }
        // Owner-only nudge to lock the books (links to the lock screen; never locks anything itself).
        $data['lockReminder'] = auth()->user()->isShopOwner()
            ? \App\Services\LockReminderService::forShop((int) $shopId)
            : null;
        $data['topCustomers'] = $data['topCustomers'] ?? collect();
        $data['monthlyRevenueTrend'] = $data['monthlyRevenueTrend'] ?? collect();
        $data['reorderAlertCount'] = (int) ($data['reorderAlertCount'] ?? 0);

        return view('dashboard', $data);
    }

    /** "Not now" on the lock reminder: hidden for the rest of this month, per shop. Owner only. */
    public function dismissLockReminder()
    {
        abort_unless(auth()->user()->isShopOwner(), 403, \App\Support\NotAvailable::MESSAGE);
        \App\Services\LockReminderService::dismiss((int) auth()->user()->shop_id);

        return redirect()->route('dashboard');
    }
}
