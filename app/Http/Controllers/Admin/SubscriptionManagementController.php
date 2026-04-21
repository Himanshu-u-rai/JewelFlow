<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionManagementController extends Controller
{
    public function index(Request $request)
    {
        $query = ShopSubscription::with(['shop', 'plan'])
            ->select('shop_subscriptions.*')
            ->leftJoin('shops', 'shop_subscriptions.shop_id', '=', 'shops.id');

        // Filtering
        if ($request->filled('status')) {
            $query->where('shop_subscriptions.status', $request->status);
        }
        if ($request->filled('plan_id')) {
            $query->where('shop_subscriptions.plan_id', $request->plan_id);
        }
        if ($request->filled('q')) {
            $query->where('shops.name', 'ilike', '%' . $request->q . '%');
        }

        $subscriptions = $query->latest('shop_subscriptions.created_at')->paginate(25)->withQueryString();

        // Calculate computed properties
        $subscriptions->getCollection()->transform(function ($sub) {
            $sub->days_remaining = Carbon::now()->diffInDays($sub->ends_at, false);
            $sub->is_overdue = $sub->days_remaining < 0;
            $sub->is_trial_ending = $sub->status === 'trial' && $sub->days_remaining >= 0 && $sub->days_remaining <= 3;
            return $sub;
        });

        // Summary Stats
        $stats = DB::table('shop_subscriptions')
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $allPlans = Plan::orderBy('name')->get();

        return view('super-admin.subscriptions.index', compact('subscriptions', 'stats', 'allPlans'));
    }
}
