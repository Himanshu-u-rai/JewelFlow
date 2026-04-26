<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\QuickBill;
use App\Services\QuickBillService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class QuickBillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = QuickBill::query()->with([
            'customer:id,first_name,last_name,mobile',
            'creator:id,name,mobile_number',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $search = $request->string('search')->toString();
            $query->where(function ($builder) use ($search): void {
                $builder->where('bill_number', 'ilike', "%{$search}%")
                    ->orWhere('customer_name', 'ilike', "%{$search}%")
                    ->orWhere('customer_mobile', 'like', "%{$search}%")
                    ->orWhereHas('customer', function ($customerQuery) use ($search): void {
                        $customerQuery->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        if ($request->filled('from_date')) {
            $query->whereDate('bill_date', '>=', $request->date('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('bill_date', '<=', $request->date('to_date'));
        }

        $perPage = (int) $request->integer('per_page', 20);
        if ($perPage <= 0) {
            $perPage = 20;
        }
        $perPage = min($perPage, 100);

        $quickBills = $query
            ->orderByDesc('bill_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        $quickBills->getCollection()->transform(fn (QuickBill $quickBill) => $this->transformListItem($quickBill));

        $shopId = (int) $request->user()->shop_id;
        $stats = [
            'total_count' => QuickBill::query()->where('shop_id', $shopId)->count(),
            'issued_count' => QuickBill::query()->where('shop_id', $shopId)->where('status', QuickBill::STATUS_ISSUED)->count(),
            'draft_count' => QuickBill::query()->where('shop_id', $shopId)->where('status', QuickBill::STATUS_DRAFT)->count(),
            'today_total' => QuickBill::query()
                ->where('shop_id', $shopId)
                ->whereDate('bill_date', today())
                ->where('status', '!=', QuickBill::STATUS_VOID)
                ->sum('total_amount'),
            'outstanding_total' => QuickBill::query()
                ->where('shop_id', $shopId)
                ->where('status', '!=', QuickBill::STATUS_VOID)
                ->sum('due_amount'),
        ];

        return response()->json([
            'data' => $quickBills->items(),
            'current_page' => $quickBills->currentPage(),
            'last_page' => $quickBills->lastPage(),
            'per_page' => $quickBills->perPage(),
            'total' => $quickBills->total(),
            'stats' => $stats,
        ]);
    }

    public function show(QuickBill $quickBill): JsonResponse
    {
        $quickBill->load('customer', 'items', 'payments.paymentMethod', 'creator');

        return response()->json($this->transformDetail($quickBill));
    }

    public function store(Request $request, QuickBillService $quickBillService): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $quickBill = $quickBillService->create($request->user()->shop, $request->user(), $payload);

        return response()->json([
            'message' => 'Quick bill saved successfully.',
            'quick_bill' => $this->transformDetail($quickBill),
        ], 201);
    }

    public function update(Request $request, QuickBill $quickBill, QuickBillService $quickBillService): JsonResponse
    {
        $payload = $this->validatedPayload($request);
        $quickBill = $quickBillService->update($quickBill, $request->user()->shop, $request->user(), $payload);

        return response()->json([
            'message' => 'Quick bill updated successfully.',
            'quick_bill' => $this->transformDetail($quickBill),
        ]);
    }

    public function void(Request $request, QuickBill $quickBill, QuickBillService $quickBillService): JsonResponse
    {
        $data = $request->validate([
            'void_reason' => 'nullable|string|max:1000',
        ]);

        $quickBill = $quickBillService->void($quickBill, $request->user(), $data['void_reason'] ?? null);

        return response()->json([
            'message' => 'Quick bill voided successfully.',
            'quick_bill' => $this->transformDetail($quickBill),
        ]);
    }

    public function template(QuickBill $quickBill): JsonResponse
    {
        $quickBill->load('customer', 'items', 'payments.paymentMethod');

        $html = view('quick-bills.print', [
            'quickBill' => $quickBill,
        ])->render();

        return response()->json([
            'id' => (int) $quickBill->id,
            'bill_number' => (string) $quickBill->bill_number,
            'status' => (string) $quickBill->status,
            'bill_date' => optional($quickBill->bill_date)?->toDateString(),
            'html' => $html,
        ]);
    }

    private function validatedPayload(Request $request): array
    {
        $shopId = (int) $request->user()->shop_id;

        return $request->validate([
            'customer_id' => ['nullable', Rule::exists('customers', 'id')->where('shop_id', $shopId)],
            'customer_name' => 'nullable|string|max:255',
            'customer_mobile' => 'nullable|string|max:20',
            'customer_address' => 'nullable|string|max:1000',
            'bill_date' => 'required|date',
            'pricing_mode' => 'required|in:no_gst,gst_exclusive,gst_inclusive',
            'gst_rate' => 'required|numeric|min:0|max:100',
            'discount_type' => 'nullable|in:fixed,percent',
            'discount_value' => 'nullable|numeric|min:0|max:9999999.99',
            'round_off' => 'nullable|numeric|min:-9999|max:9999',
            'notes' => 'nullable|string|max:5000',
            'terms' => 'nullable|string|max:5000',
            'save_action' => 'nullable|in:draft,issue',
            'items' => 'required|array|min:1',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.hsn_code' => 'nullable|string|max:40',
            'items.*.metal_type' => 'nullable|string|max:30',
            'items.*.purity' => 'nullable|string|max:30',
            'items.*.pcs' => 'nullable|integer|min:1|max:9999',
            'items.*.gross_weight' => 'nullable|numeric|min:0|max:999999.999',
            'items.*.stone_weight' => 'nullable|numeric|min:0|max:999999.999',
            'items.*.net_weight' => 'nullable|numeric|min:0|max:999999.999',
            'items.*.rate' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.making_charge' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.stone_charge' => 'nullable|numeric|min:0|max:9999999.99',
            'items.*.wastage_percent' => 'nullable|numeric|min:0|max:1000',
            'items.*.line_discount' => 'nullable|numeric|min:0|max:9999999.99',
            'payments' => 'nullable|array',
            'payments.*.payment_mode' => 'nullable|string|max:30',
            'payments.*.payment_method_id' => ['nullable', 'integer', Rule::exists('shop_payment_methods', 'id')->where('shop_id', $shopId)],
            'payments.*.reference_no' => 'nullable|string|max:100',
            'payments.*.amount' => 'nullable|numeric|min:0|max:9999999.99',
            'payments.*.notes' => 'nullable|string|max:500',
        ]);
    }

    private function transformListItem(QuickBill $quickBill): array
    {
        return [
            'id' => (int) $quickBill->id,
            'bill_number' => (string) $quickBill->bill_number,
            'status' => (string) $quickBill->status,
            'bill_date' => optional($quickBill->bill_date)?->toDateString(),
            'pricing_mode' => (string) $quickBill->pricing_mode,
            'total_amount' => (float) ($quickBill->total_amount ?? 0),
            'paid_amount' => (float) ($quickBill->paid_amount ?? 0),
            'due_amount' => (float) ($quickBill->due_amount ?? 0),
            'customer_name' => $quickBill->customer_name,
            'customer_mobile' => $quickBill->customer_mobile,
            'customer' => $quickBill->customer ? [
                'id' => (int) $quickBill->customer->id,
                'name' => trim(($quickBill->customer->first_name ?? '') . ' ' . ($quickBill->customer->last_name ?? '')),
                'mobile' => $quickBill->customer->mobile,
            ] : null,
            'created_at' => optional($quickBill->created_at)?->toIso8601String(),
            'issued_at' => optional($quickBill->issued_at)?->toIso8601String(),
        ];
    }

    private function transformDetail(QuickBill $quickBill): array
    {
        $quickBill->loadMissing('customer', 'items', 'payments.paymentMethod', 'creator');

        return [
            'id' => (int) $quickBill->id,
            'bill_number' => (string) $quickBill->bill_number,
            'status' => (string) $quickBill->status,
            'bill_date' => optional($quickBill->bill_date)?->toDateString(),
            'pricing_mode' => (string) $quickBill->pricing_mode,
            'gst_rate' => (float) ($quickBill->gst_rate ?? 0),
            'customer_id' => $quickBill->customer_id ? (int) $quickBill->customer_id : null,
            'customer_name' => $quickBill->customer_name,
            'customer_mobile' => $quickBill->customer_mobile,
            'customer_address' => $quickBill->customer_address,
            'notes' => $quickBill->notes,
            'terms' => $quickBill->terms,
            'void_reason' => $quickBill->void_reason,
            'issued_at' => optional($quickBill->issued_at)?->toIso8601String(),
            'voided_at' => optional($quickBill->voided_at)?->toIso8601String(),
            'created_at' => optional($quickBill->created_at)?->toIso8601String(),
            'customer' => $quickBill->customer ? [
                'id' => (int) $quickBill->customer->id,
                'name' => trim(($quickBill->customer->first_name ?? '') . ' ' . ($quickBill->customer->last_name ?? '')),
                'mobile' => $quickBill->customer->mobile,
            ] : null,
            'totals' => [
                'subtotal' => (float) ($quickBill->subtotal ?? 0),
                'discount_type' => $quickBill->discount_type,
                'discount_value' => $quickBill->discount_value !== null ? (float) $quickBill->discount_value : null,
                'discount_amount' => (float) ($quickBill->discount_amount ?? 0),
                'round_off' => (float) ($quickBill->round_off ?? 0),
                'taxable_amount' => (float) ($quickBill->taxable_amount ?? 0),
                'cgst_amount' => (float) ($quickBill->cgst_amount ?? 0),
                'sgst_amount' => (float) ($quickBill->sgst_amount ?? 0),
                'igst_amount' => (float) ($quickBill->igst_amount ?? 0),
                'total_amount' => (float) ($quickBill->total_amount ?? 0),
                'paid_amount' => (float) ($quickBill->paid_amount ?? 0),
                'due_amount' => (float) ($quickBill->due_amount ?? 0),
            ],
            'items' => $quickBill->items->map(fn ($item) => [
                'id' => (int) $item->id,
                'sort_order' => (int) ($item->sort_order ?? 0),
                'description' => (string) $item->description,
                'hsn_code' => $item->hsn_code,
                'metal_type' => $item->metal_type,
                'purity' => $item->purity,
                'pcs' => (int) ($item->pcs ?? 1),
                'gross_weight' => (float) ($item->gross_weight ?? 0),
                'stone_weight' => (float) ($item->stone_weight ?? 0),
                'net_weight' => (float) ($item->net_weight ?? 0),
                'rate' => (float) ($item->rate ?? 0),
                'making_charge' => (float) ($item->making_charge ?? 0),
                'stone_charge' => (float) ($item->stone_charge ?? 0),
                'wastage_percent' => (float) ($item->wastage_percent ?? 0),
                'line_discount' => (float) ($item->line_discount ?? 0),
                'line_total' => (float) ($item->line_total ?? 0),
            ])->values(),
            'payments' => $quickBill->payments->map(fn ($payment) => [
                'id' => (int) $payment->id,
                'payment_mode' => (string) $payment->payment_mode,
                'payment_method_id' => $payment->payment_method_id ? (int) $payment->payment_method_id : null,
                'payment_method_label' => $payment->paymentMethod?->account_label,
                'reference_no' => $payment->reference_no,
                'amount' => (float) ($payment->amount ?? 0),
                'paid_at' => optional($payment->paid_at)?->toIso8601String(),
                'notes' => $payment->notes,
            ])->values(),
        ];
    }
}
