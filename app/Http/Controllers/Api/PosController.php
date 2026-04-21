<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\SalesService;
use App\Services\RetailerSalesService;
use App\Services\PosSearchCacheService;
use Illuminate\Validation\Rule;

class PosController extends Controller
{
    public function items(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        return PosSearchCacheService::items($shopId, $request->input('search'));
    }

    public function customers(Request $request)
    {
        $shopId = (int) auth()->user()->shop_id;

        return PosSearchCacheService::customers($shopId, $request->input('search'));
    }

    public function sale(Request $request)
    {
        $shop = auth()->user()->shop;
        $shopId = auth()->user()->shop_id;

        if ($shop && $shop->isRetailer()) {
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
                'discount'  => 'nullable|numeric|min:0',
                'round_off' => 'nullable|numeric',
                'payments'  => 'nullable|array|min:1',
                'payments.*.mode'                => 'required_with:payments|in:cash,upi,bank,old_gold,old_silver,other,emi',
                'payments.*.amount'              => 'required_with:payments|numeric|min:0',
                'payments.*.reference'           => 'nullable|string|max:100',
                'payments.*.metal_gross_weight'  => 'nullable|numeric|min:0',
                'payments.*.metal_purity'        => 'nullable|numeric|min:0',
                'payments.*.metal_test_loss'     => 'nullable|numeric|min:0|max:100',
                'payments.*.metal_rate_per_gram' => 'nullable|numeric|min:0',
                'offer_scheme_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('schemes', 'id')->where(function ($q) use ($shopId) {
                        $q->where('shop_id', $shopId)
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
                return response()->json([
                    'message' => 'Add at least one payment mode or apply scheme redemption.',
                    'errors' => ['payments' => ['Add at least one payment mode or apply scheme redemption.']],
                ], 422);
            }

            $hasEmiMode = collect($payments)
                ->contains(fn ($payment) => ($payment['mode'] ?? null) === 'emi');

            if ($hasEmiMode) {
                $nonEmiModes = collect($payments)
                    ->pluck('mode')
                    ->filter(fn ($mode) => $mode !== 'emi')
                    ->values();

                if ($nonEmiModes->isNotEmpty()) {
                    return response()->json([
                        'message' => 'For EMI checkout, keep only EMI mode in POS. Enter down payment and EMI details on EMI page.',
                        'errors' => ['payments' => ['For EMI checkout, keep only EMI mode in POS. Enter down payment and EMI details on EMI page.']],
                    ], 422);
                }

                if ((float) data_get($validated, 'scheme_redemption.amount', 0) > 0) {
                    return response()->json([
                        'message' => 'Scheme redemption cannot be combined with EMI checkout from POS.',
                        'errors' => ['scheme_redemption.amount' => ['Scheme redemption cannot be combined with EMI checkout from POS.']],
                    ], 422);
                }

                $draftInvoice = RetailerSalesService::prepareEmiDraftSale(
                    (int) $validated['customer_id'],
                    array_map('intval', $validated['item_ids']),
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
                (int) $validated['customer_id'],
                array_map('intval', $validated['item_ids']),
                (float) ($validated['discount'] ?? 0),
                (float) ($validated['round_off'] ?? 0),
                $payments,
                isset($validated['offer_scheme_id']) ? (int) $validated['offer_scheme_id'] : null,
                $validated['scheme_redemption'] ?? null
            );

            return response()->json($invoice);
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
            'gold_rate' => 'required|numeric|min:0',
            'making' => 'nullable|numeric|min:0',
            'making_charges' => 'nullable|numeric|min:0',
            'stone' => 'nullable|numeric|min:0',
            'stone_amount' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'round_off' => 'nullable|numeric',
            'payments' => 'nullable|array|min:1',
            'payments.*.mode'                => 'required_with:payments|in:cash,upi,bank,old_gold,old_silver,other',
            'payments.*.amount'              => 'required_with:payments|numeric|min:0',
            'payments.*.reference'           => 'nullable|string|max:100',
            'payments.*.metal_gross_weight'  => 'nullable|numeric|min:0',
            'payments.*.metal_purity'        => 'nullable|numeric|min:0',
            'payments.*.metal_test_loss'     => 'nullable|numeric|min:0|max:100',
            'payments.*.metal_rate_per_gram' => 'nullable|numeric|min:0',
            'use_live_rate' => 'nullable|boolean',
            'live_rate_fetched_at' => 'nullable|date',
            'live_rate_metal' => 'nullable|in:gold,silver,platinum',
            'live_rate_purity' => 'nullable|string|max:10',
        ]);

        $making = (float) ($validated['making'] ?? $validated['making_charges'] ?? 0);
        $stone = (float) ($validated['stone'] ?? $validated['stone_amount'] ?? 0);

        $invoice = SalesService::sellItem(
            (int) $validated['customer_id'],
            (int) $validated['item_id'],
            (float) $validated['gold_rate'],
            $making,
            $stone,
            (float) ($validated['discount'] ?? 0),
            (float) ($validated['round_off'] ?? 0),
            $validated['payments'] ?? [],
            0,
            [
                'used_live_rate' => (bool) ($validated['use_live_rate'] ?? false),
                'live_rate_fetched_at' => $validated['live_rate_fetched_at'] ?? null,
                'live_rate_metal' => $validated['live_rate_metal'] ?? 'gold',
                'live_rate_purity' => $validated['live_rate_purity'] ?? '24k',
            ]
        );

        return response()->json([
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ]);
    }
}
