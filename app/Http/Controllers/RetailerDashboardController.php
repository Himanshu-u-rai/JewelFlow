<?php

namespace App\Http\Controllers;

use App\Services\RetailerReportService;
use App\Models\Customer;
use App\Models\Item;
use Illuminate\Http\Request;

class RetailerDashboardController extends Controller
{
    public function __construct(
        protected RetailerReportService $reportService,
    ) {}

    /**
     * Stock aging report view.
     */
    public function stockAging()
    {
        $buckets = $this->reportService->stockAging();

        return view('retailer-reports.stock-aging', compact('buckets'));
    }

    /**
     * Best & worst sellers analytics.
     */
    public function sellers(Request $request)
    {
        $period = $request->input('period', '30');

        $best = $this->reportService->bestSellers(10, $period);
        $worst = $this->reportService->worstSellers(10, $period);

        return view('retailer-reports.sellers', compact('best', 'worst', 'period'));
    }

    /**
     * Customer occasion reminders.
     */
    public function occasions(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $daysAhead = (int) $request->input('days', 30);

        $customers = Customer::where('shop_id', $shopId)
            ->where(function ($q) {
                $q->whereNotNull('date_of_birth')
                  ->orWhereNotNull('anniversary_date')
                  ->orWhereNotNull('wedding_date');
            })
            ->get();

        $upcoming = [];
        foreach ($customers as $customer) {
            $occasions = $customer->upcomingOccasions($daysAhead);
            foreach ($occasions as $occasion) {
                $upcoming[] = array_merge($occasion, [
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'mobile' => $customer->mobile,
                ]);
            }
        }

        // Sort by days_until ascending
        usort($upcoming, fn($a, $b) => $a['days_until'] <=> $b['days_until']);

        return view('retailer-reports.occasions', compact('upcoming', 'daysAhead'));
    }

}
