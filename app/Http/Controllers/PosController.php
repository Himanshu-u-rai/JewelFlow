<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\Customer;
use App\Models\PosQuote;
use App\Models\Scheme;
use App\Models\ShopPaymentMethod;
use App\Rules\PanFormatRule;
use App\Services\ComplianceService;
use App\Services\PricingEngine;
use App\Services\PricingEngine\QuoteInput;
use App\Services\SalesService;
use App\Services\RetailerSalesService;
use App\Services\ExchangeService;
use App\Services\ShopPricingService;
use App\Services\SchemeService;
use Illuminate\Http\JsonResponse;
use LogicException;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    use \App\Http\Controllers\Concerns\ResolvesPosQuote;

    public function __construct(private ShopPricingService $pricing) {}

    public function index(Request $request)
    {
        $shop = auth()->user()->shop;
        $shopId = auth()->user()->shop_id;

        if ($shop->isRetailer() && ($redirect = $this->retailerPricingRedirectIfMissing($shop))) {
            return $redirect;
        }

        $eagerLoads = $shop?->isManufacturer() ? ['metalLot', 'product'] : ['product'];

        $items = Item::where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->with($eagerLoads)
            ->orderByDesc('created_at')
            ->get();

        // POS category filter should include saved category master values
        // plus in-stock item categories (for legacy item rows).
        $categoryMaster = Category::query()
            ->where('shop_id', $shopId)
            ->pluck('name');

        $categories = $categoryMaster
            ->merge($items->pluck('category'))
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->sort()
            ->values();

        $subCategories = $items->pluck('sub_category')->filter()->unique()->sort()->values();

        return view('pos', compact('items', 'categories', 'subCategories'));
    }

    public function showCustomerPos(Customer $customer)
    {
        $shopId = auth()->user()->shop_id;
        $shop = auth()->user()->shop;

        $this->authorize('view', $customer);

        if ($shop->isRetailer() && ($redirect = $this->retailerPricingRedirectIfMissing($shop))) {
            return $redirect;
        }

        $items = Item::where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->get();

        // Retailer edition → simplified POS
        if ($shop->isRetailer()) {
            $loyaltyPointsPerHundred = $shop->preferences?->loyalty_points_per_hundred ?? 1;
            $loyaltyPointValue = (float) ($shop->preferences?->loyalty_point_value ?? 0.25);
            $customerLoyaltyPoints = $customer->loyalty_points ?? 0;
            $offerSchemes = Scheme::query()
                ->where('shop_id', $shopId)
                ->whereIn('type', ['festival_sale', 'discount_offer'])
                ->active()
                ->whereDate('start_date', '<=', now()->toDateString())
                ->where(function ($q) {
                    $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString());
                })
                ->orderBy('priority')
                ->orderByDesc('discount_value')
                ->get(['id', 'name', 'type', 'discount_type', 'discount_value', 'min_purchase_amount', 'max_discount_amount', 'auto_apply', 'applies_to', 'applies_to_value', 'priority']);

            $schemeService = app(SchemeService::class);
            $redeemableEnrollments = $schemeService
                ->redeemableEnrollmentsForCustomer($shopId, $customer->id)
                ->map(function ($enrollment) use ($schemeService) {
                    $enrollment->setAttribute('redeemable_amount', $schemeService->redeemableValue($enrollment));
                    return $enrollment;
                })
                ->values();

            $paymentMethods = ShopPaymentMethod::where('shop_id', $shopId)
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->groupBy('type');

            $splitAlertTotal = app(ComplianceService::class)
                ->getSplitAlertTotal($shopId, $customer->id);

            return view('pos_customer_retailer', compact(
                'customer', 'items',
                'loyaltyPointsPerHundred', 'loyaltyPointValue', 'customerLoyaltyPoints',
                'offerSchemes', 'redeemableEnrollments', 'paymentMethods',
                'splitAlertTotal'
            ));
        }

        return view('pos_customer', compact('customer', 'items'));
    }

    public function sell(Request $request)
    {
        $shop = auth()->user()->shop;

        // === Retailer mode: no gold_rate / making / stone ===
        if ($shop->isRetailer()) {
            return $this->sellRetailer($request);
        }

        // === Manufacturer mode ===
        $validated = $request->validate([
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'item_id' => [
                'required',
                Rule::exists('items', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'gold_rate' => 'required|numeric|min:' . config('business.gold_rate_min') . '|max:' . config('business.gold_rate_max'),
            'making'    => 'nullable|numeric|min:0',
            'stone'     => 'nullable|numeric|min:0',
            'discount'  => 'nullable|numeric|min:0|max:999999',
            // Tally-style rounding allows up to ~₹10 adjustment (nearest=10).
            // When a quote_id is supplied the server overrides this anyway —
            // this rule is just a safety net for the legacy fallback path.
            'round_off' => 'nullable|numeric|min:-100|max:100',

            // Split payments
            'payments'                       => 'required|array|min:1',
            'payments.*.mode'                => 'required|in:cash,upi,bank,wallet,old_gold,old_silver,other',
            'payments.*.amount'              => 'required|numeric|min:0',
            'payments.*.reference'           => 'nullable|string|max:100',
            'payments.*.metal_gross_weight'  => 'nullable|numeric|min:0',
            'payments.*.metal_purity'        => 'nullable|numeric|min:0',
            'payments.*.metal_test_loss'     => 'nullable|numeric|min:0|max:100',
            'payments.*.metal_rate_per_gram' => 'nullable|numeric|min:0',
            // Phase 3a: optional signed quote (manufacturer flow)
            'quote_id'        => 'nullable|string|max:40',
            'quote_signature' => 'nullable|string|max:80',
        ]);

        $resolution = $this->resolveSaleQuote(
            (int) auth()->user()->shop_id,
            $validated['quote_id'] ?? null,
            $validated['quote_signature'] ?? null,
            (int) auth()->id(),
        );
        if (isset($resolution['idempotent_response'])) {
            return $resolution['idempotent_response'];
        }
        if (isset($resolution['stale_response'])) {
            return $resolution['stale_response'];
        }
        $resolvedQuote     = $resolution['quote'] ?? null;
        $resolvedBreakdown = $resolution['breakdown'] ?? null;

        if ($resolvedBreakdown !== null) {
            $saleDiscount = (float) $resolvedBreakdown['manual_discount'];
            $saleRoundOff = (float) $resolvedBreakdown['rounding_adjustment'];
        } else {
            $saleDiscount = (float) ($validated['discount'] ?? 0);
            $saleRoundOff = (float) ($validated['round_off'] ?? 0);
        }

        try {
            $invoice = SalesService::sellItem(
                $validated['customer_id'],
                $validated['item_id'],
                $validated['gold_rate'],
                $validated['making'] ?? 0,
                $validated['stone'] ?? 0,
                $saleDiscount,
                $saleRoundOff,
                $validated['payments'],
            );
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['item_ids']) || isset($errors['item_id'])) {
                return response()->json([
                    'error'   => 'items_unavailable',
                    'message' => 'This item was sold by another cashier. Refresh to see updated stock.',
                    'errors'  => $errors,
                ], 409);
            }
            throw $e;
        }

        if ($resolvedQuote) {
            $resolvedQuote->update([
                'consumed_at'         => now(),
                'consumed_invoice_id' => $invoice->id,
            ]);
        }

        return response()->json([
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
    }

    /**
     * Sell for retailer edition — no gold rate, uses item selling_price
     */
    private function sellRetailer(Request $request)
    {
        try {
            $this->pricing->assertRetailerPricingReady(auth()->user()->shop);
        } catch (LogicException $e) {
            throw ValidationException::withMessages([
                'pricing' => $e->getMessage(),
            ]);
        }

        $validated = $request->validate([
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'item_ids'   => 'required|array|min:1',
            'item_ids.*' => [
                'required',
                'integer',
                Rule::exists('items', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'discount'  => 'nullable|numeric|min:0|max:999999',
            // Tally-style rounding (auto): server overrides this when a
            // quote_id is provided; bound generously for the legacy path.
            'round_off' => 'nullable|numeric|min:-100|max:100',

            'payments'             => 'nullable|array|min:1',
            'payments.*.mode'              => 'required_with:payments|in:cash,upi,bank,wallet,old_gold,old_silver,other,emi',
            'payments.*.payment_method_id' => 'nullable|integer',
            'payments.*.amount'    => 'required_with:payments|numeric|min:0',
            'payments.*.reference' => 'nullable|string|max:100',
            'payments.*.metal_gross_weight'  => 'nullable|numeric|min:0',
            'payments.*.metal_purity'        => 'nullable|numeric|min:0',
            'payments.*.metal_test_loss'     => 'nullable|numeric|min:0|max:100',
            'payments.*.metal_rate_per_gram' => 'nullable|numeric|min:0',
            'offer_scheme_id' => [
                'nullable',
                'integer',
                Rule::exists('schemes', 'id')->where(function ($q) {
                    $q->where('shop_id', auth()->user()->shop_id)
                        ->whereIn('type', ['festival_sale', 'discount_offer']);
                }),
            ],
            'scheme_redemption' => 'nullable|array',
            'scheme_redemption.enrollment_id' => [
                'nullable',
                'integer',
                Rule::exists('scheme_enrollments', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'scheme_redemption.amount' => 'nullable|numeric|min:0',
            'scheme_redemption.note' => 'nullable|string|max:500',
            // Phase 3a: optional signed quote. When present, the server uses the
            // quote's frozen breakdown as the authoritative source for discount /
            // round_off and ignores those legacy fields. Quote is consumed on
            // success (idempotent — double-clicks return the original invoice).
            'quote_id'        => 'nullable|string|max:40',
            'quote_signature' => 'nullable|string|max:80',
        ]);

        // Quote resolution (Phase 3a). Returns either:
        //   - ['idempotent_response' => JsonResponse]  → already consumed, return existing invoice
        //   - ['stale_response' => JsonResponse]       → expired/mismatched, fresh quote attached
        //   - ['quote' => PosQuote, 'breakdown' => array]  → valid, use these values to drive the sale
        //   - ['quote' => null]                         → no quote supplied, legacy path
        $resolution = $this->resolveSaleQuote(
            (int) auth()->user()->shop_id,
            $validated['quote_id'] ?? null,
            $validated['quote_signature'] ?? null,
            (int) auth()->id(),
        );
        if (isset($resolution['idempotent_response'])) {
            return $resolution['idempotent_response'];
        }
        if (isset($resolution['stale_response'])) {
            return $resolution['stale_response'];
        }
        $resolvedQuote     = $resolution['quote'] ?? null;
        $resolvedBreakdown = $resolution['breakdown'] ?? null;

        $payments = $validated['payments'] ?? [];
        $redemptionAmount = (float) data_get($validated, 'scheme_redemption.amount', 0);

        if (empty($payments) && $redemptionAmount <= 0) {
            throw ValidationException::withMessages([
                'payments' => 'Add at least one payment mode or apply scheme redemption.',
            ]);
        }

        $hasEmiMode = collect($payments)
            ->contains(fn ($payment) => ($payment['mode'] ?? null) === 'emi');

        if ($hasEmiMode) {
            $nonEmiModes = collect($payments)
                ->pluck('mode')
                ->filter(fn ($mode) => $mode !== 'emi')
                ->values();

            if ($nonEmiModes->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'payments' => 'For EMI checkout, keep only EMI mode in POS. Enter down payment and EMI details on the EMI page.',
                ]);
            }

            if ((float) data_get($validated, 'scheme_redemption.amount', 0) > 0) {
                throw ValidationException::withMessages([
                    'scheme_redemption.amount' => 'Scheme redemption cannot be combined with EMI checkout from POS.',
                ]);
            }

            $draftInvoice = RetailerSalesService::prepareEmiDraftSale(
                $validated['customer_id'],
                $validated['item_ids'],
                (float) ($validated['discount'] ?? 0),
                (float) ($validated['round_off'] ?? 0),
                isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null,
            );

            return response()->json([
                'invoice_id' => $draftInvoice->id,
                'redirect_url' => route('installments.create', [
                    'invoice_id' => $draftInvoice->id,
                    'from_pos_emi' => 1,
                ]),
            ]);
        }

        // Compliance gate. When a quote is provided, the engine already
        // embedded compliance state in the breakdown — read from there.
        // Otherwise fall back to the legacy estimate.
        $shopId = (int) auth()->user()->shop_id;
        if ($resolvedBreakdown !== null) {
            $compliance = $resolvedBreakdown['compliance'] ?? null;
            if ($compliance && ! empty($compliance['required']) && ! empty($compliance['missing_fields'])) {
                return response()->json([
                    'compliance_required' => true,
                    'missing_fields'      => $compliance['missing_fields'],
                    'threshold'           => (float) ($compliance['threshold'] ?? 200000),
                    'message'             => 'Government KYC required for high-value jewellery transactions.',
                ], 422);
            }
        } else {
            $estimatedTotal = Item::whereIn('id', $validated['item_ids'])
                ->where('shop_id', $shopId)
                ->sum('selling_price');
            $estimatedDiscount = (float) ($validated['discount'] ?? 0);
            $gstRate = (float) (auth()->user()->shop?->gst_rate ?? config('business.gst_rate_default'));
            $taxable  = max($estimatedTotal - $estimatedDiscount, 0);
            $estimatedTotal = round($taxable * (1 + $gstRate / 100), 2);

            $complianceService = app(ComplianceService::class);
            $missing = $complianceService->checkRequired($shopId, (int) $validated['customer_id'], $estimatedTotal);

            if ($missing !== null && !empty($missing)) {
                $prefs = auth()->user()->shop?->preferences;
                return response()->json([
                    'compliance_required' => true,
                    'missing_fields'      => $missing,
                    'threshold'           => (float) ($prefs?->compliance_threshold ?? 200000),
                    'message'             => 'Government KYC required for high-value jewellery transactions.',
                ], 422);
            }
        }

        // When a valid quote is in play, drive the sale from its frozen
        // breakdown so persisted invoice == FE-displayed numbers by construction.
        // Otherwise fall back to legacy discount/round_off from the body.
        if ($resolvedBreakdown !== null) {
            // Pass only the cashier's manual portion to the service. The
            // service's engine.compute() will re-derive the offer discount
            // from offer_scheme_id and produce the same total_discount as
            // the quote — preventing double-application of the offer.
            $saleDiscount = (float) $resolvedBreakdown['manual_discount'];
            $saleRoundOff = (float) $resolvedBreakdown['rounding_adjustment'];
            $saleOfferId  = isset($resolvedBreakdown['offer']['scheme_id'])
                ? (int) $resolvedBreakdown['offer']['scheme_id']
                : null;
        } else {
            $saleDiscount = (float) ($validated['discount'] ?? 0);
            $saleRoundOff = (float) ($validated['round_off'] ?? 0);
            $saleOfferId  = isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null;
        }

        try {
            $invoice = RetailerSalesService::sellItems(
                $validated['customer_id'],
                $validated['item_ids'],
                $saleDiscount,
                $saleRoundOff,
                $payments,
                $saleOfferId,
                $validated['scheme_redemption'] ?? null,
            );
        } catch (ValidationException $e) {
            // Concurrent-sale 409 UX: items raced and got sold by another
            // cashier between the quote being shown and the sale submitting.
            $errors = $e->errors();
            if (isset($errors['item_ids'])) {
                return response()->json([
                    'error'   => 'items_unavailable',
                    'message' => 'One or more items were sold by another cashier. Refresh to see the updated cart.',
                    'errors'  => $errors,
                ], 409);
            }
            throw $e;
        }

        // Stamp the quote as consumed so replays return this same invoice.
        if ($resolvedQuote) {
            $resolvedQuote->update([
                'consumed_at'         => now(),
                'consumed_invoice_id' => $invoice->id,
            ]);
        }

        return response()->json([
            'invoice_id'     => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
    }

    public function saveCompliance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => [
                'required',
                'integer',
                Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'pan'        => ['nullable', 'string', 'max:10', new PanFormatRule()],
            'mobile'     => ['nullable', 'digits:10', function ($attr, $val, $fail) {
                if ($val !== null && !preg_match('/^[6-9][0-9]{9}$/', (string) $val)) {
                    $fail('Mobile number must be 10 digits starting with 6, 7, 8, or 9.');
                }
            }],
            'address'    => ['nullable', 'string', 'max:1000'],
            'id_number'  => ['nullable', 'string', 'max:20'],
            'consent'    => ['required', 'accepted'],
        ]);

        $customer = Customer::findOrFail($validated['customer_id']);
        $this->authorize('update', $customer);

        $errors = app(ComplianceService::class)->saveComplianceData(
            $customer,
            $validated,
            (int) auth()->id(),
        );

        if (!empty($errors)) {
            return response()->json(['errors' => $errors], 422);
        }

        return response()->json(['success' => true]);
    }

    private function retailerPricingRedirectIfMissing($shop)
    {
        if (! $shop->isRetailer() || $this->pricing->hasCurrentDailyRates($shop)) {
            return null;
        }

        $message = 'Today\'s retailer pricing is missing. Ask the owner to save today\'s Pricing rates first.';

        if (auth()->user()->isOwner()) {
            return redirect()->route('settings.edit', ['tab' => 'pricing'])
                ->with('error', $message);
        }

        return redirect()->route('dashboard')
            ->with('error', $message);
    }

    public function exchange(Request $request)
    {
        $request->validate([
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'old_weight' => 'required|numeric|min:0',
            'old_purity' => 'required|numeric|min:0|max:24',
            'test_loss' => 'nullable|numeric|min:0',
            'item_id' => [
                'required',
                Rule::exists('items', 'id')->where('shop_id', auth()->user()->shop_id),
            ],
            'gold_rate' => 'required|numeric|min:0',
            'making' => 'nullable|numeric|min:0',
            'stone' => 'nullable|numeric|min:0',
        ]);

        $result = ExchangeService::exchange(
            $request->customer_id,
            $request->old_weight,
            $request->old_purity,
            $request->test_loss ?? 0,
            $request->item_id,
            $request->gold_rate,
            $request->making ?? 0,
            $request->stone ?? 0,
            []
        );

        return redirect()->route('pos.index')->with(
            'success',
            'Exchange complete. Credit: ₹' . number_format($result['credit'],2) .
            ' | Payable: ₹' . number_format($result['payable'],2) .
            ' | Invoice: ' . $result['invoice']->invoice_number
        );
    }

    public function preview(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $shop = auth()->user()->shop;

        $request->validate([
            'item_id'     => ['required', 'integer', \Illuminate\Validation\Rule::exists('items', 'id')->where('shop_id', $shopId)],
            'customer_id' => ['required', 'integer', \Illuminate\Validation\Rule::exists('customers', 'id')->where('shop_id', $shopId)],
            'gold_rate'   => 'nullable|numeric|min:0',
            'making'      => 'nullable|numeric|min:0',
            'stone'       => 'nullable|numeric|min:0',
            'discount'    => 'nullable|numeric|min:0',
            'round_off'   => 'nullable|numeric',
        ]);

        $item = \App\Models\Item::where('shop_id', $shopId)->findOrFail($request->item_id);
        $customer = \App\Models\Customer::where('shop_id', $shopId)->findOrFail($request->customer_id);

        $inputDiscount = round((float) ($request->discount ?? 0), 2);
        $inputRoundOff = round((float) ($request->round_off ?? 0), 2);
        $engine = app(PricingEngine::class);

        // === Retailer preview: delegate to the pricing engine. ===
        if ($shop->isRetailer()) {
            try {
                $this->pricing->assertRetailerPricingReady($shop);
            } catch (LogicException $e) {
                throw ValidationException::withMessages([
                    'pricing' => $e->getMessage(),
                ]);
            }

            $input = QuoteInput::retailer(
                shopId: (int) $shopId,
                customerId: (int) $customer->id,
                itemIds: [(int) $item->id],
                manualDiscount: $inputDiscount,
            );
            $breakdown = $engine->compute($input);

            // Preserve legacy response shape — the FE reads these keys verbatim.
            return response()->json([
                'selling_price'  => round($breakdown->subtotal, 2),
                'gst_rate'       => round($breakdown->gstRate, 2),
                'gst'            => round($breakdown->gst, 2),
                'discount'       => round($breakdown->totalDiscount, 2),
                'round_off'      => $breakdown->roundingMethod !== 'none'
                    ? round($breakdown->roundingAdjustment, 2)
                    : $inputRoundOff,
                'total'          => round(
                    $breakdown->roundingMethod !== 'none'
                        ? $breakdown->finalTotal
                        : $breakdown->finalTotal + $inputRoundOff,
                    2
                ),
            ]);
        }

        // === Manufacturer preview: delegate to the pricing engine. ===
        $goldRate = (float) ($request->gold_rate ?? 0);

        // Engine requires a positive gold_rate for manufacturer mode. Frontend
        // already short-circuits when rate is missing, but guard regardless.
        if ($goldRate <= 0) {
            throw ValidationException::withMessages([
                'gold_rate' => 'Enter a positive gold rate to preview the invoice.',
            ]);
        }

        $making = round((float) $request->making, 2);
        $stone  = round((float) $request->stone, 2);

        $input = QuoteInput::manufacturer(
            shopId: (int) $shopId,
            customerId: (int) $customer->id,
            itemId: (int) $item->id,
            goldRate: $goldRate,
            making: $making,
            stone: $stone,
            manualDiscount: $inputDiscount,
        );
        $breakdown = $engine->compute($input);

        // The engine's manufacturer invoice math intentionally charges metal on
        // the FULL fine weight (matching SalesService persistence). Customer
        // gold balance is surfaced separately for cashier display only — it
        // does NOT reduce the previewed total. This matches sale behaviour and
        // fixes the historical preview-vs-sale drift.
        $fineWeight  = (float) ($breakdown->customerGold['fine_required'] ?? 0);
        $goldUsed    = (float) ($breakdown->customerGold['fine_usable']   ?? 0);
        $goldCharged = (float) ($breakdown->customerGold['fine_charged']  ?? $fineWeight);
        $line        = $breakdown->lines[0] ?? [];
        $goldValue   = round(((float) ($line['weight'] ?? 0)) * ((float) ($line['rate'] ?? 0)), 2);

        return response()->json([
            'fine_weight'        => round($fineWeight, 3),
            'customer_gold_used' => round($goldUsed, 3),
            'gold_charged'       => round($goldCharged, 3),
            'gold_value'         => $goldValue,
            'making'             => round((float) ($line['making'] ?? $making), 2),
            'stone'              => round((float) ($line['stone']  ?? $stone),  2),
            'subtotal'           => round($breakdown->subtotal, 2),
            'gst_rate'           => round($breakdown->gstRate, 2),
            'gst'                => round($breakdown->gst, 2),
            'wastage_charge'     => round($breakdown->wastageCharge, 2),
            'discount'           => round($breakdown->totalDiscount, 2),
            'round_off'          => $breakdown->roundingMethod !== 'none'
                ? round($breakdown->roundingAdjustment, 2)
                : $inputRoundOff,
            'total'              => round(
                $breakdown->roundingMethod !== 'none'
                    ? $breakdown->finalTotal
                    : $breakdown->finalTotal + $inputRoundOff,
                2
            ),
        ]);
    }

    public function searchCustomers(Request $request)
    {
        $shopId = auth()->user()->shop_id;
        $search = trim($request->input('search', ''));

        $query = Customer::where('shop_id', $shopId);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'ilike', '%' . $search . '%')
                  ->orWhere('last_name', 'ilike', '%' . $search . '%')
                  ->orWhere('mobile', 'ilike', '%' . $search . '%');
            });
        }

        return response()->json(
            $query->orderByDesc('updated_at')
                  ->limit(20)
                  ->get(['id', 'first_name', 'last_name', 'mobile'])
        );
    }

    /**
     * Issue a signed price quote.
     *
     * Stateless — does NOT write to pos_quotes. This endpoint is called on
     * every cart change (keystroke-rate); persisting on each call would
     * explode the table. The companion endpoint /pos/quote/persist stores
     * the quote right before /pos/sell consumes it (Phase 3a).
     */
    public function quote(Request $request): JsonResponse
    {
        $shop   = auth()->user()->shop;
        $shopId = (int) auth()->user()->shop_id;
        $userId = (int) auth()->id();

        if ($shop->isRetailer()) {
            try {
                $this->pricing->assertRetailerPricingReady($shop);
            } catch (LogicException $e) {
                throw ValidationException::withMessages([
                    'pricing' => $e->getMessage(),
                ]);
            }

            $validated = $request->validate([
                'customer_id' => [
                    'required',
                    Rule::exists('customers', 'id')->where('shop_id', $shopId),
                ],
                'item_ids'   => 'required|array|min:1',
                'item_ids.*' => [
                    'required',
                    'integer',
                    Rule::exists('items', 'id')->where('shop_id', $shopId),
                ],
                'manual_discount'         => 'nullable|numeric|min:0|max:999999',
                'manual_discount_percent' => 'nullable|numeric|min:0|max:100',
                'offer_scheme_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('schemes', 'id')->where(function ($q) use ($shopId) {
                        $q->where('shop_id', $shopId)
                          ->whereIn('type', ['festival_sale', 'discount_offer']);
                    }),
                ],
                'scheme_redemption'                 => 'nullable|array',
                'scheme_redemption.enrollment_id'   => [
                    'nullable',
                    'integer',
                    Rule::exists('scheme_enrollments', 'id')->where('shop_id', $shopId),
                ],
                'scheme_redemption.amount'  => 'nullable|numeric|min:0',
                'ignore_auto_offer'         => 'nullable|boolean',
            ]);

            $input = QuoteInput::retailer(
                shopId: $shopId,
                customerId: (int) $validated['customer_id'],
                itemIds: array_values($validated['item_ids']),
                manualDiscount: (float) ($validated['manual_discount'] ?? 0),
                manualDiscountPercent: isset($validated['manual_discount_percent'])
                    ? (float) $validated['manual_discount_percent']
                    : null,
                offerSchemeId: isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null,
                schemeRedemption: $validated['scheme_redemption'] ?? null,
                allowAutoOfferFallback: ! ((bool) ($validated['ignore_auto_offer'] ?? false)),
                client: 'web',
            );
        } else {
            // Manufacturer mode — single item, gold rate required.
            $validated = $request->validate([
                'customer_id' => [
                    'required',
                    Rule::exists('customers', 'id')->where('shop_id', $shopId),
                ],
                'item_id' => [
                    'required',
                    'integer',
                    Rule::exists('items', 'id')->where('shop_id', $shopId),
                ],
                'gold_rate' => 'required|numeric|min:' . config('business.gold_rate_min') . '|max:' . config('business.gold_rate_max'),
                'making'    => 'nullable|numeric|min:0',
                'stone'     => 'nullable|numeric|min:0',
                'manual_discount' => 'nullable|numeric|min:0|max:999999',
            ]);

            $input = QuoteInput::manufacturer(
                shopId: $shopId,
                customerId: (int) $validated['customer_id'],
                itemId: (int) $validated['item_id'],
                goldRate: (float) $validated['gold_rate'],
                making: (float) ($validated['making'] ?? 0),
                stone: (float) ($validated['stone'] ?? 0),
                manualDiscount: (float) ($validated['manual_discount'] ?? 0),
                client: 'web',
            );
        }

        /** @var PricingEngine $engine */
        $engine = app(PricingEngine::class);
        $issued = $engine->quote($input, $userId);

        /** @var \App\Services\PricingEngine\PricingBreakdown $breakdown */
        $breakdown = $issued['breakdown'];

        return response()->json([
            'quote_id'        => $issued['quote_id'],
            'signature'       => $issued['signature'],
            'expires_at'      => $issued['expires_at']->toIso8601String(),
            'breakdown'       => $breakdown->toApiArray(),
            'breakdown_json'  => $issued['breakdown_json'],
            'feature_enabled' => PricingEngine::isQuoteFlowEnabled($shopId),
        ]);
    }

    /**
     * Persist a previously-issued, signed quote so it can be consumed by the
     * sale endpoint (Phase 3a /pos/sell). Verifies the signature against the
     * raw breakdown_json bytes the FE received — re-serialising would break
     * verification on whitespace / key-order differences.
     */
    public function persistQuote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'quote_id'        => 'required|string|max:40',
            'signature'       => 'required|string|max:80',
            'breakdown_json'  => 'required|string|max:16384',
            'input_payload'   => 'required|array',
            'idempotency_key' => 'nullable|string|max:80',
        ]);

        /** @var PricingEngine $engine */
        $engine = app(PricingEngine::class);

        if (! $engine->verify($validated['breakdown_json'], $validated['signature'])) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        try {
            $decoded = json_decode($validated['breakdown_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return response()->json(['error' => 'invalid_breakdown_json'], 422);
        }

        $shopId        = (int) auth()->user()->shop_id;
        $breakdownShop = (int) ($decoded['shop_id'] ?? 0);
        if ($breakdownShop !== $shopId) {
            // Cross-tenant replay attempt: a quote issued for a different shop
            // must NEVER be storable under this shop's id.
            return response()->json(['error' => 'shop_mismatch'], 403);
        }

        $quoteIdFromBreakdown = (string) ($decoded['quote_id'] ?? '');
        if ($quoteIdFromBreakdown !== $validated['quote_id']) {
            // The signed payload's quote_id MUST match what the FE submitted.
            return response()->json(['error' => 'quote_id_mismatch'], 422);
        }

        // Idempotency: if a row with this quote_id already exists in this shop,
        // return it. The quote_id is unique per issuance so this is a strong
        // dedupe even without an explicit idempotency_key.
        $existing = \App\Models\PosQuote::where('shop_id', $shopId)
            ->where('quote_id', $validated['quote_id'])
            ->first();
        if ($existing) {
            return response()->json([
                'quote_id'   => $existing->quote_id,
                'persisted'  => true,
                'expires_at' => optional($existing->expires_at)->toIso8601String(),
                'consumed'   => $existing->isConsumed(),
            ]);
        }

        $expiresAtRaw = (string) ($decoded['expires_at'] ?? '');
        $expiresAt    = $expiresAtRaw !== '' ? \Illuminate\Support\Carbon::parse($expiresAtRaw) : null;

        // Augment the FE-supplied input_payload with server-known identities
        // (shop_id from auth, customer_id and mode from the signed breakdown).
        // The FE does not know its own shop_id — recompute() later calls
        // QuoteInput::fromArray() which requires these fields. Without this
        // injection, every quote-aware sale would 500 on shop_id KeyError.
        $inputPayload = array_merge($validated['input_payload'], [
            'shop_id'     => $shopId,
            'customer_id' => isset($decoded['customer_id']) ? (int) $decoded['customer_id'] : null,
            'mode'        => (string) ($decoded['mode'] ?? QuoteInput::MODE_RETAILER),
        ]);

        $quote = \App\Models\PosQuote::create([
            'quote_id'           => $validated['quote_id'],
            'shop_id'            => $shopId,
            'customer_id'        => isset($decoded['customer_id']) ? (int) $decoded['customer_id'] : null,
            'created_by_user_id' => auth()->id(),
            'mode'               => (string) ($decoded['mode'] ?? QuoteInput::MODE_RETAILER),
            'client'             => 'web',
            'input_payload'      => $inputPayload,
            'breakdown_json'     => $validated['breakdown_json'],
            'breakdown_hash'     => hash('sha256', $validated['breakdown_json']),
            'signature'          => $validated['signature'],
            'expires_at'         => $expiresAt,
            'idempotency_key'    => $validated['idempotency_key'] ?? null,
        ]);

        return response()->json([
            'quote_id'   => $quote->quote_id,
            'persisted'  => true,
            'expires_at' => optional($quote->expires_at)->toIso8601String(),
            'consumed'   => false,
        ]);
    }

    public function findByBarcode($barcode)
    {
        $shopId = auth()->user()->shop_id;

        $item = \App\Models\Item::where('shop_id', $shopId)
            ->where('barcode', $barcode)
            ->first();

        if (!$item) {
            return response()->json(['error' => 'Item not found'], 404);
        }

        $data = [
            'id' => $item->id,
            'design' => $item->design,
            'category' => $item->category,
            'sub_category' => $item->sub_category,
            'weight' => $item->net_metal_weight,
            'gross_weight' => $item->gross_weight,
            'purity' => $item->purity,
            'status' => $item->status,
            'image' => $item->image,
        ];

        // Retailer: include selling_price + cost_price
        if (auth()->user()->shop->isRetailer()) {
            $data['selling_price'] = (float) $item->selling_price;
            $data['cost_price'] = (float) $item->cost_price;
            $data['making_charges'] = (float) ($item->making_charges ?? 0);
            $data['stone_charges'] = (float) ($item->stone_charges ?? 0);
            $data['hallmark_charges'] = (float) ($item->hallmark_charges ?? 0);
            $data['rhodium_charges'] = (float) ($item->rhodium_charges ?? 0);
            $data['other_charges'] = (float) ($item->other_charges ?? 0);
        }

        return response()->json($data);
    }
}
