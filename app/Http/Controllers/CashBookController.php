<?php

namespace App\Http\Controllers;

use App\Models\CashTransaction;
use App\Models\CashDrawerCheck;
use App\Models\ShopPaymentMethod;
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

        // Which of the shop's accounts (UPI / bank / wallet); rows before
        // 2026-10-25 carry none and only show under the mode filter.
        if ($request->filled('payment_method_id')) {
            $query->where('payment_method_id', (int) $request->input('payment_method_id'));
        }
        $accounts = ShopPaymentMethod::groupedActiveForShop((int) $shopId);

        // Search — cap the term defensively (feeds an unbounded ilike).
        if ($request->filled('search')) {
            $search = mb_substr((string) $request->input('search'), 0, 100);
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                  ->orWhere('source_type', 'ilike', "%{$search}%");
            });
        }

        $filtered = clone $query;
        $transactions = $query->paginate(25)->withQueryString();

        // A total for each day on this page, over the same filters (so a day
        // split across pages still adds up); shown as the list's day headings.
        $dayTotals = CashTransaction::dayTotals(
            $filtered,
            $transactions->getCollection()->map(fn ($t) => $t->created_at->toDateString())->unique()->values()->all(),
        );

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
        // Today's / this month's in and out (the snapshot strip); shared with the app.
        $stats = CashTransaction::snapshot((int) $shopId);

        return view('cashbook.index', compact(
            'transactions', 'stats', 'perMode', 'modeFilter',
            'expectedCashToday', 'recentDrawerChecks', 'accounts', 'dayTotals'
        ));
    }

    public function create()
    {
        return view('cashbook.create', [
            'accounts' => ShopPaymentMethod::groupedActiveForShop((int) auth()->user()->shop_id),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'         => 'required|in:in,out',
            'amount'       => 'required|numeric|min:0.01',
            'source_type'  => 'required|string|max:100',
            'payment_mode' => 'nullable|in:cash,upi,bank,card,wallet,other',
            'payment_method_id' => 'nullable|integer',
            'description'  => 'nullable|string|max:500',
        ]);

        // Keep direction and reason consistent for the KNOWN reasons (shared with
        // the mobile API via CashTransaction). A custom free-text reason passes.
        if (! CashTransaction::reasonMatchesType($validated['type'], $validated['source_type'])) {
            return back()->withInput()->withErrors([
                'source_type' => 'That reason does not match a ' . ($validated['type'] === 'in' ? 'money-in' : 'money-out') . ' entry. Please pick a matching reason.',
            ]);
        }

        $shopId = auth()->user()->shop_id;
        $userId = auth()->id();
        $mode = $validated['payment_mode'] ?? 'cash';
        $account = CashTransaction::resolveAccount((int) $shopId, $mode, $validated['payment_method_id'] ?? null);
        SubscriptionGateService::assertShopWritable((int) $shopId);

        $transaction = CashTransaction::record([
            'shop_id'      => $shopId,
            'user_id'      => $userId,
            'type'         => $validated['type'],
            'amount'       => $validated['amount'],
            'source_type'  => $validated['source_type'],
            'source_id'    => null,
            'payment_mode' => $mode,
            'payment_method_id' => $account?->id,
            'payment_method_label_snapshot' => $account?->account_label,
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
