<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashDrawerCheck;
use App\Models\CashTransaction;
use App\Reporting\LedgerService;
use App\Reporting\ReportPeriod;
use App\Services\SubscriptionGateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile v1 — Cash Book (Phase 4).
 *
 * Per-mode money-on-hand balances, the cash ledger, manual cash entries, and
 * the "match your drawer" check. Mirrors the web cashbook exactly — same
 * canonical balance engine (LedgerService::cashFlowByMode), same write path
 * (CashTransaction::record / CashDrawerCheck::record). No separate mobile
 * calculation engine, no stored balances, no edit/delete.
 *
 * Accounting safety is enforced by the service/model layer and DB triggers
 * (append-only cash_transactions + cash_drawer_checks). This controller only
 * authorizes, shapes input, and formats output. The mobile.envelope middleware
 * wraps responses; mobile.idempotency protects the POST mutations.
 */
class CashBookController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    private const MODES = ['cash', 'upi', 'bank', 'card', 'wallet', 'other'];

    /**
     * GET /api/mobile/v1/cashbook
     *
     * Per-mode money on hand over the date window (defaults to current month),
     * plus a paginated ledger of cash entries (each with its payment mode).
     */
    public function index(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $fromDate = $this->validDate($request->input('from_date'));
        $toDate   = $this->validDate($request->input('to_date'));

        $modeFilter = in_array($request->input('payment_mode'), self::MODES, true)
            ? $request->input('payment_mode') : null;

        // --- Per-mode "money on hand" (canonical engine; computed, not stored) ---
        $perMode = $this->ledger->cashFlowByMode($shopId, ReportPeriod::range($fromDate, $toDate));

        // --- Ledger list (filtered like the web page) ---
        $query = CashTransaction::where('shop_id', $shopId)
            ->with('invoice:id,invoice_number')
            ->orderByDesc('created_at');

        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }
        if (in_array($request->input('type'), ['in', 'out'], true)) {
            $query->where('type', $request->input('type'));
        }
        if ($modeFilter) {
            if ($modeFilter === 'cash') {
                $query->where(fn ($q) => $q->where('payment_mode', 'cash')->orWhereNull('payment_mode'));
            } else {
                $query->where('payment_mode', $modeFilter);
            }
        }
        if ($request->filled('search')) {
            // Cap the search term: it feeds an unbounded ilike, so bound the
            // input defensively (mobile is a public-facing surface).
            $search = mb_substr((string) $request->input('search'), 0, 100);
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ilike', "%{$search}%")
                  ->orWhere('source_type', 'ilike', "%{$search}%");
            });
        }

        $paginator = $query->cursorPaginate((int) min(50, max(1, (int) $request->input('per_page', 20))));

        return response()->json([
            'money_on_hand' => [
                'cash'  => $this->presentMode($perMode->cash()),
                'modes' => $perMode->modes->map(fn ($m) => $this->presentMode($m))->values(),
                'total' => [
                    'opening'  => $perMode->totalOpening,
                    'money_in' => $perMode->totalIn,
                    'money_out'=> $perMode->totalOut,
                    'closing'  => $perMode->totalClosing,
                ],
            ],
            'ledger'     => $paginator->map(fn ($t) => $this->presentTxn($t))->values(),
            'pagination' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'page_size'   => $paginator->perPage(),
                'has_more'    => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * POST /api/mobile/v1/cashbook
     *
     * Manual cash entry — same write path as the web cashbook.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type'         => 'required|in:in,out',
            'amount'       => 'required|numeric|min:0.01',
            'source_type'  => 'required|string|max:100',
            'payment_mode' => 'nullable|in:cash,upi,bank,card,wallet,other',
            'description'  => 'nullable|string|max:500',
        ]);

        $shopId = (int) $request->user()->shop_id;
        $userId = (int) $request->user()->id;
        SubscriptionGateService::assertShopWritable($shopId);

        $txn = CashTransaction::record([
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
            'shop_id'     => $shopId,
            'user_id'     => $userId,
            'action'      => 'cash_' . $validated['type'],
            'model_type'  => 'CashTransaction',
            'model_id'    => $txn->id,
            'description' => "Mobile cash {$validated['type']}: ₹{$validated['amount']} - {$validated['source_type']}",
        ]);

        return response()->json($this->presentTxn($txn->fresh('invoice')), 201);
    }

    /**
     * GET /api/mobile/v1/cashbook/drawer-check
     *
     * Today's expected cash in hand + recent drawer counts (read-only).
     */
    public function drawerContext(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $expected = round((float) $this->ledger
            ->cashFlowByMode($shopId, ReportPeriod::day(now()->toDateString()))
            ->cash()->closing, 2);

        $recent = CashDrawerCheck::where('shop_id', $shopId)
            ->with('checkedBy:id,name')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn ($c) => $this->presentDrawerCheck($c))
            ->values();

        return response()->json([
            'expected_cash' => $expected,
            'recent_checks' => $recent,
        ]);
    }

    /**
     * POST /api/mobile/v1/cashbook/drawer-check
     *
     * Record a physical cash count. Expected is computed server-side; the
     * client only sends what it counted. Append-only snapshot.
     */
    public function storeDrawerCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'counted_cash' => 'required|numeric|min:0|max:99999999.99',
            'note'         => 'nullable|string|max:500',
        ]);

        $shopId = (int) $request->user()->shop_id;
        $userId = (int) $request->user()->id;
        SubscriptionGateService::assertShopWritable($shopId);

        $expected = round((float) $this->ledger
            ->cashFlowByMode($shopId, ReportPeriod::day(now()->toDateString()))
            ->cash()->closing, 2);

        $counted    = round((float) $validated['counted_cash'], 2);
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
            'description' => "Mobile drawer check: counted ₹{$counted} vs expected ₹{$expected} (difference ₹{$difference})",
        ]);

        return response()->json($this->presentDrawerCheck($check->fresh('checkedBy')), 201);
    }

    // ── presenters ──────────────────────────────────────────────────────

    private function presentMode(object $m): array
    {
        return [
            'mode'      => $m->mode,
            'opening'   => (float) $m->opening,
            'money_in'  => (float) $m->moneyIn,
            'money_out' => (float) $m->moneyOut,
            'closing'   => (float) $m->closing,
        ];
    }

    private function presentTxn(CashTransaction $t): array
    {
        return [
            'id'           => (int) $t->id,
            'type'         => (string) $t->type,
            'amount'       => (float) $t->amount,
            'payment_mode' => $t->payment_mode ?: 'cash', // NULL → cash, defensive
            'source_type'  => $t->source_type,
            'invoice_number' => $t->invoice?->invoice_number,
            'description'  => $t->description,
            'created_at'   => optional($t->created_at)->toIso8601String(),
        ];
    }

    private function presentDrawerCheck(CashDrawerCheck $c): array
    {
        return [
            'id'            => (int) $c->id,
            'business_date' => optional($c->business_date)->toDateString(),
            'expected_cash' => (float) $c->expected_cash,
            'counted_cash'  => (float) $c->counted_cash,
            'difference'    => (float) $c->difference,
            'status'        => abs((float) $c->difference) < 0.01 ? 'matched' : ((float) $c->difference > 0 ? 'over' : 'short'),
            'note'          => $c->note,
            'checked_by'    => $c->checkedBy?->name,
            'created_at'    => optional($c->created_at)->toIso8601String(),
        ];
    }

    private function validDate($value): ?string
    {
        return (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) ? $value : null;
    }
}
