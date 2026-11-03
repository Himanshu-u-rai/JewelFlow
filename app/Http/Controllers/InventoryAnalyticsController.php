<?php

namespace App\Http\Controllers;

use App\Services\RetailerReportService;
use Illuminate\Http\Request;

class InventoryAnalyticsController extends Controller
{
    public function __construct(private RetailerReportService $reports) {}

    public function aging()
    {
        return view('inventory.items.aging', [
            'stockAgingData' => $this->reports->stockAging(),
            'stockValueDisplay' => auth()->user()->shop->preferences?->stock_value_display ?? 'total',
        ]);
    }

    public function sellers(Request $request)
    {
        $period = $request->input('seller_period', '30');

        return view('inventory.items.sellers', [
            'sellersData' => [
                'best' => $this->reports->bestSellers(10, $period),
                'worst' => $this->reports->worstSellers(10, $period),
                'period' => $period,
            ],
        ]);
    }
}
