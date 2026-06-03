<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use App\Models\Scheme;
use App\Models\ShopPaymentMethod;
use App\Services\OfferEngineService;
use App\Services\PricingEngine;
use App\Services\PricingEngine\MakingChargeType;
use App\Services\PricingEngine\QuoteInput;
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
    use \App\Http\Controllers\Concerns\ResolvesPosQuote;

    public function __construct(private ShopPricingService $pricing) {}

    /**
     * MC-6: resolve making-charge mode from the request, flag-gated. Flag OFF ⇒
     * fixed regardless of payload (parity with web PosController::makingMode).
     *
     * @return array{0:string,1:?float} [makingType, makingValue]
     */
    private function makingMode(Request $request): array
    {
        if (! config('features.making_charge_modes', false)) {
            return [MakingChargeType::FIXED, null];
        }
        $type  = MakingChargeType::normalize($request->input('making_charge_type'));
        $value = $request->input('making_charge_value');

        return [$type, $value !== null ? (float) $value : null];
    }

    /** MC-6: plain-English "why this making amount" label (NULL for fixed). */
    private function makingLabel(string $type, ?float $value, float $netWeight): ?string
    {
        if ($value === null) {
            return null;
        }
        $num = static fn (float $v, int $dp) => rtrim(rtrim(number_format($v, $dp, '.', ''), '0'), '.');

        return match ($type) {
            MakingChargeType::PERCENTAGE => $num($value, 2) . '% of metal value',
            MakingChargeType::PER_GRAM   => '₹' . $num($value, 2) . '/g × ' . $num($netWeight, 3) . 'g net',
            default                      => null,
        };
    }

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
                'loyalty_points_per_hundred' => (int) ($shop->preferences?->loyalty_points_per_hundred ?? 1),
                'loyalty_point_value' => (float) ($shop->preferences?->loyalty_point_value ?? 0.25),
            ],
            'payment_modes' => [
                'cash',
                'upi',
                'bank',
                'wallet',
                'old_gold',
                'old_silver',
                'other',
                'emi',
            ],
            'payment_methods' => $this->groupedPaymentMethodsForShop($shopId),
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

            // All pricing math via PricingEngine — single source of truth across
            // web and mobile. Same QuoteInput → bit-identical breakdown.
            /** @var PricingEngine $engine */
            $engine = app(PricingEngine::class);
            $input = QuoteInput::retailer(
                shopId: $shopId,
                customerId: (int) $validated['customer_id'],
                itemIds: array_values($validated['item_ids']),
                manualDiscount: (float) ($validated['discount'] ?? 0),
                offerSchemeId: isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null,
                allowAutoOfferFallback: ! ((bool) ($validated['ignore_auto_offer'] ?? false)),
                client: 'mobile',
            );
            $b = $engine->compute($input);

            // Round-off semantics: shops with rounding_method='none' keep using
            // the caller-supplied round_off (preserves today's mobile UI which
            // collects the field). Shops with auto-rounding ignore the input
            // and use the engine's derived rounding_adjustment.
            $effectiveRoundOff = $b->roundingMethod === 'none'
                ? round((float) ($validated['round_off'] ?? 0), 2)
                : $b->roundingAdjustment;
            $total = round($b->preRoundTotal + $effectiveRoundOff, 2);
            $total = max($total, 0);

            $offerPayload = $b->offer;

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
                'selling_price' => round($b->subtotal, 2),
                'manual_discount' => round($b->manualDiscount, 2),
                'offer_discount' => round($b->offerDiscount, 2),
                'total_discount' => round($b->totalDiscount, 2),
                'gst_rate' => round($b->gstRate, 2),
                'gst' => round($b->gst, 2),
                'round_off' => round($effectiveRoundOff, 2),
                'rounding_method' => $b->roundingMethod,
                'rounding_nearest' => round($b->roundingNearest, 2),
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
            'making_charge_type' => 'nullable|string|in:fixed,percentage,per_gram',
            'making_charge_value' => 'nullable|numeric|min:0|max:9999999.99',
            'stone' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0|max:999999',
            'round_off' => 'nullable|numeric',
        ]);

        // All pricing math via PricingEngine — same as web preview / sale path.
        /** @var PricingEngine $engine */
        $engine = app(PricingEngine::class);
        [$makingType, $makingValue] = $this->makingMode($request);
        $input = QuoteInput::manufacturer(
            shopId: $shopId,
            customerId: (int) $validated['customer_id'],
            itemId: (int) $validated['item_id'],
            goldRate: (float) $validated['gold_rate'],
            making: (float) ($validated['making'] ?? 0),
            stone: (float) ($validated['stone'] ?? 0),
            manualDiscount: (float) ($validated['discount'] ?? 0),
            client: 'mobile',
            makingType: $makingType,
            makingValue: $makingValue,
        );
        $b = $engine->compute($input);
        $mfgLine = $b->lines[0] ?? [];

        $effectiveRoundOff = $b->roundingMethod === 'none'
            ? round((float) ($validated['round_off'] ?? 0), 2)
            : $b->roundingAdjustment;
        $total = max(round($b->preRoundTotal + $effectiveRoundOff, 2), 0);

        // Customer-gold breakdown derived from engine output. Note: today's
        // mobile UI assumed customer-gold reduces gold_value before tax, but
        // the sale (SalesService::sellItem) charges full metal value and
        // records the customer-gold credit separately. The engine matches the
        // sale (single source of truth), so the displayed `total` is what the
        // customer will actually pay. UI can still surface fine_balance to the
        // cashier so they explain the available credit.
        $cg = $b->customerGold ?? [
            'fine_required' => 0.0, 'fine_balance' => 0.0,
            'fine_usable' => 0.0, 'fine_charged' => 0.0,
        ];

        return response()->json([
            'mode' => 'manufacturer',
            'fine_weight' => round((float) $cg['fine_required'], 3),
            'customer_gold_used' => round((float) $cg['fine_usable'], 3),
            'gold_charged' => round((float) $cg['fine_charged'], 3),
            'gold_value' => round((float) $cg['fine_required'] * (float) $validated['gold_rate'], 2),
            // MC-6: resolved making from the engine (matches the sale), not raw input.
            'making' => round((float) ($mfgLine['making'] ?? ($validated['making'] ?? 0)), 2),
            'making_label' => $this->makingLabel($makingType, $makingValue, (float) ($mfgLine['weight'] ?? 0)),
            'stone' => round((float) ($validated['stone'] ?? 0), 2),
            'subtotal' => round($b->subtotal, 2),
            'gst_rate' => round($b->gstRate, 2),
            'gst' => round($b->gst, 2),
            'wastage_charge' => round($b->wastageCharge, 2),
            'discount' => round($b->totalDiscount, 2),
            'round_off' => round($effectiveRoundOff, 2),
            'rounding_method' => $b->roundingMethod,
            'rounding_nearest' => round($b->roundingNearest, 2),
            'total' => round($total, 2),
            'customer_gold' => $cg,
        ]);
    }

    public function sell(Request $request): JsonResponse
    {
        // Idempotency: prevent duplicate sales from mobile app retries.
        // Key is scoped to the authenticated user so another user's key can't
        // return a different user's invoice.
        $idempotencyKey = $request->header('X-Idempotency-Key');
        $shop = $request->user()->shop;
        $shopId = (int) $request->user()->shop_id;
        if ($idempotencyKey) {
            $cacheKey = "pos_sell_idempotency:{$shopId}:{$request->user()->id}:{$idempotencyKey}";
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return response()->json($cached);
            }
        }

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
                'payments.*.mode' => 'required_with:payments|in:cash,upi,bank,wallet,old_gold,old_silver,other,emi',
                'payments.*.payment_method_id' => 'nullable|integer',
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
                // Phase 3a: optional signed quote
                'quote_id'        => 'nullable|string|max:40',
                'quote_signature' => 'nullable|string|max:80',
            ]);

            $resolution = $this->resolveSaleQuote(
                $shopId,
                $validated['quote_id'] ?? null,
                $validated['quote_signature'] ?? null,
                (int) $request->user()->id,
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

            $this->validateMobilePosPaymentMethods($payments, $shopId);

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

            if ($resolvedBreakdown !== null) {
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
                    (int) $validated['customer_id'],
                    $validated['item_ids'],
                    $saleDiscount,
                    $saleRoundOff,
                    $payments,
                    $saleOfferId,
                    $validated['scheme_redemption'] ?? null,
                    !((bool) ($validated['ignore_auto_offer'] ?? false)),
                );
            } catch (ValidationException $e) {
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

            if ($resolvedQuote) {
                $resolvedQuote->update([
                    'consumed_at'         => now(),
                    'consumed_invoice_id' => $invoice->id,
                ]);
            }

            $responseData = [
                'invoice_id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
            ];

            if ($idempotencyKey) {
                Cache::put("pos_sell_idempotency:{$shopId}:{$request->user()->id}:{$idempotencyKey}", $responseData, now()->addHours(24));
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
            'making_charge_type' => 'nullable|string|in:fixed,percentage,per_gram',
            'making_charge_value' => 'nullable|numeric|min:0|max:9999999.99',
            'stone' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0|max:999999',
            'round_off' => 'nullable|numeric',
            'payments' => 'required|array|min:1',
            'payments.*.mode' => 'required|in:cash,upi,bank,wallet,old_gold,old_silver,other',
            'payments.*.payment_method_id' => 'nullable|integer',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.reference' => 'nullable|string|max:100',
            'payments.*.metal_gross_weight' => 'nullable|numeric|min:0',
            'payments.*.metal_purity' => 'nullable|numeric|min:0',
            'payments.*.metal_test_loss' => 'nullable|numeric|min:0|max:100',
            'payments.*.metal_rate_per_gram' => 'nullable|numeric|min:0',
            // Phase 3a: optional signed quote (manufacturer flow)
            'quote_id'        => 'nullable|string|max:40',
            'quote_signature' => 'nullable|string|max:80',
        ]);

        $resolution = $this->resolveSaleQuote(
            $shopId,
            $validated['quote_id'] ?? null,
            $validated['quote_signature'] ?? null,
            (int) $request->user()->id,
        );
        if (isset($resolution['idempotent_response'])) {
            return $resolution['idempotent_response'];
        }
        if (isset($resolution['stale_response'])) {
            return $resolution['stale_response'];
        }
        $resolvedQuote     = $resolution['quote'] ?? null;
        $resolvedBreakdown = $resolution['breakdown'] ?? null;

        $this->validateMobilePosPaymentMethods($validated['payments'], $shopId);

        if ($resolvedBreakdown !== null) {
            $saleDiscount = (float) $resolvedBreakdown['manual_discount'];
            $saleRoundOff = (float) $resolvedBreakdown['rounding_adjustment'];
        } else {
            $saleDiscount = (float) ($validated['discount'] ?? 0);
            $saleRoundOff = (float) ($validated['round_off'] ?? 0);
        }

        [$makingType, $makingValue] = $this->makingMode($request);

        try {
            $invoice = SalesService::sellItem(
                (int) $validated['customer_id'],
                (int) $validated['item_id'],
                (float) $validated['gold_rate'],
                (float) ($validated['making'] ?? 0),
                (float) ($validated['stone'] ?? 0),
                $saleDiscount,
                $saleRoundOff,
                $validated['payments'],
                makingType: $makingType,
                makingValue: $makingValue,
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

        $responseData = [
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
        ];

        if ($idempotencyKey) {
            Cache::put("pos_sell_idempotency:{$shopId}:{$request->user()->id}:{$idempotencyKey}", $responseData, now()->addHours(24));
        }

        return response()->json($responseData);
    }

    /**
     * Issue a signed price quote (mobile).
     *
     * Stateless — does not write to pos_quotes. Frontend re-calls this on
     * every cart change; only /pos/quote/persist commits a row.
     */
    public function quote(Request $request): JsonResponse
    {
        $shop   = $request->user()->shop;
        $shopId = (int) $request->user()->shop_id;
        $userId = (int) $request->user()->id;

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
                client: 'mobile',
            );
        } else {
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
                'making_charge_type' => 'nullable|string|in:fixed,percentage,per_gram',
                'making_charge_value' => 'nullable|numeric|min:0|max:9999999.99',
                'stone'     => 'nullable|numeric|min:0',
                'manual_discount' => 'nullable|numeric|min:0|max:999999',
            ]);

            [$makingType, $makingValue] = $this->makingMode($request);

            $input = QuoteInput::manufacturer(
                shopId: $shopId,
                customerId: (int) $validated['customer_id'],
                itemId: (int) $validated['item_id'],
                goldRate: (float) $validated['gold_rate'],
                making: (float) ($validated['making'] ?? 0),
                stone: (float) ($validated['stone'] ?? 0),
                manualDiscount: (float) ($validated['manual_discount'] ?? 0),
                client: 'mobile',
                makingType: $makingType,
                makingValue: $makingValue,
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
     * Persist a signed quote (mobile). Accepts the idempotency key from either
     * the request body or the X-Idempotency-Key header (matches /pos/sell).
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

        // Mobile convention: idempotency key may come via the same header used
        // by /pos/sell so retried network requests dedupe correctly.
        $idempotencyKey = $validated['idempotency_key']
            ?? $request->header('X-Idempotency-Key');
        if (is_string($idempotencyKey)) {
            $idempotencyKey = trim($idempotencyKey);
            if ($idempotencyKey === '') {
                $idempotencyKey = null;
            }
        }

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

        $shopId        = (int) $request->user()->shop_id;
        $breakdownShop = (int) ($decoded['shop_id'] ?? 0);
        if ($breakdownShop !== $shopId) {
            return response()->json(['error' => 'shop_mismatch'], 403);
        }

        $quoteIdFromBreakdown = (string) ($decoded['quote_id'] ?? '');
        if ($quoteIdFromBreakdown !== $validated['quote_id']) {
            return response()->json(['error' => 'quote_id_mismatch'], 422);
        }

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

        // Augment the FE-supplied input_payload with server-known identities so
        // recompute() (which calls QuoteInput::fromArray) has shop_id available.
        $inputPayload = array_merge($validated['input_payload'], [
            'shop_id'     => $shopId,
            'customer_id' => isset($decoded['customer_id']) ? (int) $decoded['customer_id'] : null,
            'mode'        => (string) ($decoded['mode'] ?? QuoteInput::MODE_RETAILER),
        ]);

        $quote = \App\Models\PosQuote::create([
            'quote_id'           => $validated['quote_id'],
            'shop_id'            => $shopId,
            'customer_id'        => isset($decoded['customer_id']) ? (int) $decoded['customer_id'] : null,
            'created_by_user_id' => $request->user()->id,
            'mode'               => (string) ($decoded['mode'] ?? QuoteInput::MODE_RETAILER),
            'client'             => 'mobile',
            'input_payload'      => $inputPayload,
            'breakdown_json'     => $validated['breakdown_json'],
            'breakdown_hash'     => hash('sha256', $validated['breakdown_json']),
            'signature'          => $validated['signature'],
            'expires_at'         => $expiresAt,
            'idempotency_key'    => $idempotencyKey,
        ]);

        return response()->json([
            'quote_id'   => $quote->quote_id,
            'persisted'  => true,
            'expires_at' => optional($quote->expires_at)->toIso8601String(),
            'consumed'   => false,
        ]);
    }

    private function groupedPaymentMethodsForShop(int $shopId): array
    {
        $methods = ShopPaymentMethod::query()
            ->where('shop_id', $shopId)
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'type',
                'name',
                'upi_id',
                'bank_name',
                'account_number',
                'wallet_id',
            ]);

        $grouped = [
            ShopPaymentMethod::TYPE_UPI => [],
            ShopPaymentMethod::TYPE_BANK => [],
            ShopPaymentMethod::TYPE_WALLET => [],
        ];

        foreach ($methods as $method) {
            if (!isset($grouped[$method->type])) {
                continue;
            }

            $base = [
                'id' => (int) $method->id,
                'type' => (string) $method->type,
                'name' => (string) $method->name,
                'account_label' => (string) $method->account_label,
            ];

            if ($method->type === ShopPaymentMethod::TYPE_UPI) {
                $base['upi_id'] = $method->upi_id;
            } elseif ($method->type === ShopPaymentMethod::TYPE_BANK) {
                $base['bank_name'] = $method->bank_name;
                $base['account_number'] = $method->account_number;
            } elseif ($method->type === ShopPaymentMethod::TYPE_WALLET) {
                $base['wallet_id'] = $method->wallet_id;
            }

            $grouped[$method->type][] = $base;
        }

        return [
            'upi' => $grouped[ShopPaymentMethod::TYPE_UPI],
            'bank' => $grouped[ShopPaymentMethod::TYPE_BANK],
            'wallet' => $grouped[ShopPaymentMethod::TYPE_WALLET],
        ];
    }

    private function validateMobilePosPaymentMethods(array $payments, int $shopId): void
    {
        $restrictedDuplicateModes = ['old_gold', 'old_silver', 'emi'];
        $modeCounts = [];

        foreach ($payments as $payment) {
            $mode = (string) ($payment['mode'] ?? '');
            if (!in_array($mode, $restrictedDuplicateModes, true)) {
                continue;
            }
            $modeCounts[$mode] = ($modeCounts[$mode] ?? 0) + 1;
        }

        foreach ($modeCounts as $mode => $count) {
            if ($count > 1) {
                throw ValidationException::withMessages([
                    'payments' => "Duplicate payment mode \"{$mode}\" is not allowed.",
                ]);
            }
        }

        $paymentMethodIds = collect($payments)
            ->pluck('payment_method_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $methodsById = $paymentMethodIds->isEmpty()
            ? collect()
            : ShopPaymentMethod::query()
                ->where('shop_id', $shopId)
                ->whereIn('id', $paymentMethodIds)
                ->get(['id', 'type'])
                ->keyBy('id');

        $modeTypeMap = [
            'upi' => ShopPaymentMethod::TYPE_UPI,
            'bank' => ShopPaymentMethod::TYPE_BANK,
            'wallet' => ShopPaymentMethod::TYPE_WALLET,
        ];

        foreach ($payments as $index => $payment) {
            $mode = (string) ($payment['mode'] ?? '');
            $expectedType = $modeTypeMap[$mode] ?? null;
            $methodId = $payment['payment_method_id'] ?? null;
            $hasMethodId = !($methodId === null || $methodId === '');

            if ($expectedType !== null && !$hasMethodId) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payment_method_id" => "Payment method is required for mode \"{$mode}\".",
                ]);
            }

            if ($expectedType === null) {
                if ($hasMethodId) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.payment_method_id" => "Payment method is not allowed for mode \"{$mode}\".",
                    ]);
                }

                continue;
            }

            $method = $methodsById->get((int) $methodId);
            if (!$method) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payment_method_id" => 'Selected payment method is invalid for this shop.',
                ]);
            }

            if ($method->type !== $expectedType) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payment_method_id" => "Payment method type must match mode \"{$mode}\".",
                ]);
            }
        }
    }

    private function retailerPricingBlockedResponse(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 409);
    }
}
