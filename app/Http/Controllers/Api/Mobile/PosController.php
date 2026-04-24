<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Scheme;
use App\Services\OfferEngineService;
use App\Services\RetailerSalesService;
use App\Services\SalesService;
use App\Services\SchemeService;
use App\Services\ShopPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class PosController extends Controller
{
    public function __construct(private ShopPricingService $pricing) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;
        $shopId = (int) $request->user()->shop_id;
        $pricingReady = ! $shop->isRetailer() || $this->pricing->hasCurrentDailyRates($shop);

        $customerId = (int) $request->integer('customer_id', 0);
        $customer = null;

        if ($customerId > 0) {
            $customer = Customer::query()
                ->where('shop_id', $shopId)
                ->find($customerId);
        }

        $response = [
            'shop_mode' => $shop->isRetailer() ? 'retailer' : 'manufacturer',
            'gst_rate' => (float) ($shop->gst_rate ?? config('business.gst_rate_default')),
            'preferences' => [
                'round_off_nearest' => (int) ($shop->preferences?->round_off_nearest ?? 1),
                'loyalty_points_per_hundred' => (int) ($shop->preferences?->loyalty_points_per_hundred ?? 1),
                'loyalty_point_value' => (float) ($shop->preferences?->loyalty_point_value ?? 0.25),
            ],
            'payment_modes' => [
                'cash',
                'upi',
                'bank',
                'old_gold',
                'old_silver',
                'other',
                'emi',
            ],
            'customer' => $customer ? [
                'id' => (int) $customer->id,
                'name' => (string) $customer->name,
                'mobile' => (string) $customer->mobile,
                'loyalty_points' => (int) ($customer->loyalty_points ?? 0),
            ] : null,
            'offers' => [],
            'redeemable_enrollments' => [],
            'pricing_ready' => $pricingReady,
            'pricing_business_date' => $shop->isRetailer() ? $this->pricing->businessDateString($shop) : null,
        ];

        if ($shop->isRetailer() && $customer) {
            $response['offers'] = Scheme::query()
                ->where('shop_id', $shopId)
                ->whereIn('type', ['festival_sale', 'discount_offer'])
                ->active()
                ->whereDate('start_date', '<=', now()->toDateString())
                ->where(function ($query) {
                    $query->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', now()->toDateString());
                })
                ->orderBy('priority')
                ->orderByDesc('discount_value')
                ->get([
                    'id',
                    'name',
                    'type',
                    'discount_type',
                    'discount_value',
                    'min_purchase_amount',
                    'max_discount_amount',
                    'auto_apply',
                    'applies_to',
                    'applies_to_value',
                    'priority',
                ])
                ->map(fn (Scheme $scheme) => [
                    'id' => (int) $scheme->id,
                    'name' => (string) $scheme->name,
                    'type' => (string) $scheme->type,
                    'discount_type' => (string) ($scheme->discount_type ?? ''),
                    'discount_value' => (float) ($scheme->discount_value ?? 0),
                    'min_purchase_amount' => (float) ($scheme->min_purchase_amount ?? 0),
                    'max_discount_amount' => (float) ($scheme->max_discount_amount ?? 0),
                    'auto_apply' => (bool) ($scheme->auto_apply ?? false),
                    'applies_to' => (string) ($scheme->applies_to ?? 'all_items'),
                    'applies_to_value' => (string) ($scheme->applies_to_value ?? ''),
                    'priority' => (int) ($scheme->priority ?? 100),
                ])
                ->values();

            $schemeService = app(SchemeService::class);
            $response['redeemable_enrollments'] = $schemeService
                ->redeemableEnrollmentsForCustomer($shopId, (int) $customer->id)
                ->map(fn ($enrollment) => [
                    'id' => (int) $enrollment->id,
                    'scheme_name' => (string) optional($enrollment->scheme)->name,
                    'status' => (string) $enrollment->status,
                    'redeemable_amount' => (float) ($enrollment->redeemableAmount()),
                ])
                ->values();
        }

        return response()->json($response);
    }

    public function preview(Request $request): JsonResponse
    {
        $shop = $request->user()->shop;
        $shopId = (int) $request->user()->shop_id;
        $gstRate = (float) ($shop->gst_rate ?? config('business.gst_rate_default'));

        if ($shop->isRetailer()) {
            try {
                $this->pricing->assertRetailerPricingReady($shop);
            } catch (LogicException $e) {
                return $this->retailerPricingBlockedResponse($e->getMessage());
            }

            $validated = $request->validate([
                'customer_id' => [
                    'required',
                    Rule::exists('customers', 'id')->where('shop_id', $shopId),
                ],
                'item_ids' => 'required|array|min:1',
                'item_ids.*' => [
                    'required',
                    'integer',
                    Rule::exists('items', 'id')->where('shop_id', $shopId),
                ],
                'discount' => 'nullable|numeric|min:0|max:999999',
                'round_off' => 'nullable|numeric',
                'ignore_auto_offer' => 'nullable|boolean',
                'offer_scheme_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('schemes', 'id')->where(function ($query) use ($shopId) {
                        $query->where('shop_id', $shopId)
                            ->whereIn('type', ['festival_sale', 'discount_offer']);
                    }),
                ],
                'scheme_redemption' => 'nullable|array',
                'scheme_redemption.enrollment_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('scheme_enrollments', 'id')->where('shop_id', $shopId),
                ],
                'scheme_redemption.amount' => 'nullable|numeric|min:0',
            ]);

            $items = Item::query()
                ->where('shop_id', $shopId)
                ->whereIn('id', $validated['item_ids'])
                ->get();

            if ($items->count() !== count($validated['item_ids'])) {
                return response()->json(['message' => 'One or more items were not found.'], 422);
            }

            $sellingPrice = (float) $items->sum('selling_price');
            $manualDiscount = max(0, round((float) ($validated['discount'] ?? 0), 2));
            $roundOff = round((float) ($validated['round_off'] ?? 0), 2);

            $offerEngine = app(OfferEngineService::class);
            $offerPayload = $offerEngine->resolveBestOffer(
                $shopId,
                $items->map(fn (Item $item) => [
                    'category' => $item->category,
                    'sub_category' => $item->sub_category,
                ])->values()->all(),
                $sellingPrice,
                isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null,
                !((bool) ($validated['ignore_auto_offer'] ?? false))
            );

            $offerDiscount = (float) ($offerPayload['discount_amount'] ?? 0);
            $totalDiscount = min(round($sellingPrice, 2), round($manualDiscount + $offerDiscount, 2));
            $taxable = max(round($sellingPrice - $totalDiscount, 2), 0);
            $gst = round($taxable * ($gstRate / 100), 2);
            $total = round($sellingPrice + $gst - $totalDiscount + $roundOff, 2);
            $total = max($total, 0);

            $requestedRedemption = (float) data_get($validated, 'scheme_redemption.amount', 0);
            $appliedRedemption = 0.0;
            $redemptionMax = 0.0;

            if (!empty($validated['scheme_redemption']['enrollment_id'])) {
                $enrollment = \App\Models\SchemeEnrollment::query()
                    ->where('shop_id', $shopId)
                    ->where('customer_id', (int) $validated['customer_id'])
                    ->find((int) $validated['scheme_redemption']['enrollment_id']);

                if ($enrollment) {
                    $schemeService = app(SchemeService::class);
                    $redeemableAmount = $schemeService->redeemableValue($enrollment);
                    $redemptionMax = min($redeemableAmount, $total);
                    $appliedRedemption = min(max($requestedRedemption, 0), $redemptionMax);
                }
            }

            return response()->json([
                'mode' => 'retailer',
                'items_count' => $items->count(),
                'selling_price' => round($sellingPrice, 2),
                'manual_discount' => round($manualDiscount, 2),
                'offer_discount' => round($offerDiscount, 2),
                'total_discount' => round($totalDiscount, 2),
                'gst_rate' => $gstRate,
                'gst' => round($gst, 2),
                'round_off' => round($roundOff, 2),
                'total' => round($total, 2),
                'payable_after_redemption' => round(max($total - $appliedRedemption, 0), 2),
                'scheme_redemption' => [
                    'max' => round($redemptionMax, 2),
                    'applied' => round($appliedRedemption, 2),
                ],
                'applied_offer' => $offerPayload ? [
                    'scheme_id' => (int) $offerPayload['scheme_id'],
                    'scheme_name' => (string) $offerPayload['scheme_name'],
                    'discount_type' => (string) $offerPayload['discount_type'],
                    'discount_value' => (float) $offerPayload['discount_value'],
                    'discount_amount' => (float) $offerPayload['discount_amount'],
                    'auto_applied' => (bool) ($offerPayload['auto_applied'] ?? false),
                ] : null,
            ]);
        }

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
            'making' => 'nullable|numeric|min:0',
            'stone' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0|max:999999',
            'round_off' => 'nullable|numeric',
        ]);

        $item = Item::query()
            ->where('shop_id', $shopId)
            ->findOrFail((int) $validated['item_id']);

        $customerGold = \App\Models\CustomerGoldTransaction::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', (int) $validated['customer_id'])
            ->sum('fine_gold');

        $gross = (float) $item->net_metal_weight;
        $purity = (float) $item->purity;
        $fine = $gross * ($purity / 24);
        $goldRate = (float) ($validated['gold_rate'] ?? 0);

        $goldUsed = min((float) $customerGold, $fine);
        $goldCharged = $fine - $goldUsed;
        $goldValue = round($goldCharged * $goldRate, 2);

        $making = round((float) ($validated['making'] ?? 0), 2);
        $stone = round((float) ($validated['stone'] ?? 0), 2);
        $subtotal = round($goldValue + $making + $stone, 2);
        $gst = round($subtotal * ($gstRate / 100), 2);

        $wastageRecoveryPercent = $shop->wastage_recovery_percent ?? 100;
        $wastageFineGold = (float) ($item->wastage ?? 0);
        $wastageCharge = round(($wastageFineGold * $goldRate * $wastageRecoveryPercent) / 100, 2);

        $discount = round((float) ($validated['discount'] ?? 0), 2);
        $roundOff = round((float) ($validated['round_off'] ?? 0), 2);

        $total = round($subtotal + $gst + $wastageCharge - $discount + $roundOff, 2);
        $total = max($total, 0);

        return response()->json([
            'mode' => 'manufacturer',
            'fine_weight' => round($fine, 3),
            'customer_gold_used' => round($goldUsed, 3),
            'gold_charged' => round($goldCharged, 3),
            'gold_value' => round($goldValue, 2),
            'making' => round($making, 2),
            'stone' => round($stone, 2),
            'subtotal' => round($subtotal, 2),
            'gst_rate' => $gstRate,
            'gst' => round($gst, 2),
            'wastage_charge' => round($wastageCharge, 2),
            'discount' => round($discount, 2),
            'round_off' => round($roundOff, 2),
            'total' => round($total, 2),
        ]);
    }

    public function sell(Request $request): JsonResponse
    {
        // Idempotency: prevent duplicate sales from mobile app retries
        $idempotencyKey = $request->header('X-Idempotency-Key');
        if ($idempotencyKey) {
            $cacheKey = "pos_sell_idempotency:{$idempotencyKey}";
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return response()->json($cached);
            }
        }

        $shop = $request->user()->shop;
        $shopId = (int) $request->user()->shop_id;

        if ($shop->isRetailer()) {
            try {
                $this->pricing->assertRetailerPricingReady($shop);
            } catch (LogicException $e) {
                return $this->retailerPricingBlockedResponse($e->getMessage());
            }

            $validated = $request->validate([
                'customer_id' => [
                    'required',
                    Rule::exists('customers', 'id')->where('shop_id', $shopId),
                ],
                'item_ids' => 'required|array|min:1',
                'item_ids.*' => [
                    'required',
                    'integer',
                    Rule::exists('items', 'id')->where('shop_id', $shopId),
                ],
                'discount' => 'nullable|numeric|min:0|max:999999',
                'round_off' => 'nullable|numeric',
                'ignore_auto_offer' => 'nullable|boolean',
                'payments' => 'nullable|array|min:1',
                'payments.*.mode' => 'required_with:payments|in:cash,upi,bank,old_gold,old_silver,other,emi',
                'payments.*.amount' => 'required_with:payments|numeric|min:0',
                'payments.*.reference' => 'nullable|string|max:100',
                'payments.*.metal_gross_weight' => 'nullable|numeric|min:0',
                'payments.*.metal_purity' => 'nullable|numeric|min:0',
                'payments.*.metal_test_loss' => 'nullable|numeric|min:0|max:100',
                'payments.*.metal_rate_per_gram' => 'nullable|numeric|min:0',
                'offer_scheme_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('schemes', 'id')->where(function ($query) use ($shopId) {
                        $query->where('shop_id', $shopId)
                            ->whereIn('type', ['festival_sale', 'discount_offer']);
                    }),
                ],
                'scheme_redemption' => 'nullable|array',
                'scheme_redemption.enrollment_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('scheme_enrollments', 'id')->where('shop_id', $shopId),
                ],
                'scheme_redemption.amount' => 'nullable|numeric|min:0',
                'scheme_redemption.note' => 'nullable|string|max:500',
            ]);

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
                    (int) $validated['customer_id'],
                    $validated['item_ids'],
                    (float) ($validated['discount'] ?? 0),
                    (float) ($validated['round_off'] ?? 0),
                    isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null,
                    !((bool) ($validated['ignore_auto_offer'] ?? false)),
                );

                return response()->json([
                    'invoice_id' => (int) $draftInvoice->id,
                    'redirect_url' => route('installments.create', [
                        'invoice_id' => $draftInvoice->id,
                        'from_pos_emi' => 1,
                    ]),
                ]);
            }

            $invoice = RetailerSalesService::sellItems(
                (int) $validated['customer_id'],
                $validated['item_ids'],
                (float) ($validated['discount'] ?? 0),
                (float) ($validated['round_off'] ?? 0),
                $payments,
                isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null,
                $validated['scheme_redemption'] ?? null,
                !((bool) ($validated['ignore_auto_offer'] ?? false)),
            );

            $responseData = [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
            ];

            if ($idempotencyKey) {
                Cache::put("pos_sell_idempotency:{$idempotencyKey}", $responseData, now()->addHours(24));
            }

            return response()->json($responseData);
        }

        $validated = $request->validate([
            'customer_id' => [
                'required',
                Rule::exists('customers', 'id')->where('shop_id', $shopId),
            ],
            'item_id' => [
                'required',
                Rule::exists('items', 'id')->where('shop_id', $shopId),
            ],
            'gold_rate' => 'required|numeric|min:' . config('business.gold_rate_min') . '|max:' . config('business.gold_rate_max'),
            'making' => 'nullable|numeric|min:0',
            'stone' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0|max:999999',
            'round_off' => 'nullable|numeric',
            'payments' => 'required|array|min:1',
            'payments.*.mode' => 'required|in:cash,upi,bank,old_gold,old_silver,other',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string|max:100',
            'payments.*.metal_gross_weight' => 'nullable|numeric|min:0',
            'payments.*.metal_purity' => 'nullable|numeric|min:0',
            'payments.*.metal_test_loss' => 'nullable|numeric|min:0|max:100',
            'payments.*.metal_rate_per_gram' => 'nullable|numeric|min:0',
        ]);

        $invoice = SalesService::sellItem(
            (int) $validated['customer_id'],
            (int) $validated['item_id'],
            (float) $validated['gold_rate'],
            (float) ($validated['making'] ?? 0),
            (float) ($validated['stone'] ?? 0),
            (float) ($validated['discount'] ?? 0),
            (float) ($validated['round_off'] ?? 0),
            $validated['payments'],
        );

        $responseData = [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
        ];

        if ($idempotencyKey) {
            Cache::put("pos_sell_idempotency:{$idempotencyKey}", $responseData, now()->addHours(24));
        }

        return response()->json($responseData);
    }

    private function retailerPricingBlockedResponse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }
}
