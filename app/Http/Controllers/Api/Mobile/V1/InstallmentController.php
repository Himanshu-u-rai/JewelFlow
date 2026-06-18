<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\InstallmentPlan;
use App\Models\Invoice;
use App\Models\ShopPaymentMethod;
use App\Services\InstallmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Mobile v1 — EMI / Installments.
 *
 * Native equivalents of the web installment flow, reusing the SAME service
 * (InstallmentService) so the accounting is identical — this controller only
 * authorizes, shapes input, and formats output. The mobile.envelope middleware
 * wraps responses in {data, meta, errors}; mobile.idempotency protects the POST
 * mutations.
 *
 * EMI is a two-step flow: POS creates a DRAFT invoice (mode:'emi' on /pos/sell),
 * then the app finalizes that draft into a plan via `finalizeDraft` here. Items
 * stay in_stock through the draft; finalize moves them to sold. The invoice is
 * carried from POS — never chosen — so a plan can't be finalized against the
 * wrong draft.
 *
 * Account linkage: a non-cash down payment / EMI payment (upi/bank/wallet) names
 * a specific ShopPaymentMethod (payment_method_id), matching POS.
 */
class InstallmentController extends Controller
{
    public function __construct(private InstallmentService $installmentService) {}

    /** GET /api/mobile/v1/installments — list plans (cursor paginated). */
    public function index(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $query = InstallmentPlan::where('shop_id', $shopId)
            ->with(['customer:id,first_name,last_name,mobile', 'invoice:id,invoice_number'])
            ->orderByDesc('id');

        if (in_array($request->input('status'), ['active', 'completed', 'defaulted'], true)) {
            $query->where('status', $request->input('status'));
        }

        $paginator = $query->cursorPaginate((int) min(50, max(1, (int) $request->input('per_page', 20))));

        return response()->json([
            'plans' => collect($paginator->items())->map(fn ($p) => $this->presentSummary($p))->values(),
            'pagination' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'page_size'   => $paginator->perPage(),
                'has_more'    => $paginator->hasMorePages(),
            ],
        ]);
    }

    /** GET /api/mobile/v1/installments/{plan} — one plan with payment history. */
    public function show(Request $request, int $plan): JsonResponse
    {
        // Resolve shop-scoped in the controller (not route-model binding) because
        // tenant context is established by middleware, which the binding layer
        // may run before — a bound BelongsToShop model can fail-closed to 404.
        $model = InstallmentPlan::where('shop_id', (int) $request->user()->shop_id)
            ->with(['customer:id,first_name,last_name,mobile', 'invoice:id,invoice_number', 'payments.paymentMethod'])
            ->findOrFail($plan);

        return response()->json($this->presentFull($model));
    }

    /**
     * POST /api/mobile/v1/installments/finalize — finalize a POS-EMI draft into a
     * plan. The invoice_id is the draft returned by /pos/sell (mode:'emi').
     */
    public function finalizeDraft(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $data = $request->validate([
            'invoice_id' => [
                'required', 'integer',
                Rule::exists('invoices', 'id')->where('shop_id', $shopId),
            ],
            'down_payment' => 'required|numeric|min:0',
            'total_emis' => 'required|integer|min:2|max:24',
            'interest_rate_annual' => 'nullable|numeric|min:0|max:60',
            'down_payment_method' => 'nullable|in:cash,upi,bank,wallet,other',
            'down_payment_method_id' => [
                'nullable', 'integer',
                Rule::exists('shop_payment_methods', 'id')->where('shop_id', $shopId),
            ],
            'down_payment_reference' => 'nullable|string|max:100',
        ]);

        $invoice = Invoice::where('shop_id', $shopId)
            ->with(['customer'])
            ->findOrFail((int) $data['invoice_id']);

        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'invoice_id' => ['Only a POS EMI draft can be finalized here.'],
            ]);
        }
        if (! $invoice->customer) {
            throw ValidationException::withMessages([
                'invoice_id' => ['Draft invoice is not linked to a customer.'],
            ]);
        }
        if (InstallmentPlan::where('shop_id', $shopId)->where('invoice_id', $invoice->id)->exists()) {
            throw ValidationException::withMessages([
                'invoice_id' => ['An EMI plan already exists for this invoice.'],
            ]);
        }

        $downMethod = $data['down_payment_method'] ?? 'cash';
        $methodId = $this->resolveAccount(
            $shopId,
            $downMethod,
            $data['down_payment_method_id'] ?? null,
            collectsAccount: round((float) $data['down_payment'], 2) > 0,
        );

        try {
            $plan = $this->installmentService->finalizeDraftInvoiceToPlan(
                $invoice,
                round((float) $data['down_payment'], 2),
                (int) $data['total_emis'],
                (float) ($data['interest_rate_annual'] ?? 0),
                $downMethod,
                $data['down_payment_reference'] ?? null,
                $methodId,
            );
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['invoice_id' => [$e->getMessage()]]);
        }

        $plan->load(['customer:id,first_name,last_name,mobile', 'invoice:id,invoice_number', 'payments.paymentMethod']);

        return response()->json($this->presentFull($plan), 201);
    }

    /** POST /api/mobile/v1/installments/{plan}/pay — record a monthly EMI payment. */
    public function pay(Request $request, int $plan): JsonResponse
    {
        $plan = InstallmentPlan::where('shop_id', (int) $request->user()->shop_id)->findOrFail($plan);

        if (! $plan->isActive()) {
            throw ValidationException::withMessages(['plan' => ['This plan is no longer active.']]);
        }

        $data = $request->validate([
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'required|in:cash,upi,card,bank_transfer',
            'payment_method_id' => [
                'nullable', 'integer',
                Rule::exists('shop_payment_methods', 'id')->where('shop_id', $plan->shop_id),
            ],
            'notes' => 'nullable|string|max:500',
        ]);

        // upi → upi account, bank_transfer → bank account; cash/card carry none.
        $accountType = match ($data['payment_method']) {
            'upi' => 'upi',
            'bank_transfer' => 'bank',
            default => null,
        };
        $methodId = $this->resolveAccountForType($plan->shop_id, $accountType, $data['payment_method_id'] ?? null);

        try {
            $this->installmentService->recordPayment(
                $plan,
                (float) $data['amount'],
                $data['payment_method'],
                $data['notes'] ?? null,
                $methodId,
            );
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['amount' => [$e->getMessage()]]);
        }

        $fresh = $plan->fresh(['customer:id,first_name,last_name,mobile', 'invoice:id,invoice_number', 'payments.paymentMethod']);

        return response()->json($this->presentFull($fresh), 201);
    }

    /**
     * POST /api/mobile/v1/installments/discard-draft — discard a POS-EMI draft the
     * user backed out of (marks the draft invoice cancelled; items stay in_stock).
     */
    public function discardDraft(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $data = $request->validate([
            'invoice_id' => [
                'required', 'integer',
                Rule::exists('invoices', 'id')->where('shop_id', $shopId),
            ],
        ]);

        $invoice = Invoice::where('shop_id', $shopId)->findOrFail((int) $data['invoice_id']);

        try {
            $this->installmentService->discardDraftPosEmiInvoice($invoice);
        } catch (LogicException $e) {
            throw ValidationException::withMessages(['invoice_id' => [$e->getMessage()]]);
        }

        return response()->json(['discarded' => true, 'invoice_id' => (int) $invoice->id]);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * Validate the down-payment account: required + active + type-matched when a
     * non-cash payment is actually collected. Returns the id to store (or null).
     */
    private function resolveAccount(int $shopId, string $method, ?int $methodId, bool $collectsAccount): ?int
    {
        if (! $collectsAccount || ! in_array($method, ['upi', 'bank', 'wallet'], true)) {
            return null; // cash / other / no-collection carries no account
        }

        return $this->resolveAccountForType($shopId, $method, $methodId);
    }

    /** Confirm a ShopPaymentMethod of the given type for the shop, or throw. */
    private function resolveAccountForType(int $shopId, ?string $type, ?int $methodId): ?int
    {
        if ($type === null) {
            return null;
        }

        $account = ShopPaymentMethod::where('shop_id', $shopId)
            ->where('id', $methodId)
            ->active()
            ->first();

        if (! $account || $account->type !== $type) {
            throw ValidationException::withMessages([
                'payment_method_id' => ['Please choose a valid ' . strtoupper($type) . ' account.'],
            ]);
        }

        return (int) $account->id;
    }

    private function presentSummary(InstallmentPlan $p): array
    {
        return [
            'id' => (int) $p->id,
            'status' => (string) $p->status,
            'customer' => $p->customer ? [
                'id' => (int) $p->customer->id,
                'name' => $p->customer->name,
                'mobile' => $p->customer->mobile,
            ] : null,
            'invoice_number' => $p->invoice?->invoice_number,
            'emi_amount' => (float) $p->emi_amount,
            'total_payable' => (float) $p->total_payable,
            'remaining_amount' => (float) $p->remaining_amount,
            'emis_paid' => (int) $p->emis_paid,
            'total_emis' => (int) $p->total_emis,
            'next_due_date' => optional($p->next_due_date)->toDateString(),
        ];
    }

    private function presentFull(InstallmentPlan $p): array
    {
        $summary = $this->installmentService->summary($p);

        return array_merge($this->presentSummary($p), [
            'down_payment' => (float) $p->down_payment,
            'principal_amount' => (float) $p->principal_amount,
            'interest_rate_annual' => (float) $p->interest_rate_annual,
            'interest_amount' => (float) $p->interest_amount,
            'total_amount' => (float) $p->total_amount,
            'total_paid' => (float) $summary['total_paid'],
            'outstanding' => (float) $summary['outstanding'],
            'is_overdue' => (bool) $summary['is_overdue'],
            'payments' => $p->payments
                ->sortByDesc('payment_date')->values()
                ->map(fn ($pay) => [
                    'id' => (int) $pay->id,
                    'amount' => (float) $pay->amount,
                    'payment_date' => optional($pay->payment_date)->toDateString(),
                    'payment_method' => (string) $pay->payment_method,
                    'account' => $pay->paymentMethod ? [
                        'id' => (int) $pay->paymentMethod->id,
                        'label' => $pay->paymentMethod->account_label,
                    ] : null,
                    'notes' => $pay->notes,
                ])->all(),
        ]);
    }
}
