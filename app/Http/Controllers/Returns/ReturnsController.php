<?php

namespace App\Http\Controllers\Returns;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\ReturnLineItem;
use App\Models\ReturnOrder;
use App\Models\ReturnedItemDisposition;
use App\Services\EntityEventService;
use App\Services\OrchestrationEventService;
use App\Services\Returns\RefundPolicyResolver;
use App\Services\Returns\ReturnApprovalService;
use App\Services\Returns\ReturnService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Returns Inbox — Phase 1 surface.
 *
 * Lists all return orders for the current shop. Phase 1 only writes settled
 * full-invoice returns through this module, so the inbox is read-only and
 * informational. Phase 2 adds the partial-return creation UI; Phase 2+ adds
 * a "needs disposition" filter for items in `status='returned'` with no
 * disposition yet.
 */
class ReturnsController extends Controller
{
    public function __construct(
        private ReturnService $returnService,
        private OrchestrationEventService $orchestrationEvents,
        private EntityEventService $entityEventService,
    ) {}

    /**
     * Phase 2 "start a return" entry point. Loads the invoice's line items
     * with their already-returned status and shows a checkbox form where the
     * cashier picks which items, sets per-line condition, and chooses disposition.
     */
    public function create(Invoice $invoice)
    {
        $shopId = auth()->user()->shop_id;
        if ($invoice->shop_id !== $shopId) {
            abort(404);
        }
        $this->authorize('update', $invoice);

        $preferences = auth()->user()->shop?->preferences;
        if ($preferences && !$preferences->hasConfiguredReturnPolicy()) {
            return redirect()->route('settings.edit', ['tab' => 'return-policy'])
                ->with('warning', 'Please set up your return policy before processing returns. This takes about 1 minute.');
        }

        if ($invoice->status !== Invoice::STATUS_FINALIZED) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Only finalized invoices can have items returned.');
        }

        $invoice->load([
            'items.item',
            'customer',
        ]);

        $conditions = [
            ReturnLineItem::CONDITION_GOOD         => 'Good condition (re-sellable)',
            ReturnLineItem::CONDITION_MINOR_WEAR   => 'Minor wear',
            ReturnLineItem::CONDITION_DAMAGED      => 'Damaged',
            ReturnLineItem::CONDITION_NON_SELLABLE => 'Non-sellable',
        ];

        $dispositions = [
            ReturnedItemDisposition::DISPOSITION_RESTOCKED       => 'Restock — back on sale',
            ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT    => 'Send to melt — gold returns to lot',
            ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK  => 'Send for rework (parked)',
            ReturnedItemDisposition::DISPOSITION_WRITTEN_OFF     => 'Write off — non-sellable',
        ];

        // Pre-compute policy-adjusted refund estimates for the view.
        // This avoids calling the service inside the Blade loop.
        $policyBreakdowns = [];
        $shopPolicy = auth()->user()->shop?->preferences;
        $resolver   = app(\App\Services\Returns\RefundPolicyResolver::class);
        $basis      = $resolver->basisFromPolicy($shopPolicy);
        foreach ($invoice->items as $line) {
            if ($line->returned_at !== null) {
                continue;
            }
            $policyBreakdowns[$line->id] = $resolver->resolve($line, $invoice, $basis, $shopPolicy);
        }

        $allowedSettlement = auth()->user()->shop?->preferences?->return_settlement_mode ?? 'cash_or_credit';

        return view('returns.create', compact('invoice', 'conditions', 'dispositions', 'policyBreakdowns', 'allowedSettlement'));
    }

    public function store(Request $request, Invoice $invoice)
    {
        $shopId = auth()->user()->shop_id;
        if ($invoice->shop_id !== $shopId) {
            abort(404);
        }
        $this->authorize('update', $invoice);

        $preferences = auth()->user()->shop?->preferences;
        if ($preferences && !$preferences->hasConfiguredReturnPolicy()) {
            return redirect()->route('settings.edit', ['tab' => 'return-policy'])
                ->with('warning', 'Please set up your return policy before processing returns. This takes about 1 minute.');
        }

        $validated = $request->validate([
            'reason'                  => 'required|string|min:5|max:500',
            'lines'                   => 'required|array|min:1',
            'lines.*.invoice_item_id' => ['required', 'integer', Rule::exists('invoice_items', 'id')->where('invoice_id', $invoice->id)],
            'lines.*.condition'       => ['required', Rule::in([
                ReturnLineItem::CONDITION_GOOD,
                ReturnLineItem::CONDITION_MINOR_WEAR,
                ReturnLineItem::CONDITION_DAMAGED,
                ReturnLineItem::CONDITION_NON_SELLABLE,
            ])],
            'lines.*.disposition'     => ['required', Rule::in([
                ReturnedItemDisposition::DISPOSITION_RESTOCKED,
                ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT,
                ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK,
                ReturnedItemDisposition::DISPOSITION_WRITTEN_OFF,
            ])],
            'lines.*.reason'                    => 'nullable|string|max:255',
            'lines.*.override_making_charges'   => 'nullable|boolean',
            'lines.*.override_stone_charges'    => 'nullable|boolean',
            'lines.*.override_gst'              => 'nullable|boolean',
            'lines.*.override_waive_restocking' => 'nullable|boolean',
            'lines.*.override_wear_loss_pct'    => 'nullable|numeric|min:0|max:25',
            'lines.*.override_manual_total'     => 'nullable|numeric|min:0',
            'lines.*.override_reason'           => 'nullable|string|min:5|max:500',
            'refund_settlement'       => ['sometimes', Rule::in([
                \App\Services\Returns\ReturnService::REFUND_SETTLEMENT_CASH,
                \App\Services\Returns\ReturnService::REFUND_SETTLEMENT_STORE_CREDIT,
            ])],
        ]);

        // Store-credit settlement only allowed when invoice has a customer.
        $refundSettlement = $validated['refund_settlement']
            ?? \App\Services\Returns\ReturnService::REFUND_SETTLEMENT_CASH;
        if ($refundSettlement === \App\Services\Returns\ReturnService::REFUND_SETTLEMENT_STORE_CREDIT && !$invoice->customer_id) {
            return back()->withInput()->withErrors([
                'refund_settlement' => 'Store credit requires a customer on the invoice. Pick cash or add a customer first.',
            ]);
        }

        $hasAnyOverride = collect($validated['lines'])->some(fn ($r) =>
            isset($r['override_making_charges'])   ||
            isset($r['override_stone_charges'])    ||
            isset($r['override_gst'])              ||
            isset($r['override_waive_restocking']) ||
            (isset($r['override_wear_loss_pct']) && $r['override_wear_loss_pct'] !== null) ||
            isset($r['override_manual_total'])
        );

        if ($hasAnyOverride && !auth()->user()->can('returns.approve')) {
            return back()->withInput()->withErrors([
                'override' => "Override fields require the 'Approve Returns' permission.",
            ]);
        }

        $selections = [];
        foreach ($validated['lines'] as $row) {
            $selections[(int) $row['invoice_item_id']] = [
                'condition'   => $row['condition'],
                'disposition' => $row['disposition'],
                'reason'      => $row['reason'] ?? null,
            ];
        }

        $lineOverrides = [];
        foreach ($validated['lines'] as $row) {
            $hasOverride = isset($row['override_making_charges'])   ||
                          isset($row['override_stone_charges'])    ||
                          isset($row['override_gst'])              ||
                          isset($row['override_waive_restocking']) ||
                          (isset($row['override_wear_loss_pct']) && $row['override_wear_loss_pct'] !== null) ||
                          isset($row['override_manual_total']);

            if ($hasOverride) {
                $itemId = (int) $row['invoice_item_id'];
                $hasManualTotal = isset($row['override_manual_total']) && $row['override_manual_total'] !== null;
                $lineOverrides[$itemId] = [
                    'mode'                      => $hasManualTotal ? 'manual' : 'component',
                    'override_making_charges'   => isset($row['override_making_charges']) ? (bool) $row['override_making_charges'] : null,
                    'override_stone_charges'    => isset($row['override_stone_charges']) ? (bool) $row['override_stone_charges'] : null,
                    'override_gst'              => isset($row['override_gst']) ? (bool) $row['override_gst'] : null,
                    'override_waive_restocking' => isset($row['override_waive_restocking']) ? (bool) $row['override_waive_restocking'] : null,
                    'override_wear_loss_pct'    => isset($row['override_wear_loss_pct']) ? (float) $row['override_wear_loss_pct'] : null,
                    'override_manual_total'     => $hasManualTotal ? (float) $row['override_manual_total'] : null,
                    'override_reason'           => $row['override_reason'] ?? null,
                    'override_by_user_id'       => auth()->id(),
                ];
            }
        }

        // Eager-load items + their item relation for threshold pre-computation.
        $invoice->loadMissing(['items.item', 'shop.preferences']);

        // Phase B: approval gate ─────────────────────────────────────────────
        $shopPolicy      = auth()->user()->shop?->preferences;
        $approvalService = app(ReturnApprovalService::class);

        // Pre-compute refund totals for threshold check.
        $resolver         = app(RefundPolicyResolver::class);
        $basis            = $resolver->basisFromPolicy($shopPolicy);
        $lineRefundTotals = [];
        foreach ($selections as $itemId => $sel) {
            $line = $invoice->items->firstWhere('id', $itemId);
            if ($line) {
                $bd = $resolver->resolve($line, $invoice, $basis, $shopPolicy);
                $lineRefundTotals[$itemId] = $bd->refundTotal;
            }
        }

        $approvalReason = $approvalService->checkRequired($invoice, $selections, $lineRefundTotals, $shopPolicy);
        if ($approvalReason !== null && !auth()->user()->can('returns.approve')) {
            return back()->withInput()->withErrors([
                'approval' => "Manager approval required: {$approvalReason} A user with the 'Approve Returns' permission must process this return.",
            ]);
        }

        // Phase C: return-window enforcement ─────────────────────────────────
        $windowDays = (int) ($shopPolicy?->return_window_days ?? 0);
        if ($windowDays > 0) {
            $invoiceDate = Carbon::parse($invoice->finalized_at ?? $invoice->created_at);
            $daysSince   = (int) $invoiceDate->diffInDays(now());
            if ($daysSince > $windowDays && !auth()->user()->can('returns.approve')) {
                return back()->withInput()->withErrors([
                    'approval' => "The return window of {$windowDays} days has passed (this invoice is {$daysSince} days old). A user with 'Approve Returns' permission is required.",
                ]);
            }
        }

        // Phase C: settlement mode enforcement ───────────────────────────────
        $allowedMode = $shopPolicy?->return_settlement_mode ?? 'cash_or_credit';
        if ($allowedMode === 'cash_only' && $refundSettlement !== ReturnService::REFUND_SETTLEMENT_CASH) {
            return back()->withInput()->withErrors(['refund_settlement' => 'This shop policy only allows cash refunds.']);
        }
        if ($allowedMode === 'store_credit_only' && $refundSettlement !== ReturnService::REFUND_SETTLEMENT_STORE_CREDIT) {
            return back()->withInput()->withErrors(['refund_settlement' => 'This shop policy only allows store-credit refunds.']);
        }

        try {
            $returnOrder = $this->returnService->createPartialReturn(
                $invoice,
                $selections,
                $validated['reason'],
                (int) auth()->id(),
                null,
                $refundSettlement,
                false,
                $lineOverrides,
            );
        } catch (LogicException $e) {
            return back()->withInput()->withErrors(['return' => $e->getMessage()]);
        }

        $cn = $returnOrder->creditNote;

        return redirect()->route('returns.show', $returnOrder)
            ->with('success', 'Return processed. Credit note ' . ($cn?->credit_note_number ?? '(pending)')
                . ' issued for ₹' . number_format((float) ($cn?->total ?? 0), 2) . '.');
    }

    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = ReturnOrder::where('shop_id', $shopId)
            ->where('return_type', ReturnOrder::TYPE_CUSTOMER_RETURN)
            ->with([
                'invoice:id,invoice_number,total,cancelled_at',
                'customer:id,first_name,last_name,mobile',
                'creditNote:id,return_order_id,credit_note_number,total,issued_at',
                'createdBy:id,name',
                'settledBy:id,name',
                'lineItems' => function ($q) {
                    $q->select(['id', 'return_order_id', 'item_id', 'refund_total', 'condition']);
                },
            ]);

        // Filters
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('from')) {
            $query->whereDate('created_at', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('created_at', '<=', $request->input('to'));
        }
        if ($request->filled('customer')) {
            $search = '%' . $request->input('customer') . '%';
            $query->whereHas('customer', fn ($q) => $q->where('first_name', 'like', $search)
                ->orWhere('last_name', 'like', $search)
                ->orWhere('mobile', 'like', $search));
        }

        $returnOrders = $query->latest('id')->paginate(25)->withQueryString();

        // KPI chips
        $pendingApprovalCount = ReturnOrder::where('shop_id', $shopId)->where('return_type', 'customer_return')->where('status', 'pending_approval')->count();
        $pendingRestockCount  = \App\Models\Item::where('shop_id', $shopId)->where('status', 'pending_restock')->count();
        $todayRefunds = \App\Models\CreditNote::where('shop_id', $shopId)
            ->where('status', 'issued')
            ->whereDate('issued_at', today())
            ->sum('total');

        return view('returns.inbox', compact('returnOrders', 'pendingApprovalCount', 'pendingRestockCount', 'todayRefunds'));
    }

    public function show(ReturnOrder $returnOrder)
    {
        $shopId = auth()->user()->shop_id;
        if ($returnOrder->shop_id !== $shopId) {
            abort(404);
        }

        $returnOrder->load([
            'invoice.customer',
            'creditNote.issuedBy',
            'createdBy',
            'settledBy',
            'lineItems.invoiceItem',
            'lineItems.item',
            'lineItems.dispositions.dispositionedBy',
            'lineItems.dispositions.item',
            'exchangeOrder',
        ]);

        // Dispositions that still need a melt recovery — item not yet melted.
        $pendingMelts = $returnOrder->lineItems
            ->flatMap(fn($l) => $l->dispositions)
            ->filter(fn($d) => $d->disposition === ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT)
            ->filter(fn($d) => $d->item?->status !== 'melted');

        // Dispositions that still need a karigar rework job linked
        $pendingReworks = $returnOrder->lineItems
            ->flatMap(fn($l) => $l->dispositions)
            ->filter(fn($d) => $d->disposition === ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK)
            ->filter(fn($d) => is_null($d->target_job_order_id));

        // Gold lots needed for the inline melt forms
        $goldLots = $pendingMelts->isNotEmpty()
            ? \App\Models\MetalLot::where('shop_id', $shopId)
                ->where('metal_type', 'gold')
                ->where('fine_weight_remaining', '>', 0)
                ->orderBy('lot_number')
                ->get(['id', 'lot_number', 'purity', 'fine_weight_remaining'])
            : collect();

        // Compute approval context
        $approvalReason = null;
        $windowContext  = null;
        $approvalService = app(\App\Services\Returns\ReturnApprovalService::class);
        $shopPolicy = auth()->user()->shop?->preferences;

        if (!empty($returnOrder->pending_data['selections'])) {
            $selections = $returnOrder->pending_data['selections'];
            $lineRefundTotals = $returnOrder->pending_data['line_refund_totals'] ?? [];
            $approvalReason = $approvalService->checkRequired($returnOrder->invoice, $selections, $lineRefundTotals, $shopPolicy);
        }

        $windowDays = (int) ($shopPolicy?->return_window_days ?? 0);
        if ($windowDays > 0 && $returnOrder->invoice) {
            $invoiceDate = \Carbon\Carbon::parse($returnOrder->invoice->finalized_at ?? $returnOrder->invoice->created_at);
            $windowContext = [
                'days_since'  => (int) $invoiceDate->diffInDays($returnOrder->created_at ?? now()),
                'window_days' => $windowDays,
                'within'      => (int) $invoiceDate->diffInDays($returnOrder->created_at ?? now()) <= $windowDays,
            ];
        }

        $eventFeed = $this->entityEventService->feedFor($shopId, 'return_order', (int) $returnOrder->id, maxLevel: 1);

        return view('returns.show', compact(
            'returnOrder', 'approvalReason', 'windowContext',
            'pendingMelts', 'pendingReworks', 'goldLots', 'eventFeed'
        ));
    }

    public function controlCenter()
    {
        $shopId = auth()->user()->shop_id;

        // Queue 1: Needs My Approval
        $pendingApprovals = ReturnOrder::where('shop_id', $shopId)
            ->where('status', 'pending_approval')
            ->with(['invoice:id,invoice_number,total', 'customer:id,first_name,last_name,mobile', 'createdBy:id,name'])
            ->oldest()
            ->get();

        // Queue 2: Returned Items — Decide What To Do
        $returnedAwaitingDecision = \App\Models\Item::where('shop_id', $shopId)
            ->where('status', 'pending_restock')
            ->with(['latestReturnDisposition.returnLineItem.returnOrder'])
            ->oldest()
            ->get();

        // Queue 3: With Karigar (sent_to_rework disposition but no target_job_order_id)
        $withKarigar = ReturnedItemDisposition::where('shop_id', $shopId)
            ->where('disposition', 'sent_to_rework')
            ->whereNull('target_job_order_id')
            ->with(['item', 'returnLineItem.returnOrder'])
            ->oldest()
            ->get();

        // Queue 4: Gold Pending Recovery (sent_to_melt but item not yet melted)
        $goldPendingRecovery = ReturnedItemDisposition::where('shop_id', $shopId)
            ->where('disposition', 'sent_to_melt')
            ->whereHas('item', fn($q) => $q->where('status', '!=', 'melted'))
            ->with(['item:id,barcode,design,category,net_metal_weight,purity,gross_weight,status', 'returnLineItem.returnOrder'])
            ->oldest()
            ->get();

        // Orphaned items: status=with_karigar but job order was cancelled
        $orphanedWithKarigar = \App\Models\JobOrder::where('shop_id', $shopId)
            ->where('status', \App\Models\JobOrder::STATUS_CANCELLED)
            ->whereIn('job_type', [
                \App\Models\JobOrder::JOB_TYPE_REPAIR,
                \App\Models\JobOrder::JOB_TYPE_REWORK,
            ])
            ->whereNotNull('source_item_id')
            ->whereHas('sourceItem', fn($q) => $q->where('status', 'with_karigar'))
            ->with(['sourceItem:id,barcode,design,status,purity,net_metal_weight,category', 'karigar:id,name'])
            ->get();

        // Estimated gold value for the recovery queue
        $shop = auth()->user()->shop;
        $dailyRate = app(\App\Services\ShopPricingService::class)->currentDailyRate($shop);
        $goldRatePerGram = $dailyRate ? (float) $dailyRate->gold_24k_rate_per_gram : 0.0;

        $goldPendingValue = $goldPendingRecovery->sum(function ($disp) use ($goldRatePerGram) {
            $item = $disp->item;
            if (!$item || !$item->net_metal_weight || !$item->purity) return 0;
            $mult = \App\Services\MetalRegistry::fineWeightMultiplier((string) $item->metal_type, (float) $item->purity);
            if ($mult === null) return 0; // display only — non-accounting metals contribute no gold value
            $fineWeight = (float) $item->net_metal_weight * $mult;
            return round($fineWeight * $goldRatePerGram, 2);
        });

        return view('returns.control-center', compact(
            'pendingApprovals', 'returnedAwaitingDecision', 'withKarigar', 'goldPendingRecovery',
            'goldPendingValue', 'goldRatePerGram', 'orphanedWithKarigar'
        ));
    }

    public function showApprove(ReturnOrder $returnOrder)
    {
        $shopId = auth()->user()->shop_id;
        if ($returnOrder->shop_id !== $shopId) {
            abort(404);
        }
        if ($returnOrder->status !== 'pending_approval') {
            return redirect()->route('returns.show', $returnOrder)
                ->with('error', 'This return is not awaiting approval.');
        }

        $returnOrder->load(['invoice.customer', 'createdBy', 'lineItems.invoiceItem', 'lineItems.item']);

        // Compute why approval was required
        $approvalService = app(\App\Services\Returns\ReturnApprovalService::class);
        $shopPolicy = auth()->user()->shop?->preferences;
        $selections = $returnOrder->pending_data['selections'] ?? [];
        $lineRefundTotals = $returnOrder->pending_data['line_refund_totals'] ?? [];
        $approvalReason = $approvalService->checkRequired($returnOrder->invoice, $selections, $lineRefundTotals, $shopPolicy);

        return view('returns.approve-review', compact('returnOrder', 'approvalReason'));
    }

    public function redisposeItem(Request $request, \App\Models\Item $item)
    {
        $shopId = auth()->user()->shop_id;
        if ($item->shop_id !== $shopId) {
            abort(404);
        }

        $validated = $request->validate([
            'disposition'   => ['required', \Illuminate\Validation\Rule::in([
                ReturnedItemDisposition::DISPOSITION_RESTOCKED,
                ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT,
                ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK,
                ReturnedItemDisposition::DISPOSITION_WRITTEN_OFF,
            ])],
            'notes'         => 'nullable|string|max:500',
            'target_lot_id' => 'nullable|integer|exists:metal_lots,id',
        ]);

        // Find the most recent return line item for this item
        $returnLine = ReturnLineItem::where('item_id', $item->id)
            ->latest('id')
            ->firstOrFail();

        // C3: Check for an already-existing unlinked rework entry so we can warn the owner.
        $hasDuplicateRework = $validated['disposition'] === ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK
            && ReturnedItemDisposition::where('item_id', $item->id)
                ->where('shop_id', $shopId)
                ->where('disposition', ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK)
                ->whereNull('target_job_order_id')
                ->exists();

        // Determine the Tier 2 suggested action based on item condition.
        // Policy default: re-sellable condition → back to stock; otherwise pending review.
        $condition = $returnLine->condition;
        $resellableConditions = [
            ReturnLineItem::CONDITION_GOOD,
            ReturnLineItem::CONDITION_MINOR_WEAR,
        ];
        $suggestedAction   = in_array($condition, $resellableConditions, true)
            ? 'back_to_stock'
            : 'pending_review';
        $suggestionReason  = "Item condition: {$condition}. Policy default: back to stock if re-sellable.";

        try {
            $this->returnService->redisposeItem(
                $returnLine,
                $validated['disposition'],
                (int) auth()->id(),
                $validated['notes'] ?? null,
            );
        } catch (\LogicException $e) {
            return back()->withErrors(['disposition' => $e->getMessage()]);
        }

        // Record orchestration decision — what was suggested vs. what was chosen.
        try {
            $this->orchestrationEvents->record(
                shopId:           $shopId,
                userId:           (int) auth()->id(),
                entityType:       'returned_item',
                entityId:         $item->id,
                promptType:       'returned_item_fate',
                suggestedAction:  $suggestedAction,
                suggestionReason: $suggestionReason,
                userDecision:     $validated['disposition'],
                contextData:      [
                    'barcode'         => $item->barcode,
                    'condition'       => $condition,
                    'return_line_id'  => $returnLine->id,
                ],
            );
        } catch (\Throwable $e) {
            \Log::warning('OrchestrationEventService: failed to record returned_item_fate: ' . $e->getMessage());
        }

        // For rework: the item is flagged sent_to_rework. The in-app karigar
        // rework-job flow is not built (see PRODUCT_SURFACE_INTEGRITY_AUDIT.md §3.B
        // — retired, not wired), so we record the disposition and inform the
        // operator instead of redirecting to a non-existent route.
        if ($validated['disposition'] === ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK) {
            $reworkMessage = $hasDuplicateRework
                ? 'This item already had a "send for rework" entry with no karigar job linked. A second entry has been created. Track the karigar rework manually for now.'
                : 'Item marked for rework. Track the karigar rework manually for now.';
            return back()->with('info', $reworkMessage);
        }

        return back()->with('success', 'Item re-dispositioned successfully.');
    }

    public function fixOrphanStatus(Request $request, \App\Models\Item $item)
    {
        $shopId = auth()->user()->shop_id;
        abort_if($item->shop_id !== $shopId, 403);
        abort_if($item->status !== 'with_karigar', 422, 'Item is not in with_karigar status.');

        $cancelledJob = \App\Models\JobOrder::where('source_item_id', $item->id)
            ->where('shop_id', $shopId)
            ->where('status', \App\Models\JobOrder::STATUS_CANCELLED)
            ->whereIn('job_type', [
                \App\Models\JobOrder::JOB_TYPE_REPAIR,
                \App\Models\JobOrder::JOB_TYPE_REWORK,
            ])
            ->latest()
            ->firstOrFail();

        $restoredStatus = $cancelledJob->job_type === \App\Models\JobOrder::JOB_TYPE_REPAIR
            ? 'in_stock'
            : 'returned';

        $item->update(['status' => $restoredStatus]);

        return redirect()->route('returns.control-center')
            ->with('success', 'Item ' . $item->barcode . ' status corrected to ' . $restoredStatus . '.');
    }

    public function showRecover(\App\Models\ReturnedItemDisposition $disposition)
    {
        if ($disposition->shop_id !== auth()->user()->shop_id) abort(404);
        if ($disposition->disposition !== \App\Models\ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT) {
            return redirect()->route('returns.control-center')
                ->with('error', 'This item is not queued for gold recovery.');
        }
        // Check not already recovered — the item status is the canonical signal
        $disposition->loadMissing('item');
        if ($disposition->item?->status === 'melted') {
            return redirect()->route('returns.control-center')
                ->with('info', 'Gold has already been recovered for this item.');
        }

        $disposition->load(['item', 'returnLineItem.returnOrder.invoice', 'returnLineItem.returnOrder.customer']);

        $item = $disposition->item;
        $expectedFineWeight = null;
        if ($item && $item->net_metal_weight && $item->purity) {
            $expectedMult = \App\Services\MetalRegistry::fineWeightMultiplier((string) $item->metal_type, (float) $item->purity);
            $expectedFineWeight = $expectedMult === null ? null : round((float)$item->net_metal_weight * $expectedMult, 4);
        }

        // Gold lots for this shop to add recovered gold to
        $goldLots = \App\Models\MetalLot::where('shop_id', auth()->user()->shop_id)
            ->where('metal_type', 'gold')
            ->orderBy('lot_number')
            ->get(['id', 'lot_number', 'purity', 'fine_weight_remaining']);

        return view('returns.recover', compact('disposition', 'item', 'expectedFineWeight', 'goldLots'));
    }

    public function storeRecover(Request $request, \App\Models\ReturnedItemDisposition $disposition)
    {
        if ($disposition->shop_id !== auth()->user()->shop_id) abort(404);
        if ($disposition->disposition !== \App\Models\ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT) {
            return redirect()->route('returns.control-center')->with('error', 'Not queued for melt.');
        }
        if ($disposition->item?->status === 'melted') {
            return redirect()->route('returns.control-center')->with('info', 'Already recovered.');
        }

        $validated = $request->validate([
            'actual_gross_weight' => 'required|numeric|min:0.001|max:10000',
            'actual_purity'       => 'required|numeric|min:1|max:24',
            'target_lot_id'       => ['required', 'integer',
                \Illuminate\Validation\Rule::exists('metal_lots', 'id')
                    ->where('shop_id', auth()->user()->shop_id)
                    ->where('metal_type', 'gold')],
            'notes'               => 'nullable|string|max:500',
        ]);

        $invoiceId = $disposition->returnLineItem?->returnOrder?->invoice_id;

        try {
            \App\Services\InvoiceAccountingService::assertShopLockForDate(
                (int) auth()->user()->shop_id,
                now()->toDateString()
            );
            $this->returnService->recordMeltRecovery($disposition, $validated, (int) auth()->id(), $invoiceId);
        } catch (\LogicException $e) {
            return redirect()->route('returns.control-center')->with('error', $e->getMessage());
        }

        // Melt recovery is a gold-domain flow. Fine weight via the single authority.
        $actualFineWeight = round(
            (float) $validated['actual_gross_weight'] * \App\Services\MetalRegistry::fineWeightMultiplier('gold', (float) $validated['actual_purity']),
            6
        );

        return redirect()->route('returns.control-center')
            ->with('success', 'Gold recovery recorded. '
                . number_format($actualFineWeight, 3) . 'g fine weight added to vault.');
    }

    /**
     * Inline gold recovery — called from returns/show "Next Steps" section.
     * Delegates to ReturnService::recordMeltRecovery() and redirects back to
     * the return's show page, keeping the owner in context.
     */
    public function inlineRecover(Request $request, ReturnOrder $returnOrder, ReturnedItemDisposition $disposition)
    {
        $shopId = auth()->user()->shop_id;
        if ($returnOrder->shop_id !== $shopId || $disposition->shop_id !== $shopId) {
            abort(404);
        }
        if ($disposition->disposition !== ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT) {
            return back()->with('error', 'This item is not queued for gold recovery.');
        }
        if ($disposition->item?->status === 'melted') {
            return redirect()->route('returns.show', $returnOrder)
                ->with('info', 'Gold has already been recovered for this item.');
        }

        $validated = $request->validate([
            'actual_gross_weight' => 'required|numeric|min:0.001|max:10000',
            'actual_purity'       => 'required|numeric|min:1|max:24',
            'target_lot_id'       => ['required', 'integer',
                Rule::exists('metal_lots', 'id')
                    ->where('shop_id', $shopId)
                    ->where('metal_type', 'gold')],
            'notes'               => 'nullable|string|max:500',
        ]);

        $invoiceId = $disposition->returnLineItem?->returnOrder?->invoice_id;

        try {
            \App\Services\InvoiceAccountingService::assertShopLockForDate($shopId, now()->toDateString());
            $this->returnService->recordMeltRecovery($disposition, $validated, (int) auth()->id(), $invoiceId);
        } catch (\LogicException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Melt recovery is a gold-domain flow. Fine weight via the single authority.
        $actualFineWeight = round(
            (float) $validated['actual_gross_weight'] * \App\Services\MetalRegistry::fineWeightMultiplier('gold', (float) $validated['actual_purity']),
            6
        );

        // Record orchestration decision — melt recovery is a confirmation (no alternative).
        $item = $disposition->item;
        try {
            $this->orchestrationEvents->record(
                shopId:           $shopId,
                userId:           (int) auth()->id(),
                entityType:       'returned_item',
                entityId:         $disposition->item_id,
                promptType:       'melt_recovery',
                suggestedAction:  'record_melt',
                suggestionReason: 'Item sent_to_melt — recovery required before lot can be credited.',
                userDecision:     'record_melt',
                contextData:      [
                    'disposition_id'       => $disposition->id,
                    'barcode'              => $item?->barcode,
                    'target_lot_id'        => $validated['target_lot_id'],
                    'actual_gross_weight'  => $validated['actual_gross_weight'],
                    'actual_purity'        => $validated['actual_purity'],
                    'actual_fine_weight'   => $actualFineWeight,
                    'return_order_id'      => $returnOrder->id,
                ],
            );
        } catch (\Throwable $e) {
            \Log::warning('OrchestrationEventService: failed to record melt_recovery: ' . $e->getMessage());
        }

        return redirect()->route('returns.show', $returnOrder)
            ->with('success', 'Melt recovery recorded — '
                . number_format($actualFineWeight, 3) . 'g fine weight added to vault.');
    }

    /**
     * Phase B — manager approves a pending return.
     */
    public function approve(Request $request, ReturnOrder $returnOrder)
    {
        $shopId = auth()->user()->shop_id;
        if ($returnOrder->shop_id !== $shopId) {
            abort(404);
        }
        if ($returnOrder->status !== 'pending_approval') {
            return redirect()->route('returns.show', $returnOrder)
                ->with('error', 'This return is not awaiting approval.');
        }

        $validated = $request->validate([
            'lines'                             => 'nullable|array',
            'lines.*.invoice_item_id'           => 'required_with:lines|integer',
            'lines.*.override_making_charges'   => 'nullable|boolean',
            'lines.*.override_stone_charges'    => 'nullable|boolean',
            'lines.*.override_gst'              => 'nullable|boolean',
            'lines.*.override_waive_restocking' => 'nullable|boolean',
            'lines.*.override_wear_loss_pct'    => 'nullable|numeric|min:0|max:25',
            'lines.*.override_manual_total'     => 'nullable|numeric|min:0',
            'lines.*.override_reason'           => 'nullable|string|min:5|max:500',
        ]);

        $approverLineOverrides = [];
        foreach ($validated['lines'] ?? [] as $row) {
            $hasOverride = isset($row['override_making_charges'])   ||
                          isset($row['override_stone_charges'])    ||
                          isset($row['override_gst'])              ||
                          isset($row['override_waive_restocking']) ||
                          (isset($row['override_wear_loss_pct']) && $row['override_wear_loss_pct'] !== null) ||
                          isset($row['override_manual_total']);
            if ($hasOverride && isset($row['invoice_item_id'])) {
                $itemId = (int) $row['invoice_item_id'];
                $hasManualTotal = isset($row['override_manual_total']) && $row['override_manual_total'] !== null;
                $approverLineOverrides[$itemId] = [
                    'mode'                      => $hasManualTotal ? 'manual' : 'component',
                    'override_making_charges'   => isset($row['override_making_charges']) ? (bool) $row['override_making_charges'] : null,
                    'override_stone_charges'    => isset($row['override_stone_charges']) ? (bool) $row['override_stone_charges'] : null,
                    'override_gst'              => isset($row['override_gst']) ? (bool) $row['override_gst'] : null,
                    'override_waive_restocking' => isset($row['override_waive_restocking']) ? (bool) $row['override_waive_restocking'] : null,
                    'override_wear_loss_pct'    => isset($row['override_wear_loss_pct']) ? (float) $row['override_wear_loss_pct'] : null,
                    'override_manual_total'     => $hasManualTotal ? (float) $row['override_manual_total'] : null,
                    'override_reason'           => $row['override_reason'] ?? null,
                    'override_by_user_id'       => auth()->id(),
                ];
            }
        }

        try {
            $settled = $this->returnService->approveReturn($returnOrder, (int) auth()->id(), $approverLineOverrides);
        } catch (\LogicException $e) {
            return redirect()->route('returns.show', $returnOrder)
                ->with('error', $e->getMessage());
        }

        $cn = $settled->creditNote;
        return redirect()->route('returns.show', $settled)
            ->with('success', 'Return approved and processed. Credit note '
                . ($cn?->credit_note_number ?? '') . ' issued for ₹'
                . number_format((float) ($cn?->total ?? 0), 2) . '.');
    }

    /**
     * Phase B — manager rejects a pending return.
     */
    public function reject(Request $request, ReturnOrder $returnOrder)
    {
        $shopId = auth()->user()->shop_id;
        if ($returnOrder->shop_id !== $shopId) {
            abort(404);
        }
        if ($returnOrder->status !== 'pending_approval') {
            return redirect()->route('returns.show', $returnOrder)
                ->with('error', 'This return is not awaiting approval.');
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|min:5|max:500',
        ]);

        try {
            $this->returnService->rejectReturn($returnOrder, (int) auth()->id(), $validated['rejection_reason']);
        } catch (\LogicException $e) {
            return redirect()->route('returns.show', $returnOrder)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('returns.index')
            ->with('success', 'Return #' . $returnOrder->id . ' rejected.');
    }

    /**
     * Batch-restock items from the Control Center.
     *
     * Accepts a list of item IDs (from checkboxes in the "Decide What To Do"
     * queue). Only items still in status='pending_restock' are processed —
     * anything already moved on (melted, written off, etc.) is silently skipped.
     */
    public function batchRestock(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'item_ids'   => 'required|array|min:1|max:50',
            'item_ids.*' => 'integer',
        ]);

        $shopId = auth()->user()->shop_id;
        $count  = 0;

        foreach ($validated['item_ids'] as $itemId) {
            $item = \App\Models\Item::where('shop_id', $shopId)
                ->where('id', $itemId)
                ->where('status', 'pending_restock')
                ->first();

            if (! $item) {
                continue;
            }

            // Find the most recent return line for this item to link the disposition.
            $returnLine = ReturnLineItem::where('item_id', $item->id)
                ->latest('id')
                ->first();

            if (! $returnLine) {
                continue;
            }

            DB::transaction(function () use ($item, $returnLine, $shopId) {
                $item->update(['status' => 'in_stock']);
                ReturnedItemDisposition::create([
                    'shop_id'                  => $shopId,
                    'return_line_item_id'      => $returnLine->id,
                    'item_id'                  => $item->id,
                    'disposition'              => ReturnedItemDisposition::DISPOSITION_RESTOCKED,
                    'dispositioned_by_user_id' => auth()->id(),
                    'dispositioned_at'         => now(),
                    'notes'                    => 'Sent back to stock from Control Center',
                ]);
            });
            $count++;
        }

        return redirect()->route('returns.control-center')
            ->with('success', "{$count} item(s) sent back to stock.");
    }
}
