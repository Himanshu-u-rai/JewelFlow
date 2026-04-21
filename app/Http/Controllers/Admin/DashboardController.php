<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Platform\PlatformAdmin;
use App\Models\Platform\PlatformAuditLog;
use App\Models\Platform\ShopSubscription;
use App\Models\ScanSession;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'shops_total' => Shop::count(),
            'shops_active' => Shop::where('access_mode', 'active')->count(),
            'shops_inactive' => Shop::whereIn('access_mode', ['read_only', 'suspended'])->count(),
            'shops_read_only' => Shop::where('access_mode', 'read_only')->count(),
            'shops_suspended' => Shop::where('access_mode', 'suspended')->count(),
            'shops_retail' => Shop::where('shop_type', 'retailer')->count(),
            'shops_manufacturer' => Shop::where('shop_type', 'manufacturer')->count(),
            'users_total' => User::count(),
            'users_active' => User::active()->count(),
            'users_inactive' => User::inactive()->count(),
            'super_admins' => PlatformAdmin::count(),
            'invoices_total' => Invoice::withoutTenant()->count(),
        ];

        $subscriptionCounts = ShopSubscription::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        $recentAudit = PlatformAuditLog::query()
            ->with('actor')
            ->orderByDesc('created_at')
            ->take(8)
            ->get();

        $failedAdminLogins = PlatformAuditLog::query()
            ->where('action', 'platform_admin.login_failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $activeScanSessionsByShop = ScanSession::query()
            ->join('shops', 'shops.id', '=', 'scan_sessions.shop_id')
            ->select(
                'shops.id as shop_id',
                'shops.name as shop_name',
                DB::raw('COUNT(scan_sessions.id) as active_sessions'),
                DB::raw('MAX(scan_sessions.expires_at) as latest_expiry')
            )
            ->where('scan_sessions.status', 'active')
            ->where('scan_sessions.expires_at', '>', now())
            ->groupBy('shops.id', 'shops.name')
            ->orderByDesc('active_sessions')
            ->limit(12)
            ->get();

        $scanThrottledAttemptsCount = PlatformAuditLog::query()
            ->where('action', 'scan.request_throttled')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $scanInvalidSignatureHitsCount = PlatformAuditLog::query()
            ->where('action', 'scan.invalid_signature')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $recentThrottledScanAttempts = PlatformAuditLog::query()
            ->where('action', 'scan.request_throttled')
            ->where('created_at', '>=', now()->subDay())
            ->latest('created_at')
            ->take(8)
            ->get();

        $suspendedShops = Shop::query()
            ->where('access_mode', 'suspended')
            ->latest()
            ->take(6)
            ->get(['id', 'name', 'phone', 'owner_mobile', 'suspended_at']);

        $readOnlyShops = Shop::query()
            ->where('access_mode', 'read_only')
            ->latest()
            ->take(6)
            ->get(['id', 'name', 'phone', 'owner_mobile']);

        $recentShops = Shop::latest()->take(8)->get();
        $recentUsers = User::with([
            'shop',
            'role' => fn ($query) => $query->withoutTenant(),
        ])
            ->latest()
            ->take(10)
            ->get();

        return view('super-admin.dashboard', compact(
            'stats',
            'subscriptionCounts',
            'recentAudit',
            'failedAdminLogins',
            'activeScanSessionsByShop',
            'scanThrottledAttemptsCount',
            'scanInvalidSignatureHitsCount',
            'recentThrottledScanAttempts',
            'suspendedShops',
            'readOnlyShops',
            'recentShops',
            'recentUsers'
        ));
    }
}
