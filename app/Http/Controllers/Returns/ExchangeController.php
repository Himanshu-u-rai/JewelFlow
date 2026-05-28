<?php

namespace App\Http\Controllers\Returns;

use App\Http\Controllers\Controller;
use App\Models\ExchangeOrder;
use App\Models\Invoice;
use App\Models\ReturnOrder;
use App\Services\Returns\ExchangeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Phase 3A — Exchange linker UI.
 *
 *  - `create`: shown from a settled ReturnOrder. Lets the cashier pick which
 *    finalized invoice to pair with it.
 *  - `store`:  links them, computes the net settlement, emits the cash entry.
 *  - `show`:   single combined receipt-style view of both halves of an exchange.
 */
class ExchangeController extends Controller
{
    public function __construct(private ExchangeService $exchangeService) {}

    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = \App\Models\ExchangeOrder::where('shop_id', $shopId)
            ->with([
                'returnOrder:id,invoice_id,reason,status',
                'returnOrder.creditNote:id,return_order_id,credit_note_number,total',
                'returnOrder.invoice:id,invoice_number,total',
                'newInvoice:id,invoice_number,total',
                'customer:id,first_name,last_name,mobile',
                'createdBy:id,name',
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

        $exchanges = $query->latest('id')->paginate(25)->withQueryString();

        // KPI
        $totalCount   = \App\Models\ExchangeOrder::where('shop_id', $shopId)->count();
        $settledCount = \App\Models\ExchangeOrder::where('shop_id', $shopId)->where('status', 'settled')->count();
        $todayNetFlow = \App\Models\ExchangeOrder::where('shop_id', $shopId)
            ->whereDate('created_at', today())
            ->sum('net_amount');

        return view('returns.exchanges.index', compact('exchanges', 'totalCount', 'settledCount', 'todayNetFlow'));
    }

    public function create(ReturnOrder $returnOrder)
    {
        // Phase 3A is superseded by the unified exchange flow (Phase 3B).
        // Redirect staff to the correct entry point: start an exchange from the original invoice.
        if ($returnOrder->invoice_id) {
            return redirect()->route('exchanges.unified.create', $returnOrder->invoice_id)
                ->with('info', 'Use the unified exchange form to process exchanges.');
        }
        return redirect()->route('returns.index')
            ->with('info', 'Please start an exchange from the original invoice.');
    }

    public function store(Request $request, ReturnOrder $returnOrder)
    {
        return redirect()->route('returns.show', $returnOrder)
            ->with('info', 'This exchange path is no longer active. Use the unified exchange form from the original invoice.');
    }

    /**
     * Phase 3B unified create form — from an invoice, pick what's coming back
     * AND what's being taken in one screen.
     */
    public function createUnified(\App\Models\Invoice $invoice)
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

        if ($invoice->status !== \App\Models\Invoice::STATUS_FINALIZED) {
            return redirect()->route('invoices.show', $invoice)
                ->with('error', 'Only finalized invoices can have items exchanged.');
        }

        $invoice->load(['items.item', 'customer', 'shop.preferences']);
        $defaultBasis = (string) ($invoice->shop?->preferences?->gold_rate_basis_for_exchange
            ?? ExchangeOrder::BASIS_SALE_DAY_RATE);

        $conditions = [
            \App\Models\ReturnLineItem::CONDITION_GOOD         => 'Good condition (re-sellable)',
            \App\Models\ReturnLineItem::CONDITION_MINOR_WEAR   => 'Minor wear',
            \App\Models\ReturnLineItem::CONDITION_DAMAGED      => 'Damaged',
            \App\Models\ReturnLineItem::CONDITION_NON_SELLABLE => 'Non-sellable',
        ];
        $dispositions = [
            \App\Models\ReturnedItemDisposition::DISPOSITION_RESTOCKED      => 'Restock — back on sale',
            \App\Models\ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT   => 'Send to melt',
            \App\Models\ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK => 'Send for rework',
            \App\Models\ReturnedItemDisposition::DISPOSITION_WRITTEN_OFF    => 'Write off',
        ];

        $shop = $invoice->shop;

        return view('returns.exchanges.create-unified',
            compact('invoice', 'conditions', 'dispositions', 'defaultBasis', 'shop'));
    }

    public function storeUnified(Request $request, \App\Models\Invoice $invoice)
    {
        $shopId = auth()->user()->shop_id;
        if ($invoice->shop_id !== $shopId) {
            abort(404);
        }
        $this->authorize('update', $invoice);

        $preferences = auth()->user()->shop?->preferences;
        if ($preferences && !$preferences->hasConfiguredReturnPolicy()) {
            return redirect()->route('settings.edit', ['tab' => 'return-policy'])
                ->with('warning', 'Please set up your return policy before processing this exchange. This takes about 1 minute.');
        }

        $validated = $request->validate([
            'reason'                  => 'required|string|min:5|max:500',
            'lines'                   => 'required|array|min:1',
            'lines.*.invoice_item_id' => ['required', 'integer', Rule::exists('invoice_items', 'id')->where('invoice_id', $invoice->id)],
            'lines.*.condition'       => ['required', Rule::in([
                \App\Models\ReturnLineItem::CONDITION_GOOD,
                \App\Models\ReturnLineItem::CONDITION_MINOR_WEAR,
                \App\Models\ReturnLineItem::CONDITION_DAMAGED,
                \App\Models\ReturnLineItem::CONDITION_NON_SELLABLE,
            ])],
            'lines.*.disposition'     => ['required', Rule::in([
                \App\Models\ReturnedItemDisposition::DISPOSITION_RESTOCKED,
                \App\Models\ReturnedItemDisposition::DISPOSITION_SENT_TO_MELT,
                \App\Models\ReturnedItemDisposition::DISPOSITION_SENT_TO_REWORK,
                \App\Models\ReturnedItemDisposition::DISPOSITION_WRITTEN_OFF,
            ])],
            'lines.*.override_making_charges'   => 'nullable|boolean',
            'lines.*.override_stone_charges'    => 'nullable|boolean',
            'lines.*.override_gst'              => 'nullable|boolean',
            'lines.*.override_waive_restocking' => 'nullable|boolean',
            'lines.*.override_wear_loss_pct'    => 'nullable|numeric|min:0|max:25',
            'lines.*.override_manual_total'     => 'nullable|numeric|min:0',
            'lines.*.override_reason'           => 'nullable|string|min:5|max:500',
            'new_item_barcodes'            => 'required|string|max:1000',
            'valuation_basis_source'       => ['required', Rule::in([
                ExchangeOrder::BASIS_SALE_DAY_RATE,
                ExchangeOrder::BASIS_TODAY_RATE,
                ExchangeOrder::BASIS_MANUAL_OVERRIDE,
            ])],
            'gold_rate_per_gram_override'  => 'nullable|numeric|min:0.01',
            'override_reason'              => 'nullable|string|max:500',
        ]);

        // Resolve barcodes → item IDs.
        $barcodes = collect(preg_split('/[,\s]+/', $validated['new_item_barcodes']))
            ->map(fn ($b) => trim($b))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if (empty($barcodes)) {
            return back()->withInput()->withErrors(['new_item_barcodes' => 'Enter at least one barcode of the items the customer is taking.']);
        }

        $items = \App\Models\Item::where('shop_id', $shopId)
            ->whereIn('barcode', $barcodes)
            ->get(['id', 'barcode']);
        $found = $items->pluck('barcode')->all();
        $missing = array_diff($barcodes, $found);
        if (!empty($missing)) {
            return back()->withInput()->withErrors([
                'new_item_barcodes' => 'Unknown barcode(s): ' . implode(', ', $missing) . '. Check the codes.',
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
                'reason'      => null,
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

        // Phase C: exchange-window enforcement ───────────────────────────────
        $invoice->loadMissing('shop.preferences');
        $shopPolicy          = $invoice->shop?->preferences;
        $exchangeWindowDays  = (int) ($shopPolicy?->exchange_window_days ?? 0);
        if ($exchangeWindowDays > 0) {
            $invoiceDate = Carbon::parse($invoice->finalized_at ?? $invoice->created_at);
            $daysSince   = (int) $invoiceDate->diffInDays(now());
            if ($daysSince > $exchangeWindowDays && !auth()->user()->can('returns.approve')) {
                return back()->withInput()->withErrors([
                    'exchange' => "The exchange window of {$exchangeWindowDays} days has passed (invoice is {$daysSince} days old). Requires 'Approve Returns' permission.",
                ]);
            }
        }

        // Phase D: exchange_rate_basis_locked check ────────────────────────────
        $defaultBasis = (string) ($shopPolicy?->gold_rate_basis_for_exchange ?? ExchangeOrder::BASIS_SALE_DAY_RATE);
        if (($shopPolicy?->exchange_rate_basis_locked ?? false) && $validated['valuation_basis_source'] !== $defaultBasis) {
            return back()->withInput()->withErrors([
                'valuation_basis_source' => 'The exchange rate basis is locked to the shop default (' . str_replace('_', ' ', $defaultBasis) . ') by shop policy.',
            ]);
        }

        // Phase D: manual_override requires exchanges.override_rate permission ─
        $rateOverride = null;
        if ($validated['valuation_basis_source'] === ExchangeOrder::BASIS_MANUAL_OVERRIDE) {
            if (!auth()->user()->can('exchanges.override_rate')) {
                return back()->withInput()->withErrors([
                    'valuation_basis_source' => 'Manual rate override requires the "Override Exchange Rate Basis" permission.',
                ]);
            }
            $overrideRate = (float) ($validated['gold_rate_per_gram_override'] ?? 0);
            if ($overrideRate <= 0) {
                return back()->withInput()->withErrors([
                    'gold_rate_per_gram_override' => 'Enter a positive override rate per gram.',
                ]);
            }
            // ±20% sanity check against today's rate.
            $todaysRate = app(\App\Services\ShopPricingService::class)->currentDailyRate($invoice->shop);
            if ($todaysRate) {
                $marketRate = (float) $todaysRate->gold_24k_rate_per_gram;
                if ($marketRate > 0) {
                    $deviation = abs($overrideRate - $marketRate) / $marketRate;
                    if ($deviation > 0.20) {
                        return back()->withInput()->withErrors([
                            'gold_rate_per_gram_override' => 'Override rate ₹' . number_format($overrideRate, 2) . '/g deviates more than 20% from today\'s rate ₹' . number_format($marketRate, 2) . '/g. Please verify.',
                        ]);
                    }
                }
            }
            $rateOverride = $overrideRate;
        }

        try {
            $exchange = app(\App\Services\Returns\ExchangeService::class)->createUnified(
                $invoice,
                $selections,
                $items->pluck('id')->all(),
                $validated['valuation_basis_source'],
                $validated['reason'],
                (int) auth()->id(),
                $rateOverride,
                $lineOverrides,
            );
        } catch (LogicException $e) {
            return back()->withInput()->withErrors(['exchange' => $e->getMessage()]);
        }

        $cn = $exchange->returnOrder?->creditNote;
        $newInv = $exchange->newInvoice;
        return redirect()->route('exchanges.show', $exchange)
            ->with('success',
                'Exchange settled. Return ' . ($cn?->credit_note_number ?? '') .
                ' ↔ new sale ' . ($newInv?->invoice_number ?? '') .
                '. Net ' . $this->describeNet($exchange) . '.'
            );
    }

    public function show(ExchangeOrder $exchange)
    {
        $shopId = auth()->user()->shop_id;
        if ($exchange->shop_id !== $shopId) {
            abort(404);
        }
        $exchange->load([
            'returnOrder.creditNote',
            'returnOrder.lineItems.item',
            'newInvoice.customer',
            'newInvoice.items.item',
            'newInvoice.payments',
            'customer',
            'createdBy',
            'settledBy',
        ]);

        // For the show page: surface the *actual* payment methods used at each
        // half of the exchange (not the meaningless payment_method on the
        // exchange row itself in Phase 3A).
        $refundCashOut = \App\Models\CashTransaction::where('source_type', 'credit_note')
            ->where('source_id', optional($exchange->returnOrder?->creditNote)->id)
            ->first();
        $newSalePaymentMethods = $exchange->newInvoice?->payments?->pluck('mode')->unique()->values() ?? collect();

        return view('returns.exchanges.show', compact('exchange', 'refundCashOut', 'newSalePaymentMethods'));
    }

    /**
     * Printable combined receipt for an exchange — return half + new sale half
     * + net on one page, designed for browser print (Ctrl-P).
     */
    public function receipt(ExchangeOrder $exchange)
    {
        $shopId = auth()->user()->shop_id;
        if ($exchange->shop_id !== $shopId) {
            abort(404);
        }
        $exchange->load([
            'returnOrder.creditNote',
            'returnOrder.lineItems.item',
            'newInvoice.customer',
            'newInvoice.items.item',
            'newInvoice.payments',
            'customer',
            'shop',
        ]);

        return view('returns.exchanges.receipt', compact('exchange'));
    }

    private function describeNet(ExchangeOrder $exchange): string
    {
        $abs = number_format(abs((float) $exchange->net_amount), 2);
        if ((float) $exchange->net_amount > 0.005) {
            return "customer paid ₹{$abs}";
        }
        if ((float) $exchange->net_amount < -0.005) {
            return "shop refunded ₹{$abs}";
        }
        return 'even swap';
    }
}
