<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MetalMovement;
use Illuminate\Support\Facades\DB;

class PnlController extends Controller
{
    public function index()
    {
        $shopId = auth()->user()->shop_id;
        $date = request('date', now()->toDateString());

        // Total sales
        $sales = Invoice::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->sum('total');

        // Making charges
        $making = InvoiceItem::whereHas('invoice', function ($q) use ($shopId, $date) {
            $q->where('shop_id', $shopId)->whereDate('created_at', $date);
        })->sum('making_charges');

        // Stone charges
        $stones = InvoiceItem::whereHas('invoice', function ($q) use ($shopId, $date) {
            $q->where('shop_id', $shopId)->whereDate('created_at', $date);
        })->sum('stone_amount');

        // Gold sold (fine weight × rate)
        $goldSold = MetalMovement::where('shop_id', $shopId)
            ->where('type', 'sale')
            ->whereDate('created_at', $date)
            ->sum('fine_weight');

        $avgRate = Invoice::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->avg('gold_rate');

        $goldValue = $goldSold * ($avgRate ?? 0);

        // Wastage recovered from customers
        $wastageRecovered = Invoice::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->sum('wastage_charge');

        // Profit = making + stone + wastage recovered
        $profit = $making + $stones + $wastageRecovered;

        return view('report_pnl', compact(
            'date',  // Added this
            'sales',
            'goldValue',
            'making',
            'stones',
            'wastageRecovered',
            'profit'
        ));
    }
}