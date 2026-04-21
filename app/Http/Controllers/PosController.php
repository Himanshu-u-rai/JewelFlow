<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Category;
use App\Models\Item;
use App\Models\Customer;
use App\Models\Scheme;
use App\Services\SalesService;
use App\Services\RetailerSalesService;
use App\Services\ExchangeService;
use App\Services\SchemeService;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function index(Request $request)
    {
        $shop = auth()->user()->shop;
        $shopId = auth()->user()->shop_id;
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

        $items = Item::where('shop_id', $shopId)
            ->where('status', 'in_stock')
            ->get();

        // Retailer edition → simplified POS
        if ($shop->isRetailer()) {
            $roundOffNearest = $shop->preferences?->round_off_nearest ?? 1;
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

            return view('pos_customer_retailer', compact(
                'customer', 'items', 'roundOffNearest',
                'loyaltyPointsPerHundred', 'loyaltyPointValue', 'customerLoyaltyPoints',
                'offerSchemes', 'redeemableEnrollments'
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
        $request->validate([
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
            'round_off' => 'nullable|numeric',

            // Split payments
            'payments'                       => 'required|array|min:1',
            'payments.*.mode'                => 'required|in:cash,upi,bank,old_gold,old_silver,other',
            'payments.*.amount'              => 'required|numeric|min:0',
            'payments.*.reference'           => 'nullable|string|max:100',
            'payments.*.metal_gross_weight'  => 'nullable|numeric|min:0',
            'payments.*.metal_purity'        => 'nullable|numeric|min:0',
            'payments.*.metal_test_loss'     => 'nullable|numeric|min:0|max:100',
            'payments.*.metal_rate_per_gram' => 'nullable|numeric|min:0',
        ]);

        $invoice = SalesService::sellItem(
            $request->customer_id,
            $request->item_id,
            $request->gold_rate,
            $request->making ?? 0,
            $request->stone ?? 0,
            (float) ($request->discount ?? 0),
            (float) ($request->round_off ?? 0),
            $request->payments,
        );

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
    }

    /**
     * Sell for retailer edition — no gold rate, uses item selling_price
     */
    private function sellRetailer(Request $request)
    {
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
            'round_off' => 'nullable|numeric',

            'payments'             => 'nullable|array|min:1',
            'payments.*.mode'      => 'required_with:payments|in:cash,upi,bank,old_gold,old_silver,other,emi',
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

        $invoice = RetailerSalesService::sellItems(
            $validated['customer_id'],
            $validated['item_ids'],
            (float) ($validated['discount'] ?? 0),
            (float) ($validated['round_off'] ?? 0),
            $payments,
            isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null,
            $validated['scheme_redemption'] ?? null,
        );

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
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

        $discount = (float) ($request->discount ?? 0);
        $roundOff = (float) ($request->round_off ?? 0);
        $gstRate = $shop->gst_rate ?? config('business.gst_rate_default');

        // === Retailer preview: simple selling-price based ===
        if ($shop->isRetailer()) {
            $sellingPrice = (float) $item->selling_price;
            $gst = round($sellingPrice * ($gstRate / 100), 2);
            $total = round($sellingPrice + $gst - $discount + $roundOff, 2);

            return response()->json([
                'selling_price'  => round($sellingPrice, 2),
                'gst_rate'       => $gstRate,
                'gst'            => $gst,
                'discount'       => round($discount, 2),
                'round_off'      => round($roundOff, 2),
                'total'          => round($total, 2),
            ]);
        }

        // === Manufacturer preview ===
        $gross = $item->net_metal_weight;
        $purity = $item->purity;
        $fine = $gross * ($purity / 24);

        $goldRate = $request->gold_rate;

        $customerGold = \App\Models\CustomerGoldTransaction::where('shop_id', $shopId)
            ->where('customer_id', $customer->id)
            ->sum('fine_gold');

        $goldUsed = min($customerGold, $fine);
        $goldCharged = $fine - $goldUsed;

        $goldValue = round($goldCharged * $goldRate, 2);

        $making = round((float) $request->making, 2);
        $stone = round((float) $request->stone, 2);

        $subtotal = round($goldValue + $making + $stone, 2);

        $gst = round($subtotal * ($gstRate / 100), 2);

        // Wastage
        $wastageRecoveryPercent = $shop->wastage_recovery_percent ?? 100;
        $wastageFineGold = $item->wastage ?? 0;
        $wastageCharge = round(($wastageFineGold * $goldRate * $wastageRecoveryPercent) / 100, 2);

        $discount = round((float) ($request->discount ?? 0), 2);
        $roundOff = round((float) ($request->round_off ?? 0), 2);

        $total = round($subtotal + $gst + $wastageCharge - $discount + $roundOff, 2);

        return response()->json([
            'fine_weight'       => round($fine, 3),
            'customer_gold_used' => round($goldUsed, 3),
            'gold_charged'      => round($goldCharged, 3),
            'gold_value'        => round($goldValue, 2),
            'making'            => round($making, 2),
            'stone'             => round($stone, 2),
            'subtotal'          => round($subtotal, 2),
            'gst_rate'          => $gstRate,
            'gst'               => round($gst, 2),
            'wastage_charge'    => round($wastageCharge, 2),
            'discount'          => round($discount, 2),
            'round_off'         => round($roundOff, 2),
            'total'             => round($total, 2),
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
        }

        return response()->json($data);
    }
}
