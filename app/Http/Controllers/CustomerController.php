<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\LoyaltyTransaction;
use App\Models\CustomerGoldTransaction;
use App\Http\Concerns\ArchivesParties;
use App\Http\Concerns\RespondsDynamically;
use App\Rules\IndianMobileRule;
use App\Rules\PanFormatRule;
use App\Services\ComplianceService;
use App\Services\PosSearchCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerController extends Controller
{
    use ArchivesParties, RespondsDynamically;

    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $isRetailer = auth()->user()->shop?->isRetailer();

        // MASTERS PART 3: the search filter is reused for the query AND the tab
        // counts, so the number on a tab always matches what clicking it shows.
        $search = $request->filled('search') ? $request->search : null;
        $applySearch = function ($q) use ($search) {
            if ($search !== null) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('first_name', 'ilike', "%{$search}%")
                        ->orWhere('last_name', 'ilike', "%{$search}%")
                        ->orWhere('mobile', 'like', "%{$search}%");
                });
            }

            return $q;
        };

        // Default is Active: archived customers are retained, not shown by
        // default. Anything unrecognised falls back to active rather than
        // silently widening the list.
        $status = in_array($request->input('status'), ['archived', 'all'], true)
            ? $request->input('status')
            : 'active';

        $statusCounts = $applySearch(Customer::where('shop_id', $shopId))
            ->selectRaw("
                count(*) as all_count,
                count(*) filter (where is_active IS TRUE) as active_count,
                count(*) filter (where is_active IS FALSE) as archived_count
            ")
            ->first();

        $query = $applySearch(Customer::where('shop_id', $shopId));

        if ($status === 'active') {
            $query->active();
        } elseif ($status === 'archived') {
            $query->archived();
        }

        // Eager-load counts/sums needed per row — avoids N+1 in the table.
        if ($isRetailer) {
            $query->withCount('invoices');
        } else {
            $query->withSum('goldTransactions', 'fine_gold');
        }

        $customers = $query->latest()->paginate(15)->withQueryString();

        // Stats computed via DB — not in-PHP collection methods on the current page.
        $totalCustomers = $customers->total(); // already known from paginator

        $withEmail = Customer::where('shop_id', $shopId)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->count();

        $retailerInvoiceCount = null;
        $pageGoldTotal = null;

        if ($isRetailer) {
            $retailerInvoiceCount = Invoice::where('shop_id', $shopId)->count();
        } else {
            // Sum only over the customers on this page (labeled "Gold on This Page").
            $pageGoldTotal = $customers->sum('gold_transactions_sum_fine_gold');
        }

        // Loyalty data for retailer inline view
        $loyaltyData = null;
        $installmentData = null;
        $occasionsData = null;

        if ($isRetailer) {
            // --- Loyalty ---
            $loyaltyQuery = Customer::where('shop_id', $shopId)
                ->where('loyalty_points', '>', 0);

            if ($request->filled('loyalty_search')) {
                $ls = $request->loyalty_search;
                $loyaltyQuery->where(function ($q) use ($ls) {
                    $q->where('first_name', 'ilike', "%{$ls}%")
                      ->orWhere('last_name', 'ilike', "%{$ls}%")
                      ->orWhere('mobile', 'like', "%{$ls}%");
                });
            }

            $loyaltyCustomers = $loyaltyQuery->orderByDesc('loyalty_points')->paginate(15, ['*'], 'loyalty_page');

            $totalPointsIssued = LoyaltyTransaction::where('shop_id', $shopId)
                ->where('type', 'earn')
                ->sum('points');
            $totalPointsRedeemed = LoyaltyTransaction::where('shop_id', $shopId)
                ->where('type', 'redeem')
                ->sum('points');

            $loyaltyData = compact('loyaltyCustomers', 'totalPointsIssued', 'totalPointsRedeemed');

            // --- EMI / Installments ---
            $installmentQuery = InstallmentPlan::where('shop_id', $shopId)
                ->with(['customer', 'invoice']);

            $installmentStatus = $request->input('emi_status', 'active');
            if ($installmentStatus) {
                $installmentQuery->where('status', $installmentStatus);
            }

            $installmentPlans = $installmentQuery->latest()->paginate(15, ['*'], 'emi_page');

            $overduePlans = InstallmentPlan::where('shop_id', $shopId)
                ->active()
                ->where('next_due_date', '<', now()->toDateString())
                ->count();

            // remaining_amount is a stored column — sum it in DB, not in PHP.
            $totalOutstanding = InstallmentPlan::where('shop_id', $shopId)
                ->where('status', 'active')
                ->sum('remaining_amount');

            $installmentData = compact('installmentPlans', 'overduePlans', 'totalOutstanding', 'installmentStatus');

            // --- Customer Occasions ---
            $daysAhead = (int) $request->input('occasion_days', 30);

            $occasionCustomers = Customer::where('shop_id', $shopId)
                ->where(function ($q) {
                    $q->whereNotNull('date_of_birth')
                      ->orWhereNotNull('anniversary_date')
                      ->orWhereNotNull('wedding_date');
                })
                ->select('id', 'first_name', 'last_name', 'mobile', 'date_of_birth', 'anniversary_date', 'wedding_date')
                ->get();

            $upcoming = [];
            foreach ($occasionCustomers as $oc) {
                $occasions = $oc->upcomingOccasions($daysAhead);
                foreach ($occasions as $occasion) {
                    $upcoming[] = array_merge($occasion, [
                        'customer_id' => $oc->id,
                        'customer_name' => $oc->name,
                        'mobile' => $oc->mobile,
                    ]);
                }
            }

            usort($upcoming, fn($a, $b) => $a['days_until'] <=> $b['days_until']);

            $occasionsData = compact('upcoming', 'daysAhead');
        }

        return view('customers.index', compact(
            'customers', 'loyaltyData', 'installmentData', 'occasionsData',
            'withEmail', 'retailerInvoiceCount', 'pageGoldTotal',
            'status', 'statusCounts', 'search'
        ));
    }

    public function create()
    {
        return view('customers.create');
    }

    /**
     * Create a customer from a single typed name — used by the repair form's
     * inline "add this customer" flow. Splits the name on the first space and
     * skips mobile/address/etc. Returns JSON for AJAX callers.
     */
    public function quickStore(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $data = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $name = trim($data['name']);
        $parts = preg_split('/\s+/', $name, 2);
        $first = $parts[0] ?? $name;
        $last  = $parts[1] ?? null;

        $customer = Customer::create([
            'first_name' => $first,
            'last_name'  => $last,
        ]);

        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        return response()->json([
            'id'         => $customer->id,
            'first_name' => $customer->first_name,
            'last_name'  => $customer->last_name,
            'name'       => trim($customer->first_name . ' ' . ($customer->last_name ?? '')),
            'mobile'     => $customer->mobile,
        ]);
    }

    public function store(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $data = $request->validate([
            'first_name'       => 'required|string|max:255',
            'last_name'        => 'required|string|max:255',
            'mobile'           => ['nullable', 'string', new IndianMobileRule(), Rule::unique('customers', 'mobile')->where('shop_id', $shopId)],
            'address'          => 'nullable|string|max:1000',
            'email'            => 'nullable|email|max:255',
            'date_of_birth'    => 'nullable|date|before:today',
            'anniversary_date' => 'nullable|date',
            'wedding_date'     => 'nullable|date',
            'notes'            => 'nullable|string|max:2000',
        ], [
        ]);

        $data['mobile'] = $data['mobile'] ?? null;
        $data['shop_id'] = $shopId;

        // The duplicate check Rule::unique cannot do.
        //
        // Rule::unique above and the (shop_id, mobile) index both compare
        // STRINGS, so a legacy column holding '+91 98123 00099' does not clash
        // with an incoming '9812300099' and two rows for one human sail through.
        // resolveByMobile compares NUMBERS, which is why it is here — and why
        // the needle already being canonical (CanonicaliseMobileInput) is not
        // enough on its own. It is the column that may not be.
        //
        // This used to return a 409 {warning: 'possible_duplicate'} and let the
        // caller retry with confirm_duplicate=1, on the theory that a family can
        // share a phone. Nothing ever sent that flag: not the create form (a
        // plain <form> POST, so the operator got raw JSON painted on the page),
        // not the POS quick-add modal (its handler reads `message`, which the
        // 409 body did not have, so the toast said "Error: Error"). A soft gate
        // no client can pass is a hard gate with a broken error message, so it
        // is now the hard gate it always was — and one the operator can read.
        //
        // Note the shop check: resolveByMobile can surface another tenant's row,
        // and "already exists" must never leak across shops.
        if (! empty($data['mobile'])) {
            $existing = Customer::resolveByMobile($data['mobile']);

            if ($existing && $existing->shop_id === $shopId) {
                $name = trim($existing->first_name . ' ' . ($existing->last_name ?? ''));

                throw ValidationException::withMessages([
                    'mobile' => "This number is already saved for {$name}. Open that customer instead of creating a second one.",
                ]);
            }
        }

        $customer = Customer::create($data);
        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'id'         => $customer->id,
                'first_name' => $customer->first_name,
                'last_name'  => $customer->last_name,
                'mobile'     => $customer->mobile,
            ]);
        }

        return redirect()->route('customers.show', $customer->id);
    }

    public function show(Customer $customer)
    {
        $this->authorize('view', $customer);

        $shopId = auth()->user()->shop_id;
        $isRetailer = auth()->user()->shop?->isRetailer();

        // Gold balance & transactions — manufacturer only.
        $goldBalance = 0;
        $transactions = collect();
        if (!$isRetailer) {
            $goldBalance = CustomerGoldTransaction::where('shop_id', $shopId)
                ->where('customer_id', $customer->id)
                ->sum('fine_gold');

            $transactions = CustomerGoldTransaction::where('shop_id', $shopId)
                ->where('customer_id', $customer->id)
                ->latest()
                ->take(10)
                ->get();
        }

        // Loyalty transactions — retailer only.
        $loyaltyTransactions = collect();
        if ($isRetailer) {
            $loyaltyTransactions = $customer->loyaltyTransactions()
                ->with('invoice')
                ->latest()
                ->take(10)
                ->get();
        }

        // Recent invoices for the sidebar list.
        $invoices = Invoice::where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->latest()
            ->take(5)
            ->get();

        // Total spent — separate aggregate so it covers ALL invoices, not just the 5 shown.
        $totalSpent = Invoice::where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->sum('total');

        // Whether the customer can be safely deleted.
        $hasRepairs = $customer->repairs()->exists();

        // Opening money balance seeded at existing-shop onboarding (never a fake
        // invoice, so it never touched sales/GST). Net: positive = customer owes
        // the shop (receivable), negative = shop owes the customer (payable).
        $openingBalance = (float) \App\Models\CustomerOpeningBalance::where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'receivable' THEN amount ELSE -amount END), 0) as net")
            ->value('net');

        return view('customers.show', compact(
            'customer', 'isRetailer', 'goldBalance', 'transactions',
            'loyaltyTransactions', 'invoices', 'totalSpent', 'hasRepairs', 'openingBalance'
        ));
    }

    public function edit(Customer $customer)
    {
        $this->authorize('update', $customer);

        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $shopId = auth()->user()->shop_id;

        $data = $request->validate([
            'first_name'       => 'required|string|max:255',
            'last_name'        => 'required|string|max:255',
            'mobile'           => ['nullable', 'string', new IndianMobileRule(), Rule::unique('customers', 'mobile')->ignore($customer->id)->where('shop_id', $shopId)],
            'address'          => 'nullable|string|max:1000',
            'email'            => 'nullable|email|max:255',
            'date_of_birth'    => 'nullable|date|before:today',
            'anniversary_date' => 'nullable|date',
            'wedding_date'     => 'nullable|date',
            'notes'            => 'nullable|string|max:2000',
        ], [
        ]);

        $data['mobile'] = $data['mobile'] ?? null;
        $customer->update($data);
        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        return redirect()->route('customers.show', $customer->id)
            ->with('success', 'Customer updated successfully.');
    }

    /**
     * Verify a customer's KYC/compliance from the profile page (PAN + consent +
     * optional ID/address) — the same canonical engine the POS ₹2L high-value rule
     * uses. Lets a shop verify proactively, not only at checkout.
     */
    public function verifyCompliance(Request $request, Customer $customer)
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'pan'     => ['nullable', 'string', 'max:10', new PanFormatRule()],
            'aadhaar' => ['nullable', 'digits:12'],
            'mobile'  => ['nullable', new IndianMobileRule()],
            'address' => ['nullable', 'string', 'max:255'],
            'consent' => ['required', 'accepted'],
        ]);

        // Aadhaar persists in customers.id_number (its documented purpose).
        $errors = app(ComplianceService::class)->saveComplianceData($customer, [
            'pan'       => $validated['pan'] ?? null,
            'id_number' => $validated['aadhaar'] ?? null,
            'mobile'    => $validated['mobile'] ?? null,
            'address'   => $validated['address'] ?? null,
            'consent'   => $validated['consent'],
        ], (int) auth()->id());

        if (! empty($errors)) {
            return back()->withErrors($errors)->withInput();
        }

        Cache::forget(PosSearchCacheService::customersCacheKey((int) $customer->shop_id, null));

        return redirect()->route('customers.show', $customer->id)
            ->with('success', 'Customer KYC verified successfully.');
    }

    public function destroy(Customer $customer)
    {
        $this->authorize('delete', $customer);

        $shopId = auth()->user()->shop_id;

        $hasInvoices         = Invoice::where('customer_id', $customer->id)->where('shop_id', $shopId)->exists();
        $hasGoldTransactions = CustomerGoldTransaction::where('customer_id', $customer->id)->where('shop_id', $shopId)->exists();
        $hasRepairs          = $customer->repairs()->exists();

        if ($hasInvoices || $hasGoldTransactions || $hasRepairs) {
            // MASTERS PART 3: deleting would destroy the history those records
            // depend on, so we OFFER archive instead of doing it silently —
            // withdrawing a customer from new business is the user's call.
            return $this->dynamicRedirect('customers.show', [$customer], 'This customer has invoices, gold transactions, or repairs, so they cannot be deleted. Archive the customer instead to stop new transactions while keeping all history.', 'error');
        }

        $name = $customer->name;
        $customer->delete();
        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        return $this->dynamicRedirect('customers.index', [], "Customer {$name} deleted successfully.");
    }

    /**
     * MASTERS PART 3 — withdraw a customer from NEW transactions. Every existing
     * invoice, balance, EMI, scheme and repair stays exactly as it is and stays
     * settleable; the customer simply stops appearing in pickers.
     */
    public function archive(Request $request, Customer $customer)
    {
        $this->authorize('delete', $customer);

        $changed = $this->setPartyActive($request, $customer, false);
        Cache::forget(PosSearchCacheService::customersCacheKey((int) $customer->shop_id, null));

        return $changed
            ? $this->dynamicRedirect('customers.show', [$customer], "Customer {$customer->name} archived. All history is kept and you can reactivate them anytime.")
            : $this->dynamicRedirect('customers.show', [$customer], 'This customer is already archived.', 'error');
    }

    public function reactivate(Request $request, Customer $customer)
    {
        $this->authorize('delete', $customer);

        $changed = $this->setPartyActive($request, $customer, true);
        Cache::forget(PosSearchCacheService::customersCacheKey((int) $customer->shop_id, null));

        return $changed
            ? $this->dynamicRedirect('customers.show', [$customer], "Customer {$customer->name} reactivated and available for new transactions.")
            : $this->dynamicRedirect('customers.show', [$customer], 'This customer is already active.', 'error');
    }
}
