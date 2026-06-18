<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\CashDrawerCheck;
use App\Models\AuditLog;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Services\SubscriptionGateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashBookController extends Controller
{
    public function index(Request $request, LedgerService $ledger)
    {
        $shopId = auth()->user()->shop_id;

        $fromDate = $request->filled('from_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->from_date)
            ? $request->from_date : null;
        $toDate = $request->filled('to_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->to_date)
            ? $request->to_date : null;

        $modeFilter = $request->filled('payment_mode')
            && in_array($request->payment_mode, ['cash', 'upi', 'bank', 'card', 'wallet', 'other'], true)
            ? $request->payment_mode : null;

        $query = CashTransaction::where('shop_id', $shopId)
            ->with('invoice')
            ->orderBy('created_at', 'desc');

        // Date filtering — validated above.
        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        // Type filtering
        if ($request->filled('type') && in_array($request->type, ['in', 'out'])) {
            $query->where('type', $request->type);
        }

        // Payment-mode filtering (NULL treated as cash so the cash filter still
        // catches legacy/untagged rows).
        if ($modeFilter) {
            if ($modeFilter === 'cash') {
                $query->where(function ($q) {
                    $q->where('payment_mode', 'cash')->orWhereNull('payment_mode');
                });
            } else {
                $query->where('payment_mode', $modeFilter);
            }
        }

        // Search — cap the term defensively (feeds an unbounded ilike).
        if ($request->filled('search')) {
            $search = mb_substr((string) $request->input('search'), 0, 100);
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                  ->orWhere('source_type', 'ilike', "%{$search}%");
            });
        }

        $transactions = $query->paginate(25)->withQueryString();

        // Per-mode "Money on Hand" over the same date window as the table,
        // via the canonical reporting balance engine (LedgerService) — computed
        // from immutable cash_transactions, never stored. When no dates are
        // filtered, defaults to the current month (matches the month framing).
        $perMode = $ledger->cashFlowByMode($shopId, ReportPeriod::range($fromDate, $toDate));

        // Today's computed cash-in-hand — the figure to count the drawer against.
        $expectedCashToday = round((float) $ledger
            ->cashFlowByMode($shopId, ReportPeriod::day(now()->toDateString()))
            ->cash()->closing, 2);

        // Recent drawer checks (read-only history; append-only table).
        $recentDrawerChecks = CashDrawerCheck::where('shop_id', $shopId)
            ->with('checkedBy:id,name')
            ->latest('created_at')
            ->limit(5)
            ->get();

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

        return view('cashbook.index', compact(
            'transactions', 'stats', 'perMode', 'modeFilter',
            'expectedCashToday', 'recentDrawerChecks'
        ));
    }

    public function create()
    {
        return view('cashbook.create');
    }

    /**
     * Known manual-entry reasons, grouped by direction. The create form shows
     * the matching list for the chosen type; this is the server-side mirror so a
     * money-IN reason can never be saved against a money-OUT entry (or vice
     * versa). Free-text ("custom") reasons are not listed here and are allowed
     * for either direction.
     */
    private const IN_SOURCES = [
        'customer_payment', 'customer_advance', 'old_gold_sold',
        'loan_received', 'owner_investment', 'opening_balance', 'other_income',
    ];
    private const OUT_SOURCES = [
        'karigar_payment', 'gold_purchase', 'supplier_payment', 'salary', 'rent',
        'utility_bills', 'repair_charges', 'marketing_expense', 'petty_expense',
        'loan_repayment', 'owner_withdrawal', 'other_expense',
    ];

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'         => 'required|in:in,out',
            'amount'       => 'required|numeric|min:0.01',
            'source_type'  => 'required|string|max:100',
            'payment_mode' => 'nullable|in:cash,upi,bank,card,wallet,other',
            'description'  => 'nullable|string|max:500',
        ]);

        // Keep direction and reason consistent for the KNOWN reasons. A custom
        // (free-text) reason isn't in either list, so it's accepted as-is.
        $source = $validated['source_type'];
        $wrongForIn  = $validated['type'] === 'in'  && in_array($source, self::OUT_SOURCES, true);
        $wrongForOut = $validated['type'] === 'out' && in_array($source, self::IN_SOURCES, true);
        if ($wrongForIn || $wrongForOut) {
            return back()->withInput()->withErrors([
                'source_type' => 'That reason does not match a ' . ($validated['type'] === 'in' ? 'money-in' : 'money-out') . ' entry. Please pick a matching reason.',
            ]);
        }

        $shopId = auth()->user()->shop_id;
        $userId = auth()->id();
        SubscriptionGateService::assertShopWritable((int) $shopId);

        $transaction = CashTransaction::record([
            'shop_id'      => $shopId,
            'user_id'      => $userId,
            'type'         => $validated['type'],
            'amount'       => $validated['amount'],
            'source_type'  => $validated['source_type'],
            'source_id'    => null,
            'payment_mode' => $validated['payment_mode'] ?? 'cash',
            'description'  => $validated['description'] ?? null,
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

    /**
     * "Match your drawer" — record a physical cash count against the computed
     * cash-in-hand. Append-only snapshot; never touches cash_transactions. The
     * expected figure is computed server-side (never trusted from the client)
     * so the difference is meaningful. Multiple checks per day are allowed.
     */
    public function storeDrawerCheck(Request $request, LedgerService $ledger)
    {
        $validated = $request->validate([
            'counted_cash' => 'required|numeric|min:0|max:99999999.99',
            'note'         => 'nullable|string|max:500',
        ]);

        $shopId = auth()->user()->shop_id;
        $userId = auth()->id();
        SubscriptionGateService::assertShopWritable((int) $shopId);

        // Expected = today's computed cash-in-hand (current drawer balance).
        // Server-computed, never from the request.
        $expected = round((float) $ledger
            ->cashFlowByMode($shopId, ReportPeriod::day(now()->toDateString()))
            ->cash()->closing, 2);

        // For a same-day cash-in-hand we want closing including all of today's
        // movement plus prior balance. ReportPeriod::day gives today's window;
        // cash().closing already = opening(before today) + today in - today out.

        $counted = round((float) $validated['counted_cash'], 2);
        $difference = round($counted - $expected, 2);

        $check = CashDrawerCheck::record([
            'shop_id'            => $shopId,
            'business_date'      => now()->toDateString(),
            'expected_cash'      => $expected,
            'counted_cash'       => $counted,
            'difference'         => $difference,
            'note'               => $validated['note'] ?? null,
            'checked_by_user_id' => $userId,
        ]);

        AuditLog::create([
            'shop_id'     => $shopId,
            'user_id'     => $userId,
            'action'      => 'cash_drawer_check',
            'model_type'  => 'CashDrawerCheck',
            'model_id'    => $check->id,
            'description' => "Drawer check: counted ₹{$counted} vs expected ₹{$expected} (difference ₹{$difference})",
        ]);

        $msg = abs($difference) < 0.01
            ? 'Drawer matches. Counted ₹' . number_format($counted, 2) . ' equals expected cash in hand.'
            : ($difference > 0
                ? 'Saved. Drawer is OVER by ₹' . number_format($difference, 2) . ' (counted more than expected).'
                : 'Saved. Drawer is SHORT by ₹' . number_format(abs($difference), 2) . ' (counted less than expected).');

        return redirect()->route('cashbook.index')->with('drawer_check_result', $msg);
    }
}
