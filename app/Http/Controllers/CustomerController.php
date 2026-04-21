<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\LoyaltyTransaction;
use App\Models\CustomerGoldTransaction;
use App\Http\Concerns\RespondsDynamically;
use App\Services\PosSearchCacheService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    use RespondsDynamically;

    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $isRetailer = auth()->user()->shop?->isRetailer();

        $query = Customer::where('shop_id', $shopId);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                  ->orWhere('last_name', 'ilike', "%{$search}%")
                  ->orWhere('mobile', 'like', "%{$search}%");
            });
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
            'withEmail', 'retailerInvoiceCount', 'pageGoldTotal'
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
            'mobile'           => ['nullable', 'string', 'digits:10', Rule::unique('customers', 'mobile')->where('shop_id', $shopId)],
            'address'          => 'nullable|string|max:1000',
            'email'            => 'nullable|email|max:255',
            'date_of_birth'    => 'nullable|date|before:today',
            'anniversary_date' => 'nullable|date',
            'wedding_date'     => 'nullable|date',
            'notes'            => 'nullable|string|max:2000',
        ], [
            'mobile.digits' => 'Mobile number must be exactly 10 digits.',
        ]);

        $data['mobile'] = $data['mobile'] ?? null;
        $data['shop_id'] = $shopId;

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

        return view('customers.show', compact(
            'customer', 'isRetailer', 'goldBalance', 'transactions',
            'loyaltyTransactions', 'invoices', 'totalSpent', 'hasRepairs'
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
            'mobile'           => ['nullable', 'string', 'digits:10', Rule::unique('customers', 'mobile')->ignore($customer->id)->where('shop_id', $shopId)],
            'address'          => 'nullable|string|max:1000',
            'email'            => 'nullable|email|max:255',
            'date_of_birth'    => 'nullable|date|before:today',
            'anniversary_date' => 'nullable|date',
            'wedding_date'     => 'nullable|date',
            'notes'            => 'nullable|string|max:2000',
        ], [
            'mobile.digits' => 'Mobile number must be exactly 10 digits.',
        ]);

        $data['mobile'] = $data['mobile'] ?? null;
        $customer->update($data);
        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        return redirect()->route('customers.show', $customer->id)
            ->with('success', 'Customer updated successfully.');
    }

    public function destroy(Customer $customer)
    {
        $this->authorize('delete', $customer);

        $shopId = auth()->user()->shop_id;

        $hasInvoices         = Invoice::where('customer_id', $customer->id)->where('shop_id', $shopId)->exists();
        $hasGoldTransactions = CustomerGoldTransaction::where('customer_id', $customer->id)->where('shop_id', $shopId)->exists();
        $hasRepairs          = $customer->repairs()->exists();

        if ($hasInvoices || $hasGoldTransactions || $hasRepairs) {
            return $this->dynamicRedirect('customers.show', [$customer], 'Cannot delete customer with existing invoices, gold transactions, or repairs.', 'error');
        }

        $name = $customer->name;
        $customer->delete();
        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        return $this->dynamicRedirect('customers.index', [], "Customer {$name} deleted successfully.");
    }
}
