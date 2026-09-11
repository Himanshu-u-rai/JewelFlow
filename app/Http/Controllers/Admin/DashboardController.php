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
            // The read-only KPI was one number described as "Writes blocked", which
            // merged two situations an admin must act on differently: a shop an
            // admin deliberately held (Contact Support, NOT self-recoverable), and a
            // shop restricted with no admin attribution at all.
            //
            // suspended_by is the authoritative administrative marker — it is exactly
            // Shop::suspensionIsAdministrative(), needs no joins, and every admin
            // write path stamps it while the subscription lifecycle never does.
            //
            // The other bucket is deliberately labelled "unattributed", NOT
            // "subscription lapse". Proving a lapse needs the ordered subscription-row
            // checks inside suspensionIsSubscriptionManaged(), and re-implementing
            // that fail-closed logic as a second SQL predicate is exactly how two
            // copies drift apart. The per-shop rows below use the real classifier;
            // this KPI stays coarse and honest.
            'shops_read_only_admin_hold' => Shop::where('access_mode', 'read_only')
                ->whereNotNull('suspended_by')
                ->count(),
            'shops_read_only_unattributed' => Shop::where('access_mode', 'read_only')
                ->whereNull('suspended_by')
                ->count(),
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
            // suspension_reason drives the Reason column in the view. Eloquent's
            // get([...]) restricts the SELECT list, and an unselected column reads
            // back as null with no error — which is why Reason rendered as an
            // em-dash for every row regardless of the stored reason.
            ->get(['id', 'name', 'phone', 'owner_mobile', 'suspended_at', 'suspended_by', 'suspension_reason', 'access_mode']);

        $readOnlyShops = Shop::query()
            ->where('access_mode', 'read_only')
            ->latest()
            ->take(6)
            // Same missing-column bug as the suspended table above. access_mode and
            // suspended_by are also needed because the view asks each row for its
            // real classification via Shop::suspensionIsSubscriptionManaged().
            ->get(['id', 'name', 'phone', 'owner_mobile', 'suspended_at', 'suspended_by', 'suspension_reason', 'access_mode']);

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
