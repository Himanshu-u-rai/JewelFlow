<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\Plan;
use App\Models\Platform\ShopSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function export(): StreamedResponse
    {
        $filename = 'subscriptions-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, [
                'ID', 'Shop', 'Plan', 'Status', 'Billing Cycle',
                'Price Paid', 'Starts At', 'Ends At', 'Created At',
            ]);

            ShopSubscription::with(['shop:id,name', 'plan:id,name'])
                ->orderBy('id')
                ->each(function (ShopSubscription $sub) use ($handle) {
                    fputcsv($handle, [
                        $sub->id,
                        $sub->shop?->name,
                        $sub->plan?->name,
                        $sub->status,
                        $sub->billing_cycle,
                        $sub->price_paid,
                        $sub->starts_at,
                        $sub->ends_at,
                        $sub->created_at?->toDateTimeString(),
                    ]);
                }, 500);

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
