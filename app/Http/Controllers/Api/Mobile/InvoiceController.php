<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\ShopPaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::query()
            ->with(['customer:id,first_name,last_name,mobile,customer_code'])
            ->withSum('payments as paid_amount', 'amount');

        $allowedStatuses = [
            Invoice::STATUS_DRAFT,
            Invoice::STATUS_FINALIZED,
            Invoice::STATUS_CANCELLED,
        ];

        if ($request->filled('status') && in_array($request->input('status'), $allowedStatuses, true)) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));

            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', function ($cq) use ($search) {
                        $cq->where('first_name', 'ilike', "%{$search}%")
                            ->orWhere('last_name', 'ilike', "%{$search}%")
                            ->orWhere('mobile', 'like', "%{$search}%");
                    });
            });
        }

        $invoices = $query
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 20));

        $invoices->getCollection()->transform(function (Invoice $invoice) {
            $paidAmount = (float) ($invoice->paid_amount ?? 0);
            $totalAmount = (float) ($invoice->total ?? 0);

            return [
                'id' => (int) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'status' => (string) $invoice->status,
                'total' => $totalAmount,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => max(0, $totalAmount - $paidAmount),
                'created_at' => optional($invoice->created_at)?->toIso8601String(),
                'customer' => $invoice->customer ? [
                    'id' => (int) $invoice->customer->id,
                    'name' => trim(($invoice->customer->first_name ?? '') . ' ' . ($invoice->customer->last_name ?? '')),
                    'mobile' => $invoice->customer->mobile,
                    'customer_code' => (string) $invoice->customer->customer_code,
                ] : null,
            ];
        });

        return response()->json($invoices);
    }

    public function show(Invoice $invoice): JsonResponse
    {
        $invoice->load([
            'customer:id,first_name,last_name,mobile,customer_code',
            'items:id,invoice_id,item_id,weight,rate,making_charges,stone_amount,line_total,gst_rate,gst_amount,created_at',
            'items.item:id,barcode,design,category,purity,huid',
            'payments:id,invoice_id,payment_method_id,mode,amount,reference,metal_type,metal_gross_weight,metal_purity,metal_fine_weight,metal_rate_per_gram,created_at',
            'payments.paymentMethod:id,type,name,upi_id,bank_name,account_number,wallet_id',
        ]);

        $paidAmount = (float) $invoice->payments->sum('amount');
        $totalAmount = (float) ($invoice->total ?? 0);

        return response()->json([
            'id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'status' => (string) $invoice->status,
            'created_at' => optional($invoice->created_at)?->toIso8601String(),
            'totals' => [
                'subtotal' => (float) ($invoice->subtotal ?? 0),
                'wastage_charge' => (float) ($invoice->wastage_charge ?? 0),
                'gst' => (float) ($invoice->gst ?? 0),
                'gst_rate' => (float) ($invoice->gst_rate ?? 0),
                'discount' => (float) ($invoice->discount ?? 0),
                'round_off' => (float) ($invoice->round_off ?? 0),
                'total' => $totalAmount,
                'paid_amount' => $paidAmount,
                'outstanding_amount' => max(0, $totalAmount - $paidAmount),
            ],
            'customer' => $invoice->customer ? [
                'id' => (int) $invoice->customer->id,
                'name' => trim(($invoice->customer->first_name ?? '') . ' ' . ($invoice->customer->last_name ?? '')),
                'mobile' => $invoice->customer->mobile,
                'customer_code' => (string) $invoice->customer->customer_code,
            ] : null,
            'items' => $invoice->items->map(function ($line) {
                return [
                    'id' => (int) $line->id,
                    'weight' => (float) ($line->weight ?? 0),
                    'rate' => (float) ($line->rate ?? 0),
                    'making_charges' => (float) ($line->making_charges ?? 0),
                    'stone_amount' => (float) ($line->stone_amount ?? 0),
                    'line_total' => (float) ($line->line_total ?? 0),
                    'gst_rate' => (float) ($line->gst_rate ?? 0),
                    'gst_amount' => (float) ($line->gst_amount ?? 0),
                    'item' => $line->item ? [
                        'id' => (int) $line->item->id,
                        'barcode' => (string) $line->item->barcode,
                        'design' => $line->item->design,
                        'category' => (string) $line->item->category,
                        'purity' => (float) ($line->item->purity ?? 0),
                        'huid' => $line->item->huid,
                    ] : null,
                ];
            })->values(),
            'payments' => $invoice->payments->map(function ($payment) {
                return [
                    'id' => (int) $payment->id,
                    'mode' => (string) $payment->mode,
                    'amount' => (float) ($payment->amount ?? 0),
                    'reference' => $payment->reference,
                    'payment_method_id' => $payment->payment_method_id ? (int) $payment->payment_method_id : null,
                    'payment_method_label' => $payment->paymentMethod?->account_label,
                    'metal_type' => $payment->metal_type,
                    'metal_gross_weight' => $payment->metal_gross_weight !== null ? (float) $payment->metal_gross_weight : null,
                    'metal_purity' => $payment->metal_purity !== null ? (float) $payment->metal_purity : null,
                    'metal_fine_weight' => $payment->metal_fine_weight !== null ? (float) $payment->metal_fine_weight : null,
                    'metal_rate_per_gram' => $payment->metal_rate_per_gram !== null ? (float) $payment->metal_rate_per_gram : null,
                    'created_at' => optional($payment->created_at)?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    /**
     * Record a payment against a finalized invoice. Supports partial
     * collection for credit / follow-up-payment use cases. Metal-exchange
     * (old_gold/old_silver) + EMI/scheme modes are intentionally excluded —
     * those live in dedicated flows.
     */
    public function storePayment(Request $request, Invoice $invoice): JsonResponse
    {
        $idempotencyKey = $request->header('X-Idempotency-Key');
        $cacheKey = $idempotencyKey
            ? "invoice_payment_idempotency:{$invoice->id}:{$idempotencyKey}"
            : null;

        if ($cacheKey) {
            $cached = Cache::get($cacheKey);
            if ($cached) {
                return response()->json($cached);
            }
        }

        $shopId = (int) $request->user()->shop_id;
        $payments = $this->normalizePaymentPayload($request);
        $this->validatePaymentMethods($payments, $shopId);

        $response = DB::transaction(function () use ($payments, $invoice, $shopId, $request) {
            $locked = Invoice::query()->where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== Invoice::STATUS_FINALIZED) {
                throw ValidationException::withMessages([
                    'status' => 'Payments can only be recorded against finalized invoices.',
                ]);
            }

            $paidAmount = (float) InvoicePayment::query()
                ->where('invoice_id', $locked->id)
                ->sum('amount');
            $outstanding = round(((float) $locked->total) - $paidAmount, 2);

            if ($outstanding <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'This invoice is already fully paid.',
                ]);
            }

            $requestedTotal = round(collect($payments)->sum(fn ($payment) => (float) $payment['amount']), 2);
            if ($requestedTotal > $outstanding) {
                throw ValidationException::withMessages([
                    'payments' => 'Total payment amount exceeds outstanding balance of ' . number_format($outstanding, 2),
                ]);
            }

            $createdPaymentIds = [];
            foreach ($payments as $paymentInput) {
                $amount = round((float) $paymentInput['amount'], 2);

                $payment = InvoicePayment::record([
                    'invoice_id' => $locked->id,
                    'shop_id' => $shopId,
                    'mode' => $paymentInput['mode'],
                    'amount' => $amount,
                    'reference' => $paymentInput['reference'] ?? null,
                    'payment_method_id' => $paymentInput['payment_method_id'] ?? null,
                ]);

                $createdPaymentIds[] = (int) $payment->id;

                if ($paymentInput['mode'] === InvoicePayment::MODE_CASH) {
                    CashTransaction::record([
                        'shop_id' => $shopId,
                        'user_id' => (int) $request->user()->id,
                        'type' => 'in',
                        'amount' => $amount,
                        'source_type' => 'invoice',
                        'source_id' => $locked->id,
                        'invoice_id' => $locked->id,
                        'payment_mode' => InvoicePayment::MODE_CASH,
                        'description' => 'Invoice payment - ' . $locked->invoice_number,
                        'reference_type' => 'invoice',
                        'reference_id' => $locked->id,
                    ]);
                }
            }

            $createdPayments = InvoicePayment::query()
                ->whereIn('id', $createdPaymentIds)
                ->with('paymentMethod:id,type,name,upi_id,bank_name,account_number,wallet_id')
                ->orderBy('id')
                ->get();

            AuditLog::create([
                'shop_id'     => $shopId,
                'user_id'     => (int) $request->user()->id,
                'action'      => 'invoice_payment_recorded',
                'model_type'  => 'invoice',
                'model_id'    => (int) $locked->id,
                'description' => 'Invoice payment recorded from mobile app.',
                'data'        => [
                    'source'         => 'mobile_app',
                    'invoice_number' => $locked->invoice_number,
                    'payment_ids'    => $createdPaymentIds,
                    'payment_count'  => count($createdPaymentIds),
                    'amount'         => $requestedTotal,
                ],
            ]);

            $newPaid = round($paidAmount + $requestedTotal, 2);
            $newOutstanding = round(((float) $locked->total) - $newPaid, 2);

            $paymentsPayload = $createdPayments->map(function (InvoicePayment $payment) {
                return [
                    'id' => (int) $payment->id,
                    'mode' => (string) $payment->mode,
                    'amount' => (float) $payment->amount,
                    'reference' => $payment->reference,
                    'payment_method_id' => $payment->payment_method_id ? (int) $payment->payment_method_id : null,
                    'payment_method_label' => $payment->paymentMethod?->account_label,
                    'created_at' => optional($payment->created_at)?->toIso8601String(),
                ];
            })->values()->all();

            return [
                'payment' => $paymentsPayload[0] ?? null,
                'payments' => $paymentsPayload,
                'totals' => [
                    'total'              => (float) $locked->total,
                    'paid_amount'        => $newPaid,
                    'outstanding_amount' => max(0, $newOutstanding),
                ],
            ];
        });

        if ($cacheKey) {
            Cache::put($cacheKey, $response, now()->addHours(24));
        }

        return response()->json($response, 201);
    }

    private function normalizePaymentPayload(Request $request): array
    {
        if ($request->has('payments')) {
            $validated = $request->validate([
                'payments' => 'required|array|min:1',
                'payments.*.mode' => 'required|in:cash,upi,bank,other',
                'payments.*.amount' => 'required|numeric|min:0.01|max:99999999.99',
                'payments.*.reference' => 'nullable|string|max:100',
                'payments.*.payment_method_id' => 'nullable|integer',
            ]);

            return array_map(function (array $payment): array {
                return [
                    'mode' => (string) $payment['mode'],
                    'amount' => (float) $payment['amount'],
                    'reference' => $payment['reference'] ?? null,
                    'payment_method_id' => $payment['payment_method_id'] ?? null,
                ];
            }, $validated['payments']);
        }

        $validated = $request->validate([
            'mode' => 'required|in:cash,upi,bank,other',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'reference' => 'nullable|string|max:100',
            'payment_method_id' => 'nullable|integer',
        ]);

        return [[
            'mode' => (string) $validated['mode'],
            'amount' => (float) $validated['amount'],
            'reference' => $validated['reference'] ?? null,
            'payment_method_id' => $validated['payment_method_id'] ?? null,
        ]];
    }

    private function validatePaymentMethods(array $payments, int $shopId): void
    {
        $paymentMethodIds = collect($payments)
            ->pluck('payment_method_id')
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($paymentMethodIds->isEmpty()) {
            return;
        }

        $methodsById = ShopPaymentMethod::query()
            ->where('shop_id', $shopId)
            ->whereIn('id', $paymentMethodIds)
            ->get(['id', 'type'])
            ->keyBy('id');

        $modeTypeMap = [
            InvoicePayment::MODE_UPI => ShopPaymentMethod::TYPE_UPI,
            InvoicePayment::MODE_BANK => ShopPaymentMethod::TYPE_BANK,
        ];

        foreach ($payments as $index => $payment) {
            $methodId = $payment['payment_method_id'] ?? null;
            if ($methodId === null || $methodId === '') {
                continue;
            }

            $methodId = (int) $methodId;
            $method = $methodsById->get($methodId);
            if (!$method) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payment_method_id" => 'Selected payment method is invalid for this shop.',
                ]);
            }

            $mode = (string) ($payment['mode'] ?? '');
            $expectedType = $modeTypeMap[$mode] ?? null;
            if ($expectedType === null || $method->type !== $expectedType) {
                throw ValidationException::withMessages([
                    "payments.{$index}.payment_method_id" => "Payment method type must match mode \"{$mode}\".",
                ]);
            }
        }
    }

    public function template(Invoice $invoice): JsonResponse
    {
        $invoice->load([
            'customer',
            'items.item',
            'payments',
            'offerApplication',
            'schemeRedemptions',
        ]);

        $html = view('invoice_print', [
            'invoice' => $invoice,
        ])->render();

        return response()->json([
            'id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'status' => (string) $invoice->status,
            'created_at' => optional($invoice->created_at)?->toIso8601String(),
            'html' => $html,
        ]);
    }
}
