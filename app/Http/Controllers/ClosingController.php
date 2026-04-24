<?php

namespace App\Http\Controllers;

use App\Models\MetalMovement;
use App\Models\Invoice;
use App\Models\CashTransaction;
use App\Models\InvoicePayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ClosingController extends Controller
{
    public function index()
    {
        $shopId = auth()->user()->shop_id;

        // Validate date — reject anything that isn't YYYY-MM-DD.
        $dateInput = request('date', now()->toDateString());
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateInput) ? $dateInput : now()->toDateString();

        // GOLD — single aggregate instead of three separate queries.
        $goldAgg = MetalMovement::where('shop_id', $shopId)
            ->whereDate('created_at', $date)
            ->whereIn('type', [
                'buyback', 'customer_advance', 'repair_return', 'old_metal_in',
                'sale', 'manufacture', 'repair_issue', 'wastage',
            ])
            ->selectRaw("
                SUM(CASE WHEN type IN ('buyback','customer_advance','repair_return','old_metal_in') THEN fine_weight ELSE 0 END) as gold_in,
                SUM(CASE WHEN type IN ('sale','manufacture','repair_issue') THEN fine_weight ELSE 0 END) as gold_out,
                SUM(CASE WHEN type = 'wastage' THEN fine_weight ELSE 0 END) as wastage
            ")
            ->first();

        $goldIn  = (float) ($goldAgg->gold_in  ?? 0);
        $goldOut = (float) ($goldAgg->gold_out ?? 0);
        $wastage = (float) ($goldAgg->wastage  ?? 0);

        // CASH — single aggregate for all invoice figures.
        $invoiceAgg = Invoice::where('shop_id', $shopId)
            ->where('status', Invoice::STATUS_FINALIZED)
            ->whereDate('created_at', $date)
            ->selectRaw('SUM(total) as sales, SUM(gst) as gst, SUM(discount) as discount, COUNT(*) as invoice_count')
            ->first();

        $sales        = round((float) ($invoiceAgg->sales        ?? 0), 2);
        $gst          = round((float) ($invoiceAgg->gst          ?? 0), 2);
        $discount     = round((float) ($invoiceAgg->discount     ?? 0), 2);
        $invoiceCount = (int) ($invoiceAgg->invoice_count ?? 0);

        $repairs = CashTransaction::where('shop_id', $shopId)
            ->where('type', 'repair')
            ->whereDate('created_at', $date)
            ->sum('amount');

        // Payment mode breakdown — join avoids the N+1 subquery from whereHas.
        $paymentBreakdown = InvoicePayment::join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->where('invoice_payments.shop_id', $shopId)
            ->where('invoices.status', Invoice::STATUS_FINALIZED)
            ->whereDate('invoices.created_at', $date)
            ->select('invoice_payments.mode', DB::raw('SUM(invoice_payments.amount) as total'))
            ->groupBy('invoice_payments.mode')
            ->pluck('total', 'mode');

        // Last 7 days trend ending on selected report date (retail analytics widgets).
        $selectedDate = Carbon::parse($date);
        $trendStart = $selectedDate->copy()->subDays(6)->startOfDay();
        $trendEnd = $selectedDate->copy()->endOfDay();

        $invoiceTrend = Invoice::where('shop_id', $shopId)
            ->where('status', Invoice::STATUS_FINALIZED)
            ->whereBetween('created_at', [$trendStart, $trendEnd])
            ->selectRaw('DATE(created_at) as day, SUM(total) as sales, SUM(discount) as discount, COUNT(*) as invoice_count')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $repairTrend = CashTransaction::where('shop_id', $shopId)
            ->where('type', 'repair')
            ->whereBetween('created_at', [$trendStart, $trendEnd])
            ->selectRaw('DATE(created_at) as day, SUM(amount) as repairs')
            ->groupBy('day')
            ->pluck('repairs', 'day');

        $collectionsTrend = [];
        for ($i = 6; $i >= 0; $i--) {
            $pointDate = $selectedDate->copy()->subDays($i)->toDateString();
            $invoicePoint = $invoiceTrend->get($pointDate);

            $salesValue = (float) ($invoicePoint->sales ?? 0);
            $discountValue = (float) ($invoicePoint->discount ?? 0);
            $invoiceCountValue = (int) ($invoicePoint->invoice_count ?? 0);
            $repairsValue = (float) ($repairTrend[$pointDate] ?? 0);

            $collectionsTrend[] = [
                'date' => $pointDate,
                'label' => Carbon::parse($pointDate)->format('d M'),
                'day' => Carbon::parse($pointDate)->format('D'),
                'sales' => round($salesValue, 2),
                'repairs' => round($repairsValue, 2),
                'discount' => round($discountValue, 2),
                'invoice_count' => $invoiceCountValue,
                'total' => round($salesValue + $repairsValue, 2),
            ];
        }

        return view('report_closing', compact(
            'date',
            'goldIn',
            'goldOut',
            'wastage',
            'sales',
            'repairs',
            'gst',
            'discount',
            'invoiceCount',
            'paymentBreakdown',
            'collectionsTrend'
        ));
    }
}
