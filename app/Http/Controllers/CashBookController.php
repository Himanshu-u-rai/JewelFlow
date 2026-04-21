<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\AuditLog;
use App\Services\SubscriptionGateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashBookController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = CashTransaction::where('shop_id', $shopId)
            ->with('invoice')
            ->orderBy('created_at', 'desc');

        // Date filtering — validate format before use.
        if ($request->filled('from_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->from_date)) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->to_date)) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        // Type filtering
        if ($request->filled('type') && in_array($request->type, ['in', 'out'])) {
            $query->where('type', $request->type);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                  ->orWhere('source_type', 'ilike', "%{$search}%");
            });
        }

        $transactions = $query->paginate(25)->withQueryString();

        // Stats — 2 aggregate queries instead of 4.
        $today = now()->toDateString();

        $todayStats = CashTransaction::where('shop_id', $shopId)
            ->whereDate('created_at', $today)
            ->selectRaw("
                SUM(CASE WHEN type = 'in'  THEN amount ELSE 0 END) as today_in,
                SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END) as today_out
            ")
            ->first();

        $monthStats = CashTransaction::where('shop_id', $shopId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->selectRaw("
                SUM(CASE WHEN type = 'in'  THEN amount ELSE 0 END) as month_in,
                SUM(CASE WHEN type = 'out' THEN amount ELSE 0 END) as month_out
            ")
            ->first();

        $stats = [
            'today_in'  => (float) ($todayStats->today_in  ?? 0),
            'today_out' => (float) ($todayStats->today_out ?? 0),
            'month_in'  => (float) ($monthStats->month_in  ?? 0),
            'month_out' => (float) ($monthStats->month_out ?? 0),
        ];

        return view('cashbook.index', compact('transactions', 'stats'));
    }

    public function create()
    {
        return view('cashbook.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'        => 'required|in:in,out',
            'amount'      => 'required|numeric|min:0.01',
            'source_type' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $shopId = auth()->user()->shop_id;
        $userId = auth()->id();
        SubscriptionGateService::assertShopWritable((int) $shopId);

        $transaction = CashTransaction::record([
            'shop_id'     => $shopId,
            'user_id'     => $userId,
            'type'        => $validated['type'],
            'amount'      => $validated['amount'],
            'source_type' => $validated['source_type'],
            'source_id'   => null,
            'description' => $validated['description'] ?? null,
        ]);

        AuditLog::create([
            'shop_id'    => $shopId,
            'user_id'    => $userId,
            'action'     => 'cash_' . $validated['type'],
            'model_type' => 'CashTransaction',
            'model_id'   => $transaction->id,
            'description' => "Manual cash {$validated['type']}: ₹{$validated['amount']} - {$validated['source_type']}",
        ]);

        return redirect()->route('cashbook.index')
            ->with('success', 'Cash transaction recorded successfully.');
    }
}
