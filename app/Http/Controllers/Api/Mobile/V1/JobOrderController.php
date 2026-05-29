<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Concerns\EmitsEntityTag;
use App\Models\JobOrder;
use App\Models\MetalLot;
use App\Services\JobOrderService;
use App\Services\MetalRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile v1 — Karigar job orders: list, show, issue, receive (M9).
 *
 * Karigar flows are high-frequency operator surfaces. Every mutation is
 * idempotency-protected (wired via mobile.idempotency middleware in
 * routes/mobile_v1.php).
 *
 * Mobile MUST NOT:
 *   - Compute fine-weight client-side.
 *   - Validate wastage percentages locally.
 *   - Assume the lot balance is sufficient — the service layer checks.
 *
 * All material-class semantics come from MetalRegistry. The JobOrderService
 * enforces vault invariants (lot locking, non-negative balance) server-side.
 */
class JobOrderController extends Controller
{
    use EmitsEntityTag;

    public function __construct(private JobOrderService $service) {}

    // ────────────────────────────────────────────────────────────────────
    // LIST
    // ────────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $query = JobOrder::where('shop_id', $shopId)
            ->with(['karigar:id,name', 'receipts'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('karigar_id')) {
            $query->where('karigar_id', (int) $request->input('karigar_id'));
        }

        $paginator = $query->cursorPaginate(20);

        return response()->json([
            'data' => $paginator->map(fn ($jo) => $this->presentSummary($jo))->values(),
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

    public function show(Request $request, JobOrder $jobOrder): JsonResponse
    {
        abort_if($jobOrder->shop_id !== (int) $request->user()->shop_id, 404);

        $jobOrder->load([
            'karigar:id,name,mobile',
            'issuances.lot:id,lot_number,purity,source',
            'receipts.items',
            'createdBy:id,name',
        ]);

        return response()->json($this->presentFull($jobOrder))
            ->header('ETag', $this->entityTagFor($jobOrder))
            ->header('X-Has-Entity-Tag', 'yes');
    }

    // ────────────────────────────────────────────────────────────────────
    // STORE (issue)
    // ────────────────────────────────────────────────────────────────────

    /**
     * POST /api/mobile/v1/job-orders
     *
     * Issues gold from vault lots to a karigar.
     *
     * Body:
     *   karigar_id             int
     *   metal_type             string    — must be enabled for shop
     *   purity                 float     — karats for gold/silver
     *   allowed_wastage_percent float    — 0–25
     *   issue_date             date      — optional, defaults to today
     *   expected_return_date   date      — optional
     *   notes                  string    — optional
     *   issuances              array     — one entry per lot
     *     .metal_lot_id        int
     *     .gross_weight        float
     *     .fine_weight         float
     */
    public function store(Request $request): JsonResponse
    {
        $shopId = (int) $request->user()->shop_id;

        $validated = $request->validate([
            'karigar_id'             => ['required', 'integer', Rule::exists('karigars', 'id')->where('shop_id', $shopId)],
            'metal_type'             => ['required', 'string', Rule::in(MetalRegistry::enabledMetalsForShop($shopId))],
            'purity'                 => ['required', 'numeric', 'min:0.001', 'max:1000'],
            'allowed_wastage_percent' => ['nullable', 'numeric', 'min:0', 'max:25'],
            'issue_date'             => ['nullable', 'date'],
            'expected_return_date'   => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes'                  => ['nullable', 'string', 'max:1000'],
            'issuances'              => ['required', 'array', 'min:1'],
            'issuances.*.metal_lot_id' => ['required', 'integer', Rule::exists('metal_lots', 'id')->where('shop_id', $shopId)],
            'issuances.*.gross_weight' => ['required', 'numeric', 'min:0.001'],
            'issuances.*.fine_weight'  => ['required', 'numeric', 'min:0.001'],
        ]);

        try {
            $jobOrder = $this->service->issue($validated, $shopId, (int) $request->user()->id);
        } catch (\LogicException $e) {
            return response()->json([
                'errors' => [['code' => 'job_order_issue_failed', 'message' => $e->getMessage()]],
            ], 422);
        }

        $jobOrder->load(['karigar:id,name', 'issuances.lot:id,lot_number']);

        return response()->json($this->presentFull($jobOrder), 201);
    }

    // ────────────────────────────────────────────────────────────────────
    // RECEIPT (receive finished items from karigar)
    // ────────────────────────────────────────────────────────────────────

    /**
     * POST /api/mobile/v1/job-orders/{jobOrder}/receipt
     *
     * Records karigar receipt of finished items.
     *
     * Body:
     *   items   array     — per item/batch
     *     .pieces         int     — number of items in this batch
     *     .gross_weight   float
     *     .stone_weight   float   — optional
     *     .net_weight     float   — optional, derived from gross - stone
     *     .purity         float   — optional, defaults to job purity
     *     .description    string  — optional
     *   leftover_gross_weight  float  — optional
     *   leftover_purity        float  — optional
     *   leftover_lot_id        int    — optional, required if leftover > 1g
     *   making_charge          float  — optional
     *   notes                  string — optional
     */
    public function receipt(Request $request, JobOrder $jobOrder): JsonResponse
    {
        abort_if($jobOrder->shop_id !== (int) $request->user()->shop_id, 404);

        if (! in_array($jobOrder->status, [JobOrder::STATUS_ISSUED, JobOrder::STATUS_PARTIAL_RETURN], true)) {
            return response()->json([
                'errors' => [['code' => 'job_not_receivable', 'message' => "Job order is in '{$jobOrder->status}' state. Only issued or partial_return orders can receive items."]],
            ], 409);
        }

        $validated = $request->validate([
            'items'                 => ['required', 'array', 'min:1'],
            'items.*.pieces'        => ['nullable', 'integer', 'min:1'],
            'items.*.gross_weight'  => ['required', 'numeric', 'min:0.001'],
            'items.*.stone_weight'  => ['nullable', 'numeric', 'min:0'],
            'items.*.net_weight'    => ['nullable', 'numeric', 'min:0.001'],
            'items.*.purity'        => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'items.*.description'   => ['nullable', 'string', 'max:255'],
            'leftover_gross_weight' => ['nullable', 'numeric', 'min:0'],
            'leftover_purity'       => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'leftover_lot_id'       => ['nullable', 'integer', Rule::exists('metal_lots', 'id')->where('shop_id', $jobOrder->shop_id)],
            'making_charge'         => ['nullable', 'numeric', 'min:0'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $receipt = $this->service->receive($jobOrder, $validated, (int) $request->user()->id);
        } catch (\LogicException $e) {
            return response()->json([
                'errors' => [['code' => 'receipt_failed', 'message' => $e->getMessage()]],
            ], 422);
        }

        $jobOrder->refresh()->load(['karigar:id,name', 'receipts.items']);

        return response()->json([
            'receipt_id'     => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'job_order'      => $this->presentFull($jobOrder),
        ], 201);
    }

    // ────────────────────────────────────────────────────────────────────
    // Presentation helpers
    // ────────────────────────────────────────────────────────────────────

    private function presentSummary(JobOrder $jo): array
    {
        return [
            'id'                          => $jo->id,
            'job_order_number'            => $jo->job_order_number,
            'status'                      => $jo->status,
            'metal_type'                  => $jo->metal_type,
            'purity'                      => (float) $jo->purity,
            'issued_fine_weight'          => (float) $jo->issued_fine_weight,
            'outstanding_fine_weight'     => (float) $jo->outstanding_fine_weight,
            'karigar'                     => $jo->karigar ? ['id' => $jo->karigar->id, 'name' => $jo->karigar->name] : null,
            'issue_date'                  => optional($jo->issue_date)->toDateString(),
            'expected_return_date'        => optional($jo->expected_return_date)->toDateString(),
            'is_overdue'                  => (bool) $jo->is_overdue,
            'created_at'                  => optional($jo->created_at)->toIso8601String(),
        ];
    }

    private function presentFull(JobOrder $jo): array
    {
        $base = $this->presentSummary($jo);
        $base['issued_gross_weight']     = (float) $jo->issued_gross_weight;
        $base['allowed_wastage_percent'] = (float) $jo->allowed_wastage_percent;
        $base['notes']                   = $jo->notes;
        $base['receipts_count']          = ($jo->receipts ?? collect())->count();
        $base['updated_at']              = optional($jo->updated_at)->toIso8601String();
        return $base;
    }
}
