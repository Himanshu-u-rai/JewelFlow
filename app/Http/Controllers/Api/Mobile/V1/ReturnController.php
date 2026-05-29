<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Concerns\EmitsEntityTag;
use App\Models\Invoice;
use App\Models\ReturnOrder;
use App\Services\Returns\ReturnApprovalService;
use App\Services\Returns\ReturnService;
use App\Services\Returns\ValuationBasis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile v1 — Returns, Credit Notes (M8).
 *
 * Surfaces the return domain to mobile operators. Accounting safety is
 * fully enforced by the service layer (ReturnService / CreditNoteService /
 * ImmutableLedger triggers) — this controller handles authorization,
 * input shaping, and response formatting only.
 *
 * All mutations go through the mobile.idempotency middleware (wired in
 * routes/mobile_v1.php). The envelope middleware wraps every response.
 *
 * Mobile MUST NOT:
 *   - Compute refund amounts client-side.
 *   - Bypass the approval gate.
 *   - Assume a return is settled until status === 'settled'.
 */
class ReturnController extends Controller
{
    use EmitsEntityTag;

    public function __construct(
        private ReturnService         $returns,
        private ReturnApprovalService $approvals,
    ) {}

    // ────────────────────────────────────────────────────────────────────
    // LIST
    // ────────────────────────────────────────────────────────────────────

    /**
     * GET /api/mobile/v1/returns
     *
     * Returns a cursor-paginated list of return orders for this shop.
     * Accepts query filters: status, customer_id, from (date), to (date).
     */
    public function index(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $query = ReturnOrder::where('shop_id', $shopId)
            ->with(['customer:id,first_name,last_name,mobile', 'creditNote:id,credit_note_number,total,status'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }

        $paginator = $query->cursorPaginate(20);

        return response()->json([
            'data'       => $paginator->map(fn ($ro) => $this->presentSummary($ro))->values(),
            'pagination' => [
                'next_cursor' => $paginator->nextCursor()?->encode(),
                'prev_cursor' => $paginator->previousCursor()?->encode(),
                'page_size'   => $paginator->perPage(),
                'has_more'    => $paginator->hasMorePages(),
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────────────
    // SHOW
    // ────────────────────────────────────────────────────────────────────

    /**
     * GET /api/mobile/v1/returns/{returnOrder}
     */
    public function show(Request $request, ReturnOrder $returnOrder): JsonResponse
    {
        abort_if($returnOrder->shop_id !== (int) $request->user()->shop_id, 404);

        $returnOrder->load([
            'customer:id,first_name,last_name,mobile',
            'invoice:id,invoice_number,total,created_at',
            'lineItems.item:id,barcode,design,category,metal_type',
            'lineItems.dispositions',
            'creditNote',
            'createdBy:id,name',
            'approvedBy:id,name',
        ]);

        return response()->json($this->presentFull($returnOrder))
            ->header('ETag', $this->entityTagFor($returnOrder))
            ->header('X-Has-Entity-Tag', 'yes');
    }

    // ────────────────────────────────────────────────────────────────────
    // STORE (create + submit)
    // ────────────────────────────────────────────────────────────────────

    /**
     * POST /api/mobile/v1/returns
     *
     * Creates and submits a return against a finalized invoice.
     *
     * Body:
     *   invoice_id      int       required
     *   reason          string    required
     *   refund_settlement string  required — 'cash' | 'store_credit'
     *   lines           array     required — per-line selections
     *     .invoice_item_id  int
     *     .condition        string  — 'good_condition'|'minor_wear'|'damaged'|'non_sellable'
     *
     * The server computes all refund amounts from the locked
     * invoice_items.allocated_* values via RefundPolicyResolver.
     * The mobile client MUST NOT send or infer refund amounts.
     */
    public function store(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $validated = $request->validate([
            'invoice_id'        => ['required', 'integer', Rule::exists('invoices', 'id')->where('shop_id', $shopId)],
            'reason'            => 'required|string|max:1000',
            'refund_settlement' => ['required', Rule::in(['cash', 'store_credit'])],
            'lines'             => 'required|array|min:1',
            'lines.*.invoice_item_id' => ['required', 'integer'],
            'lines.*.condition'       => ['required', Rule::in(['good_condition', 'minor_wear', 'damaged', 'non_sellable'])],
        ]);

        $invoice = Invoice::where('shop_id', $shopId)
            ->with(['items'])
            ->findOrFail($validated['invoice_id']);

        // Map lines to the service's selection format.
        $selections = collect($validated['lines'])->map(fn ($l) => [
            'invoice_item_id'   => (int) $l['invoice_item_id'],
            'condition'         => $l['condition'],
        ])->all();

        // Check if approval is required BEFORE processing.
        $approvalReq = $this->approvals->checkRequired(
            $invoice,
            $selections,
            $request->user()->shop->preferences
        );

        try {
            if ($approvalReq->required) {
                // Creates a pending_approval return — does NOT settle.
                $returnOrder = $this->returns->createPendingApproval(
                    invoice: $invoice,
                    selections: $selections,
                    reason: $validated['reason'],
                    userId: (int) $request->user()->id,
                    refundSettlement: $validated['refund_settlement'],
                );
            } else {
                $returnOrder = $this->returns->createPartialReturn(
                    invoice: $invoice,
                    selections: $selections,
                    reason: $validated['reason'],
                    userId: (int) $request->user()->id,
                    basis: ValuationBasis::saleDayRate(
                        $request->user()->shop->preferences?->toRefundDeductions() ?? []
                    ),
                    refundSettlement: $validated['refund_settlement'],
                );
            }
        } catch (\LogicException|\RuntimeException $e) {
            return response()->json([
                'errors' => [['code' => 'return_creation_failed', 'message' => $e->getMessage()]],
            ], 422);
        }

        $returnOrder->load(['creditNote', 'customer:id,first_name,last_name']);

        return response()->json($this->presentFull($returnOrder), 201);
    }

    // ────────────────────────────────────────────────────────────────────
    // APPROVE
    // ────────────────────────────────────────────────────────────────────

    /**
     * POST /api/mobile/v1/returns/{returnOrder}/approve
     *
     * Owner/manager approves a pending_approval return order. Only users
     * with `returns.approve` permission reach this action.
     */
    public function approve(Request $request, ReturnOrder $returnOrder): JsonResponse
    {
        abort_if($returnOrder->shop_id !== (int) $request->user()->shop_id, 404);

        if ($returnOrder->status !== ReturnOrder::STATUS_PENDING_APPROVAL) {
            return response()->json([
                'errors' => [['code' => 'return_not_pending', 'message' => "Return is in '{$returnOrder->status}' state. Only pending_approval returns can be approved."]],
            ], 409);
        }

        try {
            $settled = $this->returns->approveReturn($returnOrder, (int) $request->user()->id);
        } catch (\LogicException $e) {
            return response()->json([
                'errors' => [['code' => 'approval_failed', 'message' => $e->getMessage()]],
            ], 422);
        }

        $settled->load(['creditNote', 'customer:id,first_name,last_name', 'approvedBy:id,name']);

        return response()->json($this->presentFull($settled));
    }

    // ────────────────────────────────────────────────────────────────────
    // Presentation helpers
    // ────────────────────────────────────────────────────────────────────

    private function presentSummary(ReturnOrder $ro): array
    {
        return [
            'id'                => $ro->id,
            'status'            => $ro->status,
            'return_type'       => $ro->return_type,
            'reason'            => $ro->reason,
            'refund_settlement' => $ro->refund_settlement,
            'customer'          => $ro->customer ? [
                'id'     => $ro->customer->id,
                'name'   => trim($ro->customer->first_name . ' ' . $ro->customer->last_name),
                'mobile' => $ro->customer->mobile,
            ] : null,
            'credit_note'       => $ro->creditNote ? [
                'number' => $ro->creditNote->credit_note_number,
                'total'  => (float) $ro->creditNote->total,
                'status' => $ro->creditNote->status,
            ] : null,
            'created_at'        => optional($ro->created_at)->toIso8601String(),
        ];
    }

    private function presentFull(ReturnOrder $ro): array
    {
        $base = $this->presentSummary($ro);

        $base['invoice'] = $ro->invoice ? [
            'id'             => $ro->invoice->id,
            'invoice_number' => $ro->invoice->invoice_number,
            'total'          => (float) $ro->invoice->total,
            'created_at'     => optional($ro->invoice->created_at)->toIso8601String(),
        ] : null;

        $base['lines'] = ($ro->lineItems ?? collect())->map(fn ($line) => [
            'id'              => $line->id,
            'invoice_item_id' => $line->invoice_item_id,
            'condition'       => $line->condition,
            'refund_subtotal' => (float) $line->refund_subtotal,
            'refund_gst'      => (float) $line->refund_gst,
            'refund_total'    => (float) $line->refund_total,
            'item' => $line->item ? [
                'id'        => $line->item->id,
                'barcode'   => $line->item->barcode,
                'design'    => $line->item->design,
                'category'  => $line->item->category,
                'metal_type' => $line->item->metal_type,
            ] : null,
        ])->values()->all();

        $base['approved_by']  = $ro->approvedBy ? ['id' => $ro->approvedBy->id, 'name' => $ro->approvedBy->name] : null;
        $base['created_by']   = $ro->createdBy ? ['id' => $ro->createdBy->id, 'name' => $ro->createdBy->name] : null;
        $base['updated_at']   = optional($ro->updated_at)->toIso8601String();

        return $base;
    }
}
