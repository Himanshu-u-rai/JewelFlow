<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoicePaymentClaim;
use App\Models\ShopPaymentMethod;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
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

    public function show(Invoice $invoice, Request $request): JsonResponse
    {
        if ((int) $invoice->shop_id !== (int) $request->user()->shop_id) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $invoice->load([
            'customer:id,first_name,last_name,mobile,customer_code',
            'items:id,invoice_id,item_id,weight,rate,making_charges,making_charge_type,making_charge_value,stone_amount,line_total,gst_rate,gst_amount,created_at',
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
                    'making_charge_type' => $line->making_charge_type,
                    'making_charge_value' => $line->making_charge_value !== null ? (float) $line->making_charge_value : null,
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
     * S3-07: authorize a cache HIT the same way fresh processing is authorized.
     *
     * The idempotency key is `invoice_payment_idempotency:{invoice}:{key}` —
     * no shop, no user. That is deliberately left alone: changing the key shape
     * makes every in-flight key miss across the deploy window, and a client
     * retrying a partial payment in that window would have it recorded twice.
     * So the key, its TTL and its replay semantics are untouched, and the
     * missing check is added here instead — the cached body is a payment
     * receipt, and returning one is an access decision whether or not the
     * database is touched.
     *
     * Three conditions, in this order:
     *
     *  1. A trusted active tenant must exist. `TenantContext` is set by the
     *     `tenant` middleware; if this action is ever reached without it, the
     *     answer is a refusal, NOT a fallback to `$request->user()->shop_id`.
     *     Fail closed, matching BelongsToShop's own `whereRaw('1 = 0')` stance.
     *  2. The invoice must belong to that tenant. 404 rather than 403, because
     *     that is the answer the scoped route binding already gives for
     *     somebody else's invoice — a 403 here would confirm the row exists.
     *  3. The caller must hold `sales.create` AND pass InvoicePolicy::view —
     *     the same permission the route's `can:` middleware requires, checked
     *     again here so the cache-hit path does not depend on route middleware
     *     configuration staying correct.
     *
     * Why this is defence in depth and not an incident fix: with the shipped
     * scoped binding, another shop's request 404s before reaching this method.
     * The gap was that the cache-hit path had exactly one guard (that binding)
     * where the write path has two — it also re-resolves the invoice through a
     * tenant-scoped `lockForUpdate()`. This closes that asymmetry.
     */
    private function authorizeCachedPaymentReplay(Request $request, Invoice $invoice): void
    {
        $tenantShopId = TenantContext::get();

        abort_if($tenantShopId === null, 403, 'No active shop context.');
        abort_if((int) $invoice->shop_id !== (int) $tenantShopId, 404);

        $user = $request->user();

        abort_unless($user?->can('sales.create') && $user->can('view', $invoice), 403);
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

        // Same hash shape as EnsureIdempotency: METHOD|path|raw body. Using the
        // RAW body rather than the normalized payload keeps this consistent with
        // the mechanism already in the repo, and keeps the hash computable here,
        // BEFORE validation runs — which matters because a replay must be
        // answerable without re-validating a payload the client may have built
        // slightly differently. The cost is that two byte-different encodings of
        // the same logical payment read as a conflict rather than a replay; for a
        // client retrying its own serialized request that does not arise.
        $requestHash = $idempotencyKey
            ? hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent())
            : null;

        if ($idempotencyKey) {
            // 1. DURABLE claim first. This is the record written inside the
            //    payment transaction, so its presence is proof the payment
            //    committed — which the cache entry never was.
            $claim = $this->findPaymentClaim((int) $invoice->id, $idempotencyKey);

            if ($claim !== null) {
                $this->authorizeCachedPaymentReplay($request, $invoice);

                // Changed parameters under a key that was already used is a
                // conflict, not a replay. Answering 200 with the ORIGINAL
                // receipt (the pre-repair behaviour) tells the operator the
                // amount they just keyed in succeeded when it was discarded.
                if (! hash_equals((string) $claim->request_hash, (string) $requestHash)) {
                    return response()->json([
                        'errors' => [[
                            'code' => 'idempotency_key_conflict',
                            'message' => 'This idempotency key was already used with a different payment payload.',
                        ]],
                    ], 409);
                }

                // 200, NOT the stored 201.
                //
                // Replaying the original status was the first version of this
                // and it broke I-06, which encodes the contract clients in the
                // field already depend on: a replayed receipt comes back 200.
                // That test was right and the code was wrong. A replay did not
                // create anything *now*, so 201 would also be a worse answer on
                // its own terms. The status is stored for the record; the fact
                // that this is a replay is carried by the header, which is
                // additive and breaks nobody.
                //
                // Keeping 200 here also makes a replay indistinguishable in
                // status whether the durable claim or the legacy cache answered
                // it — during the compatibility window both are live.
                return response()
                    ->json($claim->response_body, 200)
                    ->header('X-Idempotent-Replay', 'true');
            }

            // 2. LEGACY cache entry, for keys in flight across the deploy.
            //
            //    COMPATIBILITY LIMIT, STATED PRECISELY. A legacy entry holds the
            //    response body and nothing else — the original payload was never
            //    hashed, so for these entries there is no evidence against which
            //    a changed payload could be detected. They therefore replay (the
            //    pre-repair behaviour, which at least does not double-charge) and
            //    CANNOT answer 409. Deriving a hash from the replayed body would
            //    manufacture the missing evidence rather than recover it. The gap
            //    is bounded by the unchanged 24h TTL and closes by expiry.
            $cached = Cache::get($cacheKey);
            if ($cached) {
                $this->authorizeCachedPaymentReplay($request, $invoice);

                return response()->json($cached)->header('X-Idempotent-Replay', 'true');
            }
        }

        $shopId = (int) $request->user()->shop_id;
        $payments = $this->normalizePaymentPayload($request);
        $this->validatePaymentMethods($payments, $shopId);

        $commitPayment = function () use ($payments, $invoice, $shopId, $request, $idempotencyKey, $requestHash) {
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

            $payload = [
                'payment' => $paymentsPayload[0] ?? null,
                'payments' => $paymentsPayload,
                'totals' => [
                    'total'              => (float) $locked->total,
                    'paid_amount'        => $newPaid,
                    'outstanding_amount' => max(0, $newOutstanding),
                ],
            ];

            // THE REPAIR. The replay record commits WITH the payment or not at
            // all. Previously this was a `Cache::put` after the transaction
            // returned: a separate write, to a separate store, that could simply
            // not happen. Anything that landed the commit without it left a
            // charged customer and no evidence, and the next retry of the key
            // was indistinguishable from a first request.
            //
            // Placed at the END of the transaction rather than the start because
            // the `lockForUpdate` above already serializes concurrent requests
            // against this invoice. By the time a second worker reaches this
            // INSERT the first has committed, so the unique index rejects it
            // immediately and rolls back this worker's payment INSERT with it.
            if ($idempotencyKey !== null) {
                InvoicePaymentClaim::create([
                    'invoice_id'      => (int) $locked->id,
                    'shop_id'         => $shopId,
                    'user_id'         => (int) $request->user()->id,
                    'key'             => $idempotencyKey,
                    'request_hash'    => $requestHash,
                    'response_status' => 201,
                    'response_body'   => $payload,
                ]);
            }

            return $payload;
        };

        try {
            $response = DB::transaction($commitPayment);
        } catch (UniqueConstraintViolationException $e) {
            // LOST THE RACE. A concurrent worker committed this (invoice, key)
            // between our claim lookup and our INSERT. Our whole transaction —
            // including our payment row — has already rolled back, so there is
            // nothing to undo; the winner's payment is the only one that exists.
            //
            // Replay the winner rather than surfacing a 500. Returning the
            // winner's receipt is the correct answer to this request: the
            // payment the client asked for did happen, once.
            $winner = $idempotencyKey !== null
                ? $this->findPaymentClaim((int) $invoice->id, $idempotencyKey)
                : null;

            if ($winner === null) {
                // Not our unique index — something else collided. Do not
                // swallow it; a unique violation we cannot explain must not be
                // reported to the client as a successful payment.
                throw $e;
            }

            $this->authorizeCachedPaymentReplay($request, $invoice);

            // 200 for the same reason as the claim-replay path above.
            return response()
                ->json($winner->response_body, 200)
                ->header('X-Idempotent-Replay', 'true');
        }

        // The cache is kept, unchanged, as a read accelerator in front of the
        // durable claim. Its key shape and 24h TTL are deliberately untouched:
        // changing either would make every in-flight legacy key miss across the
        // deploy window, which is the precise condition that double-charges.
        if ($cacheKey) {
            Cache::put($cacheKey, $response, now()->addHours(24));
        }

        return response()->json($response, 201);
    }

    /**
     * Look up a durable claim, failing CLOSED as a REFUSAL rather than as a miss.
     *
     * The distinction is the whole point. `null` from this method means "no
     * claim exists, proceed to take the payment". If a database error were
     * allowed to produce `null`, an unreachable database would become a double
     * charge. So the error path aborts the request instead of returning.
     *
     * Not scoped by `BelongsToShop` — see the note on InvoicePaymentClaim for
     * why a fail-closed global scope is actively unsafe on a dedup lookup. The
     * tenant check is upstream (scoped route binding) and downstream
     * (`authorizeCachedPaymentReplay` before any body is returned).
     */
    private function findPaymentClaim(int $invoiceId, string $key): ?InvoicePaymentClaim
    {
        try {
            return InvoicePaymentClaim::query()
                ->where('invoice_id', $invoiceId)
                ->where('key', $key)
                ->first();
        } catch (\Throwable $e) {
            Log::error('storePayment: idempotency claim lookup failed', [
                'invoice_id' => $invoiceId,
                'error' => $e->getMessage(),
            ]);

            abort(503, 'Payment idempotency check is temporarily unavailable. Please retry.');
        }
    }

    private function normalizePaymentPayload(Request $request): array
    {
        $shopId = (int) $request->user()->shop_id;

        if ($request->has('payments')) {
            $validated = $request->validate([
                'payments' => 'required|array|min:1',
                'payments.*.mode' => 'required|in:cash,upi,bank,other',
                'payments.*.amount' => 'required|numeric|min:0.01|max:99999999.99',
                'payments.*.reference' => 'nullable|string|max:100',
                'payments.*.payment_method_id' => [
                    'nullable', 'integer',
                    Rule::exists('shop_payment_methods', 'id')->where('shop_id', $shopId),
                ],
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
            'payment_method_id' => [
                'nullable', 'integer',
                Rule::exists('shop_payment_methods', 'id')->where('shop_id', $shopId),
            ],
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

        $methodsById = $paymentMethodIds->isEmpty()
            ? collect()
            : ShopPaymentMethod::query()
                ->where('shop_id', $shopId)
                ->whereIn('id', $paymentMethodIds)
                ->get(['id', 'type'])
                ->keyBy('id');

        $modeTypeMap = [
            InvoicePayment::MODE_UPI => ShopPaymentMethod::TYPE_UPI,
            InvoicePayment::MODE_BANK => ShopPaymentMethod::TYPE_BANK,
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

    public function template(Invoice $invoice, Request $request): JsonResponse
    {
        if ((int) $invoice->shop_id !== (int) $request->user()->shop_id) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

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

        // S3-04. The HTML now carries the signature bytes inline, and the mobile
        // client prints it with no screen to show the web banner on — so the
        // operator warning has to ride the JSON instead. Resolved from the same
        // request-scoped renderer the view already used, so this re-reads nothing.
        $signature = app(\App\Services\InvoiceSignatureRenderer::class)->forInvoice($invoice);

        return response()->json([
            'id' => (int) $invoice->id,
            'invoice_number' => (string) $invoice->invoice_number,
            'status' => (string) $invoice->status,
            'created_at' => optional($invoice->created_at)?->toIso8601String(),
            'html' => $html,
            // Deliberately NOT the bytes — the client already has them inside
            // `html`. This is only the state the operator must be told about.
            'signature' => [
                'expected'  => $signature['show'],
                'available' => $signature['available'],
                'reason'    => $signature['reason'],
            ],
        ]);
    }
}
