<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use App\Services\DhiranService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DhiranController extends Controller
{
    public function __construct(
        protected DhiranService $dhiranService
    ) {}

    /* ================================================================
     *  DASHBOARD & ACTIVATION
     * ================================================================ */

    public function dashboard()
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        if (! $settings->is_enabled) {
            return view('dhiran.activate', compact('settings'));
        }

        $activeLoans = DhiranLoan::where('shop_id', $shopId)->active()->count();

        $overdueLoans = DhiranLoan::where('shop_id', $shopId)->overdue()->count();

        $totalOutstanding = DhiranLoan::where('shop_id', $shopId)
            ->active()
            ->selectRaw('COALESCE(SUM(outstanding_principal + outstanding_interest + outstanding_penalty), 0) as total')
            ->value('total');

        $thisMonthInterest = (float) DhiranPayment::where('shop_id', $shopId)
            ->whereBetween('payment_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->sum('interest_component');

        $recentLoans = DhiranLoan::where('shop_id', $shopId)
            ->with('customer')
            ->latest()
            ->limit(10)
            ->get();

        return view('dhiran.dashboard', compact(
            'settings',
            'activeLoans',
            'overdueLoans',
            'totalOutstanding',
            'thisMonthInterest',
            'recentLoans'
        ));
    }

    public function activate(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        $settings->update(['is_enabled' => true]);

        return redirect()->route('dhiran.dashboard')
            ->with('success', 'Dhiran module activated successfully.');
    }

    /* ================================================================
     *  LOAN CRUD
     * ================================================================ */

    public function create()
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        $customers = Customer::where('shop_id', $shopId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'mobile']);

        return view('dhiran.create', compact('settings', 'customers'));
    }

    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $settings = DhiranSettings::getForShop($shopId);
        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        $data = $request->validate([
            'customer_id'              => ['required', 'integer', Rule::exists('customers', 'id')->where('shop_id', $shopId)],
            'principal_amount'         => 'required|numeric|min:1',
            'gold_rate_on_date'        => 'required|numeric|min:0',
            'silver_rate_on_date'      => 'nullable|numeric|min:0',
            'interest_rate_monthly'    => 'nullable|numeric|min:0|max:10',
            'interest_type'            => 'nullable|in:flat,daily,compound',
            'loan_date'                => 'nullable|date',
            'tenure_months'            => 'nullable|integer|min:1|max:60',
            'min_lock_months'          => 'nullable|integer|min:0|max:60',
            'grace_period_days'        => 'nullable|integer|min:0|max:90',
            'penalty_rate_monthly'     => 'nullable|numeric|min:0|max:10',
            'processing_fee'           => 'nullable|numeric|min:0',
            'processing_fee_type'      => 'nullable|in:flat,percent',
            'aadhaar'                  => 'nullable|string|max:20',
            'pan'                      => 'nullable|string|max:20',
            'notes'                    => 'nullable|string|max:1000',
            'items'                    => 'required|array|min:1',
            'items.*.description'      => 'required|string|max:255',
            'items.*.metal_type'       => 'nullable|in:gold,silver',
            'items.*.category'         => 'nullable|string|max:100',
            'items.*.quantity'         => 'nullable|integer|min:1|max:1000',
            'items.*.purity'           => 'required|numeric|min:0|max:24',
            'items.*.gross_weight'     => 'required|numeric|min:0|max:9999.999999',
            'items.*.stone_weight'     => 'nullable|numeric|min:0|max:9999.999999',
            'items.*.rate_per_gram_at_pledge' => 'required|numeric|min:0|max:999999.9999',
            'items.*.huid'             => 'nullable|string|max:50',
        ]);

        $customer = Customer::findOrFail($data['customer_id']);

        $items = array_map(
            fn ($i) => array_merge($i, ['metal_type' => $i['metal_type'] ?? 'gold']),
            $data['items']
        );

        $params = [
            'principal_amount'      => $data['principal_amount'],
            'gold_rate_on_date'     => $data['gold_rate_on_date'],
            'silver_rate_on_date'   => $data['silver_rate_on_date'] ?? null,
            'interest_rate_monthly' => $data['interest_rate_monthly'] ?? null,
            'interest_type'         => $data['interest_type'] ?? null,
            'loan_date'             => $data['loan_date'] ?? null,
            'tenure_months'         => $data['tenure_months'] ?? null,
            'min_lock_months'       => $data['min_lock_months'] ?? null,
            'grace_period_days'     => $data['grace_period_days'] ?? null,
            'penalty_rate_monthly'  => $data['penalty_rate_monthly'] ?? null,
            'processing_fee'        => $data['processing_fee'] ?? null,
            'processing_fee_type'   => $data['processing_fee_type'] ?? null,
            'kyc_aadhaar'           => $data['aadhaar'] ?? null,
            'kyc_pan'               => $data['pan'] ?? null,
            'notes'                 => $data['notes'] ?? null,
            'created_by'            => auth()->id(),
        ];

        try {
            $loan = $this->dhiranService->createLoan(auth()->user()->shop, $customer, $items, $params);
        } catch (\LogicException $e) {
            return back()->withErrors(['loan' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan created successfully.');
    }

    /* ================================================================
     *  LOAN LISTING & DETAIL
     * ================================================================ */

    public function loans(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['active', 'closed', 'renewed', 'forfeited'])],
            'search' => 'nullable|string|max:100',
        ]);

        $shopId = auth()->user()->shop_id;

        $query = DhiranLoan::where('shop_id', $shopId)
            ->with('customer');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('loan_number', 'ilike', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('first_name', 'ilike', "%{$search}%")
                         ->orWhere('last_name', 'ilike', "%{$search}%")
                         ->orWhere('mobile', 'ilike', "%{$search}%");
                  });
            });
        }

        $loans = $query->latest()->paginate(20)->withQueryString();

        return view('dhiran.loans', compact('loans'));
    }

    public function show(DhiranLoan $loan)
    {
        $loan->load([
            'customer',
            'items',
            'payments'  => fn ($q) => $q->latest('payment_date'),
            'renewedFrom',
            'renewals',
            'creator',
        ]);

        return view('dhiran.show', compact('loan'));
    }

    /* ================================================================
     *  PAYMENTS & ACTIONS
     * ================================================================ */

    public function payInterest(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'active', 422, 'Loan is not active.');

        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'nullable|in:cash,upi,card,bank_transfer',
        ]);

        try {
            $this->dhiranService->recordInterestPayment($loan, (float) $data['amount'], $data['payment_method'] ?? 'cash');
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Interest payment recorded.');
    }

    public function repay(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'active', 422, 'Loan is not active.');

        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'nullable|in:cash,upi,card,bank_transfer',
        ]);

        try {
            $this->dhiranService->recordPayment($loan, (float) $data['amount'], $data['payment_method'] ?? 'cash');
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Repayment recorded.');
    }

    public function releaseItem(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'active', 422, 'Loan is not active.');

        $data = $request->validate([
            'item_id'         => ['required', 'integer', Rule::exists('dhiran_loan_items', 'id')->where('dhiran_loan_id', $loan->id)],
            'payment_amount'  => 'required|numeric|min:0',
            'payment_method'  => 'nullable|in:cash,upi,card,bank_transfer',
            'condition_note'  => 'nullable|string|max:500',
        ]);

        $item = DhiranLoanItem::where('dhiran_loan_id', $loan->id)->findOrFail($data['item_id']);

        try {
            $this->dhiranService->releaseItem(
                $loan,
                $item,
                (float) $data['payment_amount'],
                $data['payment_method'] ?? 'cash',
                $data['condition_note'] ?? ''
            );
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Item released successfully.');
    }

    public function preClose(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'active', 422, 'Loan is not active.');

        $data = $request->validate([
            'payment_method' => 'nullable|in:cash,upi,card,bank_transfer',
        ]);

        try {
            $this->dhiranService->preCloseLoan($loan, $data['payment_method'] ?? 'cash');
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan pre-closed successfully.');
    }

    public function renew(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'active', 422, 'Loan is not active.');

        $data = $request->validate([
            'tenure_months'         => 'nullable|integer|min:1|max:60',
            'interest_rate_monthly' => 'nullable|numeric|min:0|max:10',
        ]);

        try {
            $newLoan = $this->dhiranService->renewLoan(
                $loan,
                isset($data['tenure_months']) ? (int) $data['tenure_months'] : null,
                isset($data['interest_rate_monthly']) ? (float) $data['interest_rate_monthly'] : null
            );
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $newLoan)
            ->with('success', 'Loan renewed successfully.');
    }

    public function close(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'active', 422, 'Loan is not active.');

        try {
            $this->dhiranService->closeLoan($loan);
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan closed successfully.');
    }

    /* ================================================================
     *  FORFEITURE
     * ================================================================ */

    public function sendNotice(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'active', 422, 'Loan is not active.');

        try {
            $this->dhiranService->sendForfeitureNotice($loan);
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Forfeiture notice sent.');
    }

    public function forfeit(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'active', 422, 'Loan is not active.');
        abort_unless($loan->forfeiture_notice_sent_at !== null, 422, 'Forfeiture notice must be sent first.');

        try {
            $this->dhiranService->executeForfeit($loan);
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan forfeited. Pledged items marked as forfeited.');
    }

    /* ================================================================
     *  PRINTABLE DOCUMENTS
     * ================================================================ */

    public function receipt(DhiranLoan $loan)
    {
        $loan->load(['customer', 'items', 'creator']);

        return view('dhiran.receipt', compact('loan'));
    }

    public function closureCertificate(DhiranLoan $loan)
    {
        abort_unless(in_array($loan->status, ['closed', 'forfeited']), 422, 'Loan is still active.');

        $loan->load(['customer', 'items', 'payments']);

        return view('dhiran.closure-certificate', compact('loan'));
    }

    public function forfeitureNotice(DhiranLoan $loan)
    {
        $loan->load(['customer', 'items']);

        return view('dhiran.forfeiture-notice', compact('loan'));
    }

    public function paymentReceipt(DhiranLoan $loan, DhiranPayment $payment)
    {
        abort_unless((int) $payment->dhiran_loan_id === (int) $loan->id, 404);

        $loan->load('customer');

        return view('dhiran.payment-receipt', compact('loan', 'payment'));
    }

    /* ================================================================
     *  CUSTOMER LOAN HISTORY
     * ================================================================ */

    public function customerLoans(Customer $customer)
    {
        // Defense-in-depth: route binding is tenant-scoped via BelongsToShop, this is a belt-and-suspenders guard.
        abort_unless((int) $customer->shop_id === (int) auth()->user()->shop_id, 403);

        $loans = DhiranLoan::where('customer_id', $customer->id)
            ->with('items')
            ->latest()
            ->paginate(15);

        return view('dhiran.customer-loans', compact('customer', 'loans'));
    }

    /* ================================================================
     *  SETTINGS
     * ================================================================ */

    public function settings()
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        return view('dhiran.settings', compact('settings'));
    }

    public function updateSettings(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $data = $request->validate([
            'default_interest_rate_monthly' => 'required|numeric|min:0|max:10',
            'default_tenure_months'         => 'required|integer|min:1|max:60',
            'default_min_lock_months'       => 'required|integer|min:0|max:60',
            'grace_period_days'             => 'required|integer|min:0|max:90',
            'default_penalty_rate_monthly'  => 'required|numeric|min:0|max:10',
            'default_ltv_ratio'             => 'required|numeric|min:1|max:100',
            'kyc_mandatory'                 => 'boolean',
            'sms_reminders_enabled'         => 'boolean',
            'reminder_days_before_due'      => 'nullable|integer|min:1|max:30',
            'loan_number_prefix'            => ['nullable', 'string', 'max:10', 'regex:/^[A-Za-z0-9\-_]{1,10}$/'],
        ]);

        $data['kyc_mandatory']        = $request->boolean('kyc_mandatory');
        $data['sms_reminders_enabled'] = $request->boolean('sms_reminders_enabled');

        $settings = DhiranSettings::getForShop($shopId);
        $settings->update($data);

        return redirect()->route('dhiran.settings')
            ->with('success', 'Settings updated.');
    }

    /* ================================================================
     *  REPORTS
     * ================================================================ */

    public function reports(Request $request)
    {
        $type = $request->input('type');

        return match ($type) {
            'active'        => $this->reportActive($request),
            'overdue'       => $this->reportOverdue($request),
            'interest'      => $this->reportInterest($request),
            'forfeiture'    => $this->reportForfeiture($request),
            'cashbook'      => $this->reportCashbook($request),
            'profitability' => $this->reportProfitability($request),
            default         => view('dhiran.reports.index'),
        };
    }

    public function reportActive(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $loans = DhiranLoan::where('shop_id', $shopId)
            ->active()
            ->with('customer')
            ->latest('loan_date')
            ->paginate(20)
            ->withQueryString();

        return view('dhiran.reports.active', compact('loans'));
    }

    public function reportOverdue(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $loans = DhiranLoan::where('shop_id', $shopId)
            ->overdue()
            ->with('customer')
            ->orderBy('maturity_date')
            ->paginate(20)
            ->withQueryString();

        return view('dhiran.reports.overdue', compact('loans'));
    }

    public function reportInterest(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        $shopId = auth()->user()->shop_id;

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        // Filter by payment_date (the accounting date for the transaction),
        // NOT created_at. A back-dated payment recorded today belongs in the
        // fiscal period of payment_date.
        $payments = DhiranPayment::where('shop_id', $shopId)
            ->where('interest_component', '>', 0)
            ->whereBetween('payment_date', [$from, $to])
            ->with(['loan', 'loan.customer'])
            ->latest('payment_date')
            ->paginate(20)
            ->withQueryString();

        $totalInterest = (float) DhiranPayment::where('shop_id', $shopId)
            ->whereBetween('payment_date', [$from, $to])
            ->sum('interest_component');

        return view('dhiran.reports.interest', compact('payments', 'totalInterest', 'from', 'to'));
    }

    public function reportForfeiture(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $loans = DhiranLoan::where('shop_id', $shopId)
            ->where('status', 'forfeited')
            ->with('customer')
            ->latest('forfeited_at')
            ->paginate(20)
            ->withQueryString();

        return view('dhiran.reports.forfeiture', compact('loans'));
    }

    public function reportCashbook(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        $shopId = auth()->user()->shop_id;

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        $entries = \App\Models\Dhiran\DhiranCashEntry::where('shop_id', $shopId)
            ->whereBetween('entry_date', [$from, $to])
            ->with('loan')
            ->orderBy('entry_date')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();

        return view('dhiran.reports.cashbook', compact('entries', 'from', 'to'));
    }

    public function reportProfitability(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        $shopId = auth()->user()->shop_id;

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to   = $request->input('to', now()->toDateString());

        $loans = DhiranLoan::where('shop_id', $shopId)
            ->whereBetween('loan_date', [$from, $to])
            ->with('customer')
            ->latest('loan_date')
            ->get();

        return view('dhiran.reports.profitability', compact('loans', 'from', 'to'));
    }
}
