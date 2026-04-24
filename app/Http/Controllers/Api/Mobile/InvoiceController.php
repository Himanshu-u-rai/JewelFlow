<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
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
            'payments:id,invoice_id,mode,amount,reference,metal_type,metal_gross_weight,metal_purity,metal_fine_weight,metal_rate_per_gram,created_at',
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

        $validated = $request->validate([
            'mode'      => 'required|in:cash,upi,bank,other',
            'amount'    => 'required|numeric|min:0.01|max:99999999.99',
            'reference' => 'nullable|string|max:100',
        ]);

        $shopId = (int) $request->user()->shop_id;

        $response = DB::transaction(function () use ($validated, $invoice, $shopId, $request) {
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

            $amount = round((float) $validated['amount'], 2);
            if ($amount > $outstanding) {
                throw ValidationException::withMessages([
                    'amount' => 'Amount exceeds outstanding balance of ' . number_format($outstanding, 2),
                ]);
            }

            $payment = InvoicePayment::record([
                'invoice_id' => $locked->id,
                'shop_id'    => $shopId,
                'mode'       => $validated['mode'],
                'amount'     => $amount,
                'reference'  => $validated['reference'] ?? null,
            ]);

            if ($validated['mode'] === InvoicePayment::MODE_CASH) {
                CashTransaction::record([
                    'shop_id'        => $shopId,
                    'user_id'        => (int) $request->user()->id,
                    'type'           => 'in',
                    'amount'         => $amount,
                    'source_type'    => 'invoice',
                    'source_id'      => $locked->id,
                    'invoice_id'     => $locked->id,
                    'description'    => 'Invoice payment - ' . $locked->invoice_number,
                    'reference_type' => 'invoice',
                    'reference_id'   => $locked->id,
                ]);
            }

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
                    'payment_id'     => (int) $payment->id,
                    'mode'           => $payment->mode,
                    'amount'         => $amount,
                ],
            ]);

            $newPaid = round($paidAmount + $amount, 2);
            $newOutstanding = round(((float) $locked->total) - $newPaid, 2);

            return [
                'payment' => [
                    'id'         => (int) $payment->id,
                    'mode'       => (string) $payment->mode,
                    'amount'     => (float) $payment->amount,
                    'reference'  => $payment->reference,
                    'created_at' => optional($payment->created_at)?->toIso8601String(),
                ],
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
