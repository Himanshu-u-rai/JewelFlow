<?php

namespace App\Http\Controllers;

use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\SchemeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SchemeController extends Controller
{
    public function __construct(
        protected SchemeService $schemeService
    ) {}

    // ─── Scheme CRUD ───

    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        // Stats over the full dataset (not filtered by type) so the cards
        // always reflect totals for the shop, not just the current page.
        $stats = Scheme::where('shop_id', $shopId)
            ->selectRaw("
                count(*) filter (where type = 'gold_savings') as gold_savings_count,
                count(*) filter (where type in ('festival_sale','discount_offer')) as offers_count,
                count(*) filter (where is_active IS TRUE) as active_count
            ")
            ->first();

        $query = Scheme::where('shop_id', $shopId);

        if ($request->filled('search')) {
            $term = trim((string) $request->search);
            $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

            $query->where(function ($schemeQuery) use ($term, $operator) {
                $schemeQuery
                    ->where('name', $operator, "%{$term}%")
                    ->orWhere('description', $operator, "%{$term}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $schemes = $query->withCount('enrollments')->latest()->paginate(15)->withQueryString();

        return view('schemes.index', compact('schemes', 'stats'));
    }

    public function create()
    {
        return view('schemes.create');
    }

    public function store(Request $request)
    {
        $data = $this->validateSchemePayload($request);

        $data['is_active'] = $data['is_active'] ?? true;
        $data = $this->normalizeSchemeData($data);

        Scheme::create($data);

        return redirect()->route('schemes.index')
            ->with('success', 'Scheme created successfully.');
    }

    public function show(Scheme $scheme)
    {
        $this->authorize('view', $scheme);

        $enrollments = $scheme->enrollments()
            ->with('customer')
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('schemes.show', compact('scheme', 'enrollments'));
    }

    public function edit(Scheme $scheme)
    {
        $this->authorize('update', $scheme);

        return view('schemes.edit', compact('scheme'));
    }

    public function update(Request $request, Scheme $scheme)
    {
        $this->authorize('update', $scheme);

        $data = $this->validateSchemePayload($request);

        $data['is_active'] = $request->has('is_active');
        $data = $this->normalizeSchemeData($data);

        $scheme->update($data);

        return redirect()->route('schemes.show', $scheme)
            ->with('success', 'Scheme updated.');
    }

    public function destroy(Scheme $scheme)
    {
        $this->authorize('delete', $scheme);

        $hasLiveEnrollments = $scheme->enrollments()
            ->whereIn('status', ['active', 'matured'])
            ->exists();

        if ($hasLiveEnrollments) {
            return back()->with('error', 'Cannot delete a scheme that has active or matured enrollments. Deactivate it instead.');
        }

        $scheme->delete();

        return redirect()->route('schemes.index')->with('success', 'Scheme deleted.');
    }

    // ─── Enrollments ───

    public function enrollForm(Scheme $scheme)
    {
        $this->authorize('enroll', $scheme);

        $customers = Customer::where('shop_id', auth()->user()->shop_id)
            ->orderBy('first_name')
            ->get();

        return view('schemes.enroll', compact('scheme', 'customers'));
    }

    public function enroll(Request $request, Scheme $scheme)
    {
        $this->authorize('enroll', $scheme);

        $data = $request->validate([
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'monthly_amount' => 'required|numeric|min:100',
            'notes' => 'nullable|string',
            'accept_terms' => 'required|accepted',
        ]);

        $customer = Customer::findOrFail($data['customer_id']);

        try {
            $enrollment = $this->schemeService->enroll(
                $scheme,
                $customer,
                (float) $data['monthly_amount'],
                $data['notes'] ?? null,
                true
            );
        } catch (\LogicException $e) {
            return back()->withErrors(['scheme' => $e->getMessage()])->withInput();
        }

        return redirect()->route('schemes.enrollment.show', $enrollment)
            ->with('success', 'Customer enrolled successfully.');
    }

    public function enrollmentShow(SchemeEnrollment $enrollment)
    {
        $this->authorize('view', $enrollment);

        $enrollment->load([
            'scheme',
            'customer',
            'payments' => fn ($q) => $q->latest('payment_date'),
            'redemptions' => fn ($q) => $q->with('invoice:id,invoice_number')->latest('redeemed_at'),
            'ledgerEntries' => fn ($q) => $q->latest('id')->limit(50),
        ]);

        $redeemableValue = $this->schemeService->redeemableValue($enrollment);
        $ledgerBalance = (float) ($enrollment->ledgerEntries->first()->balance_after ?? 0);

        $eligibleInvoices = Invoice::query()
            ->where('shop_id', $enrollment->shop_id)
            ->where('customer_id', $enrollment->customer_id)
            ->where('status', Invoice::STATUS_FINALIZED)
            ->withSum('payments as paid_amount', 'amount')
            ->latest()
            ->get(['id', 'invoice_number', 'total', 'created_at'])
            ->map(function (Invoice $invoice) {
                $invoice->paid_amount = (float) ($invoice->paid_amount ?? 0);
                $invoice->outstanding_amount = max(0, (float) $invoice->total - $invoice->paid_amount);
                return $invoice;
            })
            ->filter(fn (Invoice $invoice) => $invoice->outstanding_amount > 0)
            ->values();

        return view('schemes.enrollment-show', compact(
            'enrollment',
            'redeemableValue',
            'ledgerBalance',
            'eligibleInvoices'
        ));
    }

    public function recordPayment(Request $request, SchemeEnrollment $enrollment)
    {
        $this->authorize('update', $enrollment);

        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|in:cash,upi,card,bank_transfer',
            'receipt_number' => 'nullable|string|max:50',
            'notes' => 'nullable|string',
        ]);

        try {
            $this->schemeService->recordPayment(
                $enrollment,
                (float) $data['amount'],
                $data['payment_method'],
                $data['receipt_number'] ?? null,
                $data['notes'] ?? null
            );
        } catch (\LogicException $e) {
            return redirect()->route('schemes.enrollment.show', $enrollment)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('schemes.enrollment.show', $enrollment)
            ->with('success', 'Payment recorded.');
    }

    public function redeemToInvoice(Request $request, SchemeEnrollment $enrollment)
    {
        $this->authorize('update', $enrollment);

        $shopId = auth()->user()->shop_id;

        $data = $request->validate([
            'invoice_id' => [
                'required',
                'integer',
                Rule::exists('invoices', 'id')->where('shop_id', $shopId),
            ],
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:500',
        ]);

        $invoice = Invoice::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $enrollment->customer_id)
            ->findOrFail((int) $data['invoice_id']);

        try {
            $redemption = $this->schemeService->applyRedemptionToInvoice(
                $enrollment,
                $invoice,
                (float) $data['amount'],
                $data['note'] ?? null
            );
        } catch (\LogicException $e) {
            return redirect()->route('schemes.enrollment.show', $enrollment)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('invoices.show', $invoice)
            ->with('success', 'Scheme redemption of ₹' . number_format((float) $redemption->amount, 2) . ' applied successfully.');
    }

    // ─── Helpers ───

    private function validateSchemePayload(Request $request): array
    {
        return $request->validate([
            'name'                 => 'required|string|max:255',
            'type'                 => 'required|in:gold_savings,festival_sale,discount_offer',
            'description'          => 'nullable|string|max:1000',
            'start_date'           => 'required|date',
            'end_date'             => 'nullable|date|after_or_equal:start_date',
            'discount_type'        => 'nullable|in:percentage,flat',
            'discount_value'       => 'nullable|numeric|min:0',
            'max_discount_amount'  => 'nullable|numeric|min:0',
            'min_purchase_amount'  => 'nullable|numeric|min:0',
            'total_installments'   => 'nullable|integer|min:1|max:36',
            'bonus_month_value'    => 'nullable|numeric|min:0',
            'is_active'            => 'boolean',
            'auto_apply'           => 'boolean',
            'priority'             => 'nullable|integer|min:1|max:1000',
            'stackable'            => 'boolean',
            'applies_to'           => 'nullable|in:all_items,category,sub_category',
            'applies_to_value'     => 'nullable|string|max:255',
            'max_uses_per_customer' => 'nullable|integer|min:1|max:9999',
            'terms'                => 'nullable|string|max:2000',
        ]);
    }

    private function normalizeSchemeData(array $data): array
    {
        $data['auto_apply'] = (bool) ($data['auto_apply'] ?? false);
        $data['stackable']  = (bool) ($data['stackable'] ?? false);
        $data['priority']   = (int) ($data['priority'] ?? 100);
        $data['applies_to'] = $data['applies_to'] ?? 'all_items';

        if ($data['applies_to'] === 'all_items') {
            $data['applies_to_value'] = null;
        }

        if (($data['type'] ?? '') === 'gold_savings') {
            $data['discount_type']       = null;
            $data['discount_value']      = null;
            $data['max_discount_amount'] = null;
            $data['min_purchase_amount'] = null;
            $data['auto_apply']          = false;
            $data['applies_to']          = 'all_items';
            $data['applies_to_value']    = null;
        }

        return $data;
    }
}
