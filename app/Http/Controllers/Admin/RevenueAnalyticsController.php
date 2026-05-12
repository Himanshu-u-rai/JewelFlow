<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Platform\ShopSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RevenueAnalyticsController extends Controller
{
    public function index(): View
    {
        $now = now();

        // MRR: sum of price_paid for all active subscriptions, normalised to monthly
        $activeSubs = ShopSubscription::query()
            ->where('status', 'active')
            ->get(['price_paid', 'billing_cycle']);

        $mrr = $activeSubs->sum(function ($sub) {
            return $sub->billing_cycle === 'yearly'
                ? round((float) $sub->price_paid / 12, 2)
                : (float) $sub->price_paid;
        });

        $arr = round($mrr * 12, 2);

        // Active shop count
        $activeShops = DB::table('shop_subscriptions')->where('status', 'active')->distinct('shop_id')->count('shop_id');

        // ARPU
        $arpu = $activeShops > 0 ? round($mrr / $activeShops, 2) : 0;

        // Churn: shops whose subscription moved to cancelled/expired this month
        $startOfMonth = $now->copy()->startOfMonth();
        $churned = DB::table('shop_subscriptions')
            ->whereIn('status', ['cancelled', 'expired'])
            ->where('updated_at', '>=', $startOfMonth)
            ->distinct('shop_id')->count('shop_id');

        $startActive = DB::table('shop_subscriptions')
            ->where('status', 'active')
            ->where('created_at', '<', $startOfMonth)
            ->distinct('shop_id')->count('shop_id');

        $churnRate = $startActive > 0 ? round(($churned / $startActive) * 100, 1) : 0;

        // Trial to paid conversion this month: trials started this month that converted to active
        $trialsStartedThisMonth = DB::table('shop_subscriptions')
            ->whereIn('status', ['trial', 'active', 'cancelled', 'expired', 'grace', 'read_only', 'suspended'])
            ->where('created_at', '>=', $startOfMonth)
            ->whereExists(function ($q) {
                $q->from('plans')->whereColumn('plans.id', 'shop_subscriptions.plan_id')
                  ->where('plans.trial_days', '>', 0);
            })
            ->count();

        $trialsPaidThisMonth = DB::table('shop_subscriptions')
            ->where('status', 'active')
            ->where('created_at', '>=', $startOfMonth)
            ->whereExists(function ($q) {
                $q->from('plans')->whereColumn('plans.id', 'shop_subscriptions.plan_id')
                  ->where('plans.trial_days', '>', 0);
            })
            ->count();

        $trialConversionRate = $trialsStartedThisMonth > 0
            ? round(($trialsPaidThisMonth / $trialsStartedThisMonth) * 100, 1)
            : null;

        // Monthly MRR trend (last 6 months)
        $mrrTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month      = $now->copy()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd   = $month->copy()->endOfMonth();

            $subsInMonth = DB::table('shop_subscriptions')
                ->where('status', 'active')
                ->where('starts_at', '<=', $monthEnd)
                ->where(function ($q) use ($monthStart) {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>=', $monthStart);
                })
                ->get(['price_paid', 'billing_cycle']);

            $monthMrr = $subsInMonth->sum(function ($sub) {
                return $sub->billing_cycle === 'yearly'
                    ? round((float) $sub->price_paid / 12, 2)
                    : (float) $sub->price_paid;
            });

            $mrrTrend[] = [
                'label' => $month->format('M Y'),
                'mrr'   => round($monthMrr, 2),
            ];
        }

        // New signups last 30 days
        $newSignups = DB::table('shops')->where('created_at', '>=', $now->copy()->subDays(30))->count();

        // Plan breakdown
        $planBreakdown = DB::table('shop_subscriptions')
            ->join('plans', 'shop_subscriptions.plan_id', '=', 'plans.id')
            ->where('shop_subscriptions.status', 'active')
            ->select('plans.name', DB::raw('count(*) as count'), DB::raw('sum(shop_subscriptions.price_paid) as revenue'))
            ->groupBy('plans.name')
            ->orderByDesc('revenue')
            ->get();

        return view('super-admin.revenue.index', compact(
            'mrr', 'arr', 'arpu', 'activeShops',
            'churnRate', 'churned', 'startActive',
            'trialConversionRate', 'trialsPaidThisMonth', 'trialsStartedThisMonth',
            'mrrTrend', 'newSignups', 'planBreakdown'
        ));
    }
}
