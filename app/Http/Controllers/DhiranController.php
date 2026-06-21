<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Dhiran\DhiranLoan;
use App\Models\Dhiran\DhiranLoanItem;
use App\Models\Dhiran\DhiranPayment;
use App\Models\Dhiran\DhiranSettings;
use Illuminate\Support\Facades\DB;
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
        $user = auth()->user();

        // Dhiran onboarding gate (Phase 3). A Dhiran-realm customer who hasn't
        // finished onboarding must be routed into the Dhiran-only flow, never the
        // ERP one:
        //   • no shop yet + a paid/pending Dhiran subscription → create the business
        //   • no shop yet + no subscription                    → choose the plan
        // (An ERP account never reaches here — realm:dhiran on this group bounces it.)
        if (! $user->shop_id) {
            if (\App\Services\OnboardingResumeService::findPendingSubscription($user)) {
                return redirect()->route('dhiran.onboarding');
            }

            return redirect()->route('dhiran.plans');
        }

        $shopId   = $user->shop_id;
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

    public function create(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);

        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        $customers = Customer::where('shop_id', $shopId)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'mobile']);

        // Preselect a borrower when arriving from the borrower profile / list
        // (?customer_id=). Only honoured if that customer belongs to this shop.
        $preselectedCustomerId = null;
        if ($request->filled('customer_id')) {
            $preselectedCustomerId = $customers->firstWhere('id', (int) $request->customer_id)?->id;
        }

        return view('dhiran.create', compact('settings', 'customers', 'preselectedCustomerId'));
    }

    /**
     * Create a borrower inline from the Dhiran New Loan page. Strictly scoped to
     * the current Dhiran shop: shop_id is NEVER taken from the request — it is set
     * by the Customer model's BelongsToShop hook from the tenant context (and we
     * pass the authenticated shop_id explicitly as belt-and-suspenders). A mobile
     * already used in THIS shop returns that existing customer instead of creating
     * a duplicate (the (shop_id, mobile) unique index also enforces this at the DB).
     */
    public function storeCustomer(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);
        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name'  => ['nullable', 'string', 'max:255'],
            'mobile'     => ['required', 'string', 'regex:/^[0-9]{10}$/'],
            'address'    => ['nullable', 'string', 'max:500'],
            'state_code' => ['nullable', 'string', 'max:10'],
            'pan'        => ['nullable', 'string', 'max:20'],
            'id_number'  => ['nullable', 'string', 'max:50'],
            'notes'      => ['nullable', 'string', 'max:1000'],
        ]);

        // Dedupe within THIS shop (BelongsToShop scope keeps the lookup tenant-local).
        $existing = Customer::where('mobile', $data['mobile'])->first();
        if ($existing) {
            return response()->json([
                'ok'        => true,
                'duplicate' => true,
                'message'   => 'A borrower with this mobile already exists in your shop — selected it.',
                'customer'  => [
                    'id'     => $existing->id,
                    'name'   => trim($existing->first_name . ' ' . ($existing->last_name ?? '')),
                    'mobile' => $existing->mobile,
                ],
            ]);
        }

        // shop_id and customer_code are set by the Customer model hooks (tenant
        // context + per-shop counter); state_code is not mass-assignable, so set it
        // via forceFill after create. We never read shop_id from the request.
        $customer = Customer::create([
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'] ?? null,
            'mobile'     => $data['mobile'],
            'address'    => $data['address'] ?? null,
            'pan'        => $data['pan'] ?? null,
            'id_number'  => $data['id_number'] ?? null,
            'notes'      => $data['notes'] ?? null,
        ]);

        if (! empty($data['state_code'])) {
            $customer->forceFill(['state_code' => $data['state_code']])->save();
        }

        return response()->json([
            'ok'       => true,
            'customer' => [
                'id'     => $customer->id,
                'name'   => trim($customer->first_name . ' ' . ($customer->last_name ?? '')),
                'mobile' => $customer->mobile,
            ],
        ], 201);
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
            // Aadhaar: accept a full 12-digit number OR an already-masked value, but
            // NEVER persist the full number (masked to last-4 before save below).
            'aadhaar'                  => ['nullable', 'string', 'max:20', 'regex:/^[0-9Xx\- ]{4,20}$/'],
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
            // Privacy: store only a masked Aadhaar (last 4). The full number is
            // never persisted, logged, or shown. PAN is kept as-is for now.
            'kyc_aadhaar'           => \App\Support\AadhaarMask::mask($data['aadhaar'] ?? null),
            'kyc_pan'               => $data['pan'] ?? null,
            'notes'                 => $data['notes'] ?? null,
            'created_by'            => auth()->id(),
            // Evidence gate: a loan created from the UI starts inactive until the
            // required photo + ID proof are uploaded and the owner activates it.
            'status'                => 'pending_evidence',
        ];

        try {
            $loan = $this->dhiranService->createLoan(auth()->user()->shop, $customer, $items, $params);
        } catch (\LogicException $e) {
            return back()->withErrors(['loan' => $e->getMessage()])->withInput();
        }

        return redirect()->route('dhiran.show', $loan)
            ->with('success', 'Loan created. Upload the pledged-item photo and borrower ID proof, then activate the loan.');
    }

    /**
     * Activate a pending-evidence loan once the required evidence is uploaded.
     * The service re-checks the evidence (authoritative guard); this is not a
     * UI-only gate.
     */
    public function activateLoan(Request $request, DhiranLoan $loan)
    {
        abort_unless($loan->status === 'pending_evidence', 422, 'This loan is not awaiting evidence.');

        try {
            $this->dhiranService->activateLoan($loan);
        } catch (\LogicException $e) {
            return redirect()->route('dhiran.show', $loan)->with('error', $e->getMessage());
        }

        return redirect()->route('dhiran.show', $loan)->with('success', 'Loan activated.');
    }

    /* ================================================================
     *  LOAN LISTING & DETAIL
     * ================================================================ */

    public function loans(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['pending_evidence', 'active', 'closed', 'renewed', 'forfeited'])],
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

        // Evidence attachments for this loan, its pledged items, and its borrower
        // (all tenant-scoped via BelongsToShop). Grouped for the view.
        $itemIds = $loan->items->pluck('id')->all();
        $attachments = \App\Models\Dhiran\DhiranAttachment::query()
            ->where(function ($q) use ($loan, $itemIds) {
                $q->where(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)->where('owner_id', $loan->id))
                  ->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)->whereIn('owner_id', $itemIds ?: [0]));
                if ($loan->customer_id) {
                    $q->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_CUSTOMER)->where('owner_id', $loan->customer_id));
                }
            })
            ->latest()
            ->get();

        // Evidence gate status for the checklist + activation button.
        $evidence = $this->dhiranService->evidenceStatus($loan);

        return view('dhiran.show', compact('loan', 'attachments', 'evidence'));
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

    /* ================================================================
     *  EVIDENCE ATTACHMENTS (private, shop-scoped) — Phase E2/E3
     * ================================================================ */

    /**
     * Upload a Dhiran evidence file (pledged-item photo, borrower ID proof, or
     * loan document). The owner (loan/item/customer) is verified to belong to the
     * current shop; shop_id is taken from the auth shop, never the request. Files
     * land on the private disk via DhiranAttachmentService.
     */
    public function storeAttachment(Request $request)
    {
        $shopId   = auth()->user()->shop_id;
        $settings = DhiranSettings::getForShop($shopId);
        abort_unless($settings->is_enabled, 403, 'Dhiran module is not enabled.');

        $data = $request->validate([
            'owner_type'    => ['required', 'in:dhiran_loan,dhiran_loan_item,customer'],
            'owner_id'      => ['required', 'integer'],
            'document_type' => ['required', 'in:' . implode(',', \App\Models\Dhiran\DhiranAttachment::DOCUMENT_TYPES)],
            'file'          => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        // Verify the owner exists AND belongs to THIS shop (no raw-id trust).
        $this->assertOwnerInShop($data['owner_type'], (int) $data['owner_id'], $shopId);

        try {
            $attachment = app(\App\Services\Dhiran\DhiranAttachmentService::class)->store(
                $request->file('file'),
                $shopId,
                $data['owner_type'],
                (int) $data['owner_id'],
                $data['document_type'],
                auth()->id(),
            );
        } catch (\LogicException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', 'File uploaded.');
    }

    /**
     * Stream a Dhiran attachment after a shop + permission check. Never a public
     * URL: the file is read from the private disk and streamed inline. Route
     * binding is BelongsToShop-scoped; the explicit shop check is belt-and-suspenders.
     */
    public function showAttachment(\App\Models\Dhiran\DhiranAttachment $attachment)
    {
        abort_unless((int) $attachment->shop_id === (int) auth()->user()->shop_id, 404);

        $disk = \Illuminate\Support\Facades\Storage::disk($attachment->file_disk);
        abort_unless($disk->exists($attachment->file_path), 404);

        return $disk->response(
            $attachment->file_path,
            $attachment->original_name,
            ['Content-Type' => $attachment->mime_type ?: 'application/octet-stream'],
            'inline'
        );
    }

    /** Resolve+verify an attachment owner is in the current shop, or 404. */
    private function assertOwnerInShop(string $ownerType, int $ownerId, int $shopId): void
    {
        $model = match ($ownerType) {
            'dhiran_loan'      => DhiranLoan::find($ownerId),
            'dhiran_loan_item' => DhiranLoanItem::find($ownerId),
            'customer'         => Customer::find($ownerId),
            default            => null,
        };

        abort_if($model === null || (int) $model->shop_id !== $shopId, 404, 'Not found.');
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
     *  BORROWERS (customer profiles, Dhiran-scoped)
     * ================================================================ */

    /**
     * Borrowers index — only customers of the current shop who have at least one
     * Dhiran loan here. Shop isolation comes from BelongsToShop on Customer +
     * DhiranLoan (both auto-filtered by tenant context); the whereHas keeps the
     * list to actual borrowers (customers with a pledge loan in this shop).
     */
    public function borrowers(Request $request)
    {
        $request->validate(['search' => 'nullable|string|max:100']);
        $shopId = (int) auth()->user()->shop_id;

        $query = Customer::query()
            ->whereHas('dhiranLoans', fn ($q) => $q->where('shop_id', $shopId))
            ->withCount([
                'dhiranLoans as active_loans_count'        => fn ($q) => $q->where('status', 'active'),
                'dhiranLoans as pending_evidence_count'    => fn ($q) => $q->where('status', 'pending_evidence'),
                'dhiranLoans as closed_loans_count'        => fn ($q) => $q->where('status', 'closed'),
                'dhiranLoans as forfeited_loans_count'     => fn ($q) => $q->where('status', 'forfeited'),
            ]);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'ilike', "%{$s}%")
                  ->orWhere('last_name', 'ilike', "%{$s}%")
                  ->orWhere('mobile', 'ilike', "%{$s}%")
                  ->orWhere('customer_code', 'ilike', "%{$s}%");
            });
        }

        $borrowers = $query->orderBy('first_name')->paginate(20)->withQueryString();

        // Outstanding per borrower (active loans only), scoped to this shop.
        $outstanding = [];
        foreach ($borrowers as $b) {
            $outstanding[$b->id] = (float) DhiranLoan::where('customer_id', $b->id)
                ->whereIn('status', ['active', 'pending_evidence'])
                ->sum(DB::raw('outstanding_principal + outstanding_interest + outstanding_penalty'));
        }

        return view('dhiran.borrowers.index', compact('borrowers', 'outstanding'));
    }

    /**
     * Borrower profile — full Dhiran-scoped view of one borrower: details, KYC
     * documents, loan summary + table, recent payments, pledged-item history.
     */
    public function borrowerProfile(Customer $customer)
    {
        // Route binding is tenant-scoped via BelongsToShop; this is the explicit
        // belt-and-suspenders shop guard (rejects ERP + other-shop customers).
        abort_unless((int) $customer->shop_id === (int) auth()->user()->shop_id, 404);
        $shopId = (int) auth()->user()->shop_id;

        $loans = DhiranLoan::where('customer_id', $customer->id)
            ->with('items')
            ->latest()
            ->get();

        $loanIds = $loans->pluck('id')->all();
        $itemIds = $loans->flatMap(fn ($l) => $l->items->pluck('id'))->all();

        // Counts + totals (read straight off the loans; no new financial formulas).
        $summary = [
            'active'          => $loans->where('status', 'active')->count(),
            'pending_evidence' => $loans->where('status', 'pending_evidence')->count(),
            'closed'          => $loans->where('status', 'closed')->count(),
            'renewed'         => $loans->where('status', 'renewed')->count(),
            'forfeited'       => $loans->where('status', 'forfeited')->count(),
            'principal_outstanding' => (float) $loans->sum(fn ($l) => (float) $l->outstanding_principal),
            'interest_outstanding'  => (float) $loans->sum(fn ($l) => (float) $l->outstanding_interest),
            'total_collected'       => (float) $loans->sum(fn ($l) => (float) $l->total_principal_collected + (float) $l->total_interest_collected),
        ];

        // Recent payments across this borrower's loans (this shop only).
        $payments = DhiranPayment::whereIn('dhiran_loan_id', $loanIds ?: [0])
            ->with('loan:id,loan_number')
            ->latest('payment_date')
            ->latest('id')
            ->limit(25)
            ->get();

        // KYC / evidence documents: borrower-owned + this borrower's loans + items.
        $attachments = \App\Models\Dhiran\DhiranAttachment::query()
            ->where(function ($q) use ($customer, $loanIds, $itemIds) {
                $q->where(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_CUSTOMER)->where('owner_id', $customer->id))
                  ->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)->whereIn('owner_id', $loanIds ?: [0]))
                  ->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)->whereIn('owner_id', $itemIds ?: [0]));
            })
            ->latest('id')
            ->get();

        // Item ids that have a photo (for the "photo" indicator on the items list).
        $itemsWithPhoto = $attachments
            ->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)
            ->where('document_type', 'item_photo')
            ->pluck('owner_id')->unique()->all();

        // Masked Aadhaar / PAN, if any loan captured KYC. Aadhaar is already stored
        // masked; never show a full number.
        $kycAadhaar = $loans->pluck('kyc_aadhaar')->filter()->first();
        $kycPan     = $loans->pluck('kyc_pan')->filter()->first();

        return view('dhiran.borrowers.show', compact(
            'customer', 'loans', 'summary', 'payments', 'attachments', 'itemsWithPhoto', 'kycAadhaar', 'kycPan'
        ));
    }

    /* ================================================================
     *  PLEDGED ITEM DETAIL
     * ================================================================ */

    /**
     * One pledged item: details, valuation (stored values only), evidence,
     * linked borrower + loan, and a simple status history. Shop-scoped.
     */
    public function itemDetail(DhiranLoanItem $item)
    {
        // Route binding is tenant-scoped via BelongsToShop; explicit backstop.
        abort_unless((int) $item->shop_id === (int) auth()->user()->shop_id, 404);

        $item->load(['loan.customer']);
        $loan = $item->loan;

        // Evidence: attachments owned by this item, plus loan-level item photos /
        // valuation proofs / loan documents that belong to the same loan (we never
        // fake item ownership — loan-owned rows are shown as loan documents).
        $attachments = \App\Models\Dhiran\DhiranAttachment::query()
            ->where(function ($q) use ($item, $loan) {
                $q->where(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_ITEM)->where('owner_id', $item->id));
                if ($loan) {
                    $q->orWhere(fn ($w) => $w->where('owner_type', \App\Models\Dhiran\DhiranAttachment::OWNER_LOAN)
                        ->where('owner_id', $loan->id)
                        ->whereIn('document_type', ['item_photo', 'valuation_proof', 'loan_document']));
                }
            })
            ->latest('id')
            ->get();

        return view('dhiran.items.show', compact('item', 'loan', 'attachments'));
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
