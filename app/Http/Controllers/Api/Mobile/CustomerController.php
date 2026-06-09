<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGoldTransaction;
use App\Models\Invoice;
use App\Models\KycDocument;
use App\Models\LoyaltyTransaction;
use App\Models\Repair;
use App\Models\ShopPreferences;
use App\Rules\PanFormatRule;
use App\Services\ComplianceService;
use App\Services\KycDocumentService;
use App\Services\PosSearchCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function search(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $results = PosSearchCacheService::customers($shopId, $request->input('search'));

        return response()->json($results);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->query('per_page', 25)), 100);
        $search  = trim((string) $request->query('search', ''));

        // Customer uses the BelongsToShop global scope, so this is auto-scoped to
        // the authenticated user's shop (TenantContext set by the `tenant`
        // middleware) — no cross-tenant rows can be returned.
        $query = Customer::query()->orderByDesc('updated_at');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', '%' . $search . '%')
                  ->orWhere('last_name', 'ilike', '%' . $search . '%')
                  ->orWhere('mobile', 'like', '%' . $search . '%')
                  ->orWhereRaw(
                      "CONCAT(first_name, ' ', COALESCE(last_name, '')) ILIKE ?",
                      ['%' . $search . '%']
                  );
            });
        }

        $paginated = $query->paginate($perPage);

        return response()->json($paginated);
    }

    public function store(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'mobile' => [
                'required', 'digits:10',
                Rule::unique('customers', 'mobile')->where('shop_id', $shopId),
            ],
        ]);

        $customer = Customer::create([
            'shop_id' => $shopId,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'] ?? null,
            'mobile' => $validated['mobile'],
        ]);

        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        return response()->json([
            'id' => $customer->id,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'customer_code' => $customer->customer_code,
            'message' => 'Customer added successfully.',
        ], 201);
    }

    public function context(Customer $customer, Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        if ((int) $customer->shop_id !== $shopId) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $recentRepairs = Repair::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->latest('created_at')
            ->limit(6)
            ->get([
                'id',
                'repair_number',
                'item_description',
                'status',
                'estimated_cost',
                'final_cost',
                'due_date',
                'created_at',
            ]);

        $recentInvoices = Invoice::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->latest('created_at')
            ->limit(6)
            ->get([
                'id',
                'invoice_number',
                'status',
                'total',
                'created_at',
            ]);

        $timeline = collect();

        foreach ($recentRepairs as $repair) {
            $timeline->push([
                'id' => 'repair-' . $repair->id,
                'type' => 'repair',
                'title' => 'Repair #' . $repair->repair_number,
                'subtitle' => $repair->item_description,
                'status' => $repair->status,
                'amount' => (float) ($repair->final_cost ?? $repair->estimated_cost ?? 0),
                'date' => optional($repair->created_at)?->toIso8601String(),
            ]);
        }

        foreach ($recentInvoices as $invoice) {
            $timeline->push([
                'id' => 'invoice-' . $invoice->id,
                'type' => 'invoice',
                'title' => 'Invoice ' . $invoice->invoice_number,
                'subtitle' => 'Sale invoice',
                'status' => $invoice->status,
                'amount' => (float) ($invoice->total ?? 0),
                'date' => optional($invoice->created_at)?->toIso8601String(),
            ]);
        }

        $timeline = $timeline
            ->sortByDesc('date')
            ->values()
            ->take(12);

        $totalInvoices = Invoice::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->count();

        $lifetimeSpend = (float) Invoice::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'cancelled')
            ->sum('total');

        $totalRepairs = Repair::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->count();

        $openRepairs = Repair::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->where('status', '!=', 'delivered')
            ->count();

        // Edition-gated history. Loyalty is a retailer concept, the customer gold
        // account a manufacturer concept. Gate on the canonical edition checks
        // (a shop may hold multiple editions; shops.shop_type is only a scalar).
        // LoyaltyTransaction / CustomerGoldTransaction use the BelongsToShop scope,
        // so querying by customer_id is already shop-scoped — no manual shop_id.
        $shop = $request->user()->shop;

        $loyaltyHistory = [];
        if ($shop?->isRetailer()) {
            $loyaltyHistory = LoyaltyTransaction::query()
                ->where('customer_id', $customer->id)
                ->latest('created_at')
                ->limit(20)
                ->get(['type', 'points', 'balance_after', 'description', 'invoice_id', 'created_at'])
                ->map(fn ($t) => [
                    'date' => optional($t->created_at)?->toDateString(),
                    'type' => $t->type,
                    'points' => (int) $t->points,
                    'balance_after' => (int) $t->balance_after,
                    'description' => $t->description,
                    'invoice_id' => $t->invoice_id,
                ])
                ->all();
        }

        $goldBalanceGrams = null;
        $goldTransactions = [];
        if ($shop?->isManufacturer()) {
            // fine_gold is signed (+credit / −debit), so the balance is its sum.
            $goldBalanceGrams = round((float) CustomerGoldTransaction::query()
                ->where('customer_id', $customer->id)
                ->sum('fine_gold'), 3);

            $goldTransactions = CustomerGoldTransaction::query()
                ->where('customer_id', $customer->id)
                ->latest('created_at')
                ->limit(20)
                ->get(['type', 'fine_gold', 'created_at'])
                ->map(fn ($t) => [
                    'date' => optional($t->created_at)?->toDateString(),
                    'type' => ((float) $t->fine_gold) >= 0 ? 'credit' : 'debit',
                    'fine_gold_grams' => round(abs((float) $t->fine_gold), 3),
                    'description' => $this->goldTransactionDescription($t->type),
                ])
                ->all();
        }

        return response()->json([
            'customer' => $this->customerProfile($customer),
            'summary' => [
                'total_invoices' => $totalInvoices,
                'lifetime_spend' => $lifetimeSpend,
                'total_repairs' => $totalRepairs,
                'open_repairs' => $openRepairs,
            ],
            'timeline' => $timeline,
            'loyalty_history' => $loyaltyHistory,
            'gold_balance_grams' => $goldBalanceGrams,
            'gold_transactions' => $goldTransactions,
            'compliance' => $this->complianceBlock($customer),
        ]);
    }

    /**
     * Verify a customer's KYC/compliance (PAN + consent + optional ID/address) —
     * the same canonical path used at POS for the ₹2L high-value rule. Shop-scoped.
     * Returns the refreshed profile + compliance status so the app can update the UI.
     */
    public function verifyCompliance(Customer $customer, Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        if ((int) $customer->shop_id !== $shopId) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $validated = $request->validate([
            'pan' => ['nullable', 'string', 'max:10', new PanFormatRule()],
            'aadhaar' => ['nullable', 'digits:12'],
            'mobile' => ['nullable', 'digits:10'],
            'address' => ['nullable', 'string', 'max:255'],
            'consent' => ['required', 'accepted'],
        ]);

        // Reuse the canonical compliance engine — no KYC logic re-implemented here.
        // Aadhaar persists in the customers.id_number column (its documented purpose).
        $errors = app(ComplianceService::class)->saveComplianceData($customer, [
            'pan' => $validated['pan'] ?? null,
            'id_number' => $validated['aadhaar'] ?? null,
            'mobile' => $validated['mobile'] ?? null,
            'address' => $validated['address'] ?? null,
            'consent' => $validated['consent'],
        ], (int) $request->user()->id);

        if (! empty($errors)) {
            return response()->json(['message' => 'Verification failed.', 'errors' => $errors], 422);
        }

        $customer->refresh();

        return response()->json([
            'customer' => $this->customerProfile($customer),
            'compliance' => $this->complianceBlock($customer),
            'message' => 'Customer verified successfully.',
        ]);
    }

    /**
     * Upload an identity document image (PAN / Aadhaar / passport / other) for a
     * customer. Shop-scoped. Reuses KycDocumentService — same private storage and
     * audit as the web KYC upload.
     */
    public function uploadKycDocument(Customer $customer, Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        if ((int) $customer->shop_id !== $shopId) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $validated = $request->validate([
            'document_type' => ['required', 'in:pan_card,aadhaar,passport,other'],
            'file' => ['required', 'file', 'mimes:jpeg,jpg,png,pdf', 'max:10240'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $doc = app(KycDocumentService::class)->store(
            $customer,
            $request->file('file'),
            $validated['document_type'],
            $validated['notes'] ?? null,
            (int) $request->user()->id,
        );

        return response()->json([
            'id' => $doc->id,
            'document_type' => $doc->document_type,
            'url' => $this->kycDocumentUrl($customer, $doc),
            'created_at' => optional($doc->created_at)?->toIso8601String(),
            'message' => 'Document uploaded.',
        ], 201);
    }

    /**
     * Stream a customer's KYC document image (Sanctum-auth, shop-scoped). The web
     * stream route is session-guarded, so mobile needs its own.
     */
    public function showKycDocument(Customer $customer, KycDocument $kycDocument, Request $request)
    {
        if (! $this->ownsKycDocument($customer, $kycDocument, (int) $request->user()->shop_id)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $disk = $kycDocument->file_disk ?? 'public';
        abort_unless(Storage::disk($disk)->exists($kycDocument->file_path), 404);

        return Storage::disk($disk)->response(
            $kycDocument->file_path,
            $kycDocument->original_filename,
            ['Content-Type' => $kycDocument->mime_type ?? 'application/octet-stream'],
        );
    }

    /** Delete a customer's KYC document (Sanctum-auth, shop-scoped). */
    public function deleteKycDocument(Customer $customer, KycDocument $kycDocument, Request $request): JsonResponse
    {
        if (! $this->ownsKycDocument($customer, $kycDocument, (int) $request->user()->shop_id)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        app(KycDocumentService::class)->delete($kycDocument);

        return response()->json(['success' => true]);
    }

    /** True when the document belongs to the given customer and shop. */
    private function ownsKycDocument(Customer $customer, KycDocument $kycDocument, int $shopId): bool
    {
        return (int) $customer->shop_id === $shopId
            && (int) $kycDocument->shop_id === $shopId
            && (int) $kycDocument->customer_id === (int) $customer->id;
    }

    /** Relative mobile stream path for a KYC document (app prepends base URL + token). */
    private function kycDocumentUrl(Customer $customer, KycDocument $doc): string
    {
        return "/api/mobile/customers/{$customer->id}/kyc-documents/{$doc->id}";
    }

    /** KYC/compliance status + the shop's ₹2L rule config (so POS can decide). */
    private function complianceBlock(Customer $customer): array
    {
        $prefs = ShopPreferences::where('shop_id', $customer->shop_id)->first();

        return [
            'status' => $customer->complianceStatus(), // compliant | pending_verification | missing_pan
            'pan' => $customer->pan,
            'aadhaar' => $customer->id_number,
            'verified_at' => optional($customer->compliance_verified_at)?->toIso8601String(),
            // Shop rule so the POS can decide when verification is required.
            'threshold' => (float) ($prefs->compliance_threshold ?? 200000),
            'pan_mandatory' => (bool) ($prefs?->compliance_pan_mandatory ?? false),
            'documents' => $customer->kycDocuments()
                ->where('is_active', \Illuminate\Support\Facades\DB::raw('true'))
                ->latest('created_at')
                ->get(['id', 'document_type', 'created_at'])
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'document_type' => $d->document_type,
                    'created_at' => optional($d->created_at)?->toIso8601String(),
                    'url' => $this->kycDocumentUrl($customer, $d),
                ])
                ->all(),
        ];
    }

    /**
     * Update a customer's profile. Shop-scoped: a shop can only update its own
     * customers (route-model binding applies the BelongsToShop scope; the explicit
     * shop_id check is defence-in-depth). Returns the customer in the same shape as
     * the context endpoint's `customer` object so the app can refresh the profile.
     */
    public function update(Customer $customer, Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        if ((int) $customer->shop_id !== $shopId) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'mobile' => [
                'required', 'digits:10',
                Rule::unique('customers', 'mobile')->ignore($customer->id)->where('shop_id', $shopId),
            ],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'date_of_birth' => 'nullable|date|before:today',
            'anniversary_date' => 'nullable|date',
            'wedding_date' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ], [
            'mobile.digits' => 'Mobile number must be exactly 10 digits.',
        ]);

        $customer->update($validated);

        Cache::forget(PosSearchCacheService::customersCacheKey($shopId, null));

        return response()->json([
            'customer' => $this->customerProfile($customer->refresh()),
            'message' => 'Customer updated successfully.',
        ]);
    }

    /**
     * The customer profile object shared by the context and update responses.
     */
    private function customerProfile(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'customer_code' => $customer->customer_code,
            'loyalty_points' => (int) ($customer->loyalty_points ?? 0),
            'email' => $customer->email,
            'address' => $customer->address,
            'date_of_birth' => optional($customer->date_of_birth)?->toDateString(),
            'anniversary_date' => optional($customer->anniversary_date)?->toDateString(),
            'wedding_date' => optional($customer->wedding_date)?->toDateString(),
            'notes' => $customer->notes,
            'member_since' => optional($customer->created_at)?->toDateString(),
        ];
    }

    /**
     * Human-friendly label for a customer gold transaction type (simple English).
     * customer_gold_transactions has no description column, so derive from `type`.
     */
    private function goldTransactionDescription(?string $type): string
    {
        return match ($type) {
            'advance' => 'Gold deposit',
            'old_metal_in' => 'Old gold taken in',
            'adjust' => 'Adjustment',
            'refund' => 'Gold refund',
            'sale_offset' => 'Used against sale',
            'credit_note_reversal' => 'Return reversal',
            default => $type ? ucwords(str_replace('_', ' ', $type)) : 'Gold transaction',
        };
    }
}
