<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoicePaymentClaim;
use App\Models\ShopPaymentMethod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Money received later against a finalized invoice's due. One path for the
 * web invoice page and the app (Api\Mobile\InvoiceController::storePayment),
 * so both record the same rows: an InvoicePayment per money mode and a cash
 * book row for each, carrying its account like a sale does.
 */
class InvoiceDueService
{
    public const MODES = [InvoicePayment::MODE_CASH, InvoicePayment::MODE_UPI, InvoicePayment::MODE_BANK, 'other'];

    /** Validation rules for a `payments` list (split payment). */
    public static function rules(int $shopId): array
    {
        return [
            'payments' => 'required|array|min:1',
            'payments.*.mode' => 'required|in:' . implode(',', self::MODES),
            'payments.*.amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payments.*.reference' => 'nullable|string|max:100',
            'payments.*.payment_method_id' => [
                'nullable', 'integer',
                Rule::exists('shop_payment_methods', 'id')->where('shop_id', $shopId),
            ],
        ];
    }

    /** UPI and bank need an account of that type; cash and other take none. */
    public function validatePaymentMethods(array $payments, int $shopId): void
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

    /**
     * Record a collection at most once per request key: the web form's twin of the
     * app endpoint's durable claim (same table, same (invoice_id, key) identity,
     * same rules). The claim is staked inside the payment transaction BEFORE any
     * business check, so a resubmission after a lost response, or a concurrent
     * identical submission, finds the committed claim and replays it instead of
     * charging again. A refused attempt rolls its claim back, freeing the key.
     *
     * @return array{outcome: 'recorded'|'replayed'|'conflict', body: array{total?: float, payment_ids?: int[]}}
     */
    public function collectOnce(Invoice $invoice, array $payments, int $userId, string $source, string $key, string $requestHash): array
    {
        $claim = $this->findClaim($invoice, $key);
        if ($claim !== null) {
            return $this->replay($claim, $requestHash);
        }

        try {
            $body = DB::transaction(function () use ($invoice, $payments, $userId, $source, $key, $requestHash) {
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                $claim = InvoicePaymentClaim::create([
                    'invoice_id' => (int) $locked->id,
                    'shop_id' => (int) $locked->shop_id,
                    'user_id' => $userId,
                    'key' => $key,
                    'request_hash' => $requestHash,
                    'response_status' => 201,
                    'response_body' => null,
                ]);

                $result = $this->collect($locked, $payments, $userId, $source);
                $body = ['source' => $source, 'total' => $result['total'], 'payment_ids' => $result['payment_ids']];
                $claim->update(['response_body' => $body]);

                return $body;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Lost the race to a concurrent submission of the same form. Only our
            // own index proves that; anything else is a real error.
            if (! str_contains($e->getMessage(), 'invoice_payment_claims_invoice_key_unique')) {
                throw $e;
            }
            $winner = $this->findClaim($invoice, $key);
            if ($winner === null) {
                throw $e;
            }

            return $this->replay($winner, $requestHash);
        }

        return ['outcome' => 'recorded', 'body' => $body];
    }

    /** A replay answers with the original result only for the same payload. */
    private function replay(InvoicePaymentClaim $claim, string $requestHash): array
    {
        if (! hash_equals((string) $claim->request_hash, $requestHash)) {
            return ['outcome' => 'conflict', 'body' => []];
        }

        return ['outcome' => 'replayed', 'body' => (array) $claim->response_body];
    }

    /**
     * Fails closed: a lookup error or a claim from another shop is a refusal,
     * never "no claim" (which would mean "take the payment again").
     */
    private function findClaim(Invoice $invoice, string $key): ?InvoicePaymentClaim
    {
        try {
            $claim = InvoicePaymentClaim::query()->where('invoice_id', $invoice->id)->where('key', $key)->first();
        } catch (\Throwable $e) {
            Log::error('collectOnce: payment claim lookup failed', ['invoice_id' => $invoice->id, 'error' => $e->getMessage()]);
            abort(503, 'The payment could not be checked right now. Please try again.');
        }

        if ($claim !== null && (int) $claim->shop_id !== (int) $invoice->shop_id) {
            Log::error('collectOnce: payment claim shop mismatch', ['invoice_id' => $invoice->id, 'claim_shop_id' => (int) $claim->shop_id]);
            abort(409, 'This payment could not be verified. Please contact support before retrying.');
        }

        return $claim;
    }

    /**
     * Record the payments. Runs inside the caller's transaction, on an invoice
     * row the caller has already locked (the app stakes its idempotency claim
     * between the lock and this call).
     *
     * @param  array<int, array{mode: string, amount: float|string, reference?: ?string, payment_method_id?: ?int}>  $payments
     * @return array{paid_before: float, total: float, payment_ids: int[]}
     */
    public function collect(Invoice $locked, array $payments, int $userId, string $source): array
    {
        if ($locked->status !== Invoice::STATUS_FINALIZED) {
            throw ValidationException::withMessages([
                'status' => 'Payments can only be recorded against finalized invoices.',
            ]);
        }

        if (InstallmentPlan::activeFor($locked)) {
            throw ValidationException::withMessages(['payments' => InstallmentPlan::COLLECT_THROUGH_PLAN]);
        }

        try {
            InvoiceAccountingService::assertShopLockForDate((int) $locked->shop_id, now()->toDateString());
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['payments' => $e->getMessage()]);
        }

        $paidBefore = (float) InvoicePayment::query()->where('invoice_id', $locked->id)->sum('amount');
        $outstanding = round(((float) $locked->total) - $paidBefore, 2);

        if ($outstanding <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'This invoice is already fully paid.',
            ]);
        }

        $total = round(collect($payments)->sum(fn ($payment) => (float) $payment['amount']), 2);
        if ($total > $outstanding) {
            throw ValidationException::withMessages([
                'payments' => 'Total payment amount exceeds outstanding balance of ' . number_format($outstanding, 2),
            ]);
        }

        $paymentIds = [];
        foreach ($payments as $input) {
            $amount = round((float) $input['amount'], 2);
            $methodId = $input['payment_method_id'] ?? null;

            $paymentIds[] = (int) InvoicePayment::record([
                'invoice_id' => $locked->id,
                'shop_id' => $locked->shop_id,
                'mode' => $input['mode'],
                'amount' => $amount,
                'reference' => $input['reference'] ?? null,
                'payment_method_id' => $methodId,
            ])->id;

            // Every money mode reaches the cash book, as a sale's and an EMI's do.
            CashTransaction::record([
                'shop_id' => $locked->shop_id,
                'user_id' => $userId,
                'type' => 'in',
                'amount' => $amount,
                'source_type' => 'invoice',
                'source_id' => $locked->id,
                'invoice_id' => $locked->id,
                'payment_mode' => $input['mode'],
                ...CashTransaction::accountColumns($methodId ? (int) $methodId : null),
                'description' => 'Invoice payment - ' . $locked->invoice_number,
                'reference_type' => 'invoice',
                'reference_id' => $locked->id,
            ]);
        }

        AuditLog::create([
            'shop_id' => $locked->shop_id,
            'user_id' => $userId,
            'action' => 'invoice_payment_recorded',
            'model_type' => 'invoice',
            'model_id' => (int) $locked->id,
            'description' => $source === 'web' ? 'Invoice payment recorded on the web.' : 'Invoice payment recorded from mobile app.',
            'data' => [
                'source' => $source,
                'invoice_number' => $locked->invoice_number,
                'payment_ids' => $paymentIds,
                'payment_count' => count($paymentIds),
                'amount' => $total,
            ],
        ]);

        return ['paid_before' => $paidBefore, 'total' => $total, 'payment_ids' => $paymentIds];
    }
}
