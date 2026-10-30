<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InstallmentPlan;
use App\Models\ShopPaymentMethod;
use App\Services\InvoiceAccountingService;
use App\Services\InvoiceDueService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $shopId = auth()->user()->shop_id;

        $query = Invoice::where('shop_id', $shopId)
            ->with('customer', 'payments');

        // Date filtering — validate format before use.
        if ($request->filled('from_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->from_date)) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date') && preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->to_date)) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $validStatuses = [
            Invoice::STATUS_DRAFT,
            Invoice::STATUS_FINALIZED,
            Invoice::STATUS_CANCELLED,
        ];

        if ($request->filled('status') && in_array($request->status, $validStatuses, true)) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_mode') && in_array($request->payment_mode, InvoicePayment::VALID_MODES, true)) {
            $query->whereHas('payments', function ($paymentQuery) use ($request) {
                $paymentQuery->where('mode', $request->payment_mode);
            });
        }

        // Search by invoice number or customer.
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('invoice_number', 'ilike', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('first_name', 'ilike', "%{$search}%")
                         ->orWhere('last_name', 'ilike', "%{$search}%")
                         ->orWhere('mobile', 'like', "%{$search}%");
                  });
            });
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();

        // Stats — single aggregate query using PostgreSQL FILTER syntax.
        // Monetary totals (today/month) only count finalized invoices.
        $today = today()->toDateString();
        $stats = Invoice::where('shop_id', $shopId)
            ->selectRaw(
                "COUNT(*) as total_count,
                 COUNT(*) FILTER (WHERE DATE(created_at) = ?) as today_count,
                 COALESCE(SUM(total) FILTER (WHERE DATE(created_at) = ? AND status = ?), 0) as today_total,
                 COALESCE(SUM(total) FILTER (WHERE DATE_TRUNC('month', created_at) = DATE_TRUNC('month', CURRENT_DATE) AND status = ?), 0) as month_total",
                [$today, $today, Invoice::STATUS_FINALIZED, Invoice::STATUS_FINALIZED]
            )
            ->first();

        return view('invoices.index', compact('invoices', 'stats'));
    }

    public function show(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load('customer', 'items', 'payments', 'offerApplication', 'schemeRedemptions');
        $shop = auth()->user()->shop;

        $paidAmount        = (float) $invoice->payments->sum('amount');
        $outstandingAmount = max(0, (float) $invoice->total - $paidAmount);
        $installmentPlan   = InstallmentPlan::where('shop_id', $invoice->shop_id)
            ->where('invoice_id', $invoice->id)
            ->first();
        $hasInstallmentPlan = $installmentPlan !== null;
        // An active plan collects this bill's due; no direct payment or store credit.
        $collectThroughPlan = (bool) $installmentPlan?->isActive();

        $emiMeta = [
            'is_retailer' => (bool) ($shop?->isRetailer()),
            'has_plan'    => $hasInstallmentPlan,
            'plan_id'     => $installmentPlan?->id,
            'collect_through_plan' => $collectThroughPlan,
            'outstanding' => $outstandingAmount,
            'eligible'    => (bool) ($shop?->isRetailer())
                && $invoice->status === Invoice::STATUS_FINALIZED
                && !$hasInstallmentPlan
                && $outstandingAmount > 0,
        ];

        // Store-credit application: only when the finalized invoice has an
        // outstanding balance, a customer, and that customer has wallet credit.
        $storeCreditAvailable = 0.0;
        if ($invoice->customer_id
            && $invoice->status === Invoice::STATUS_FINALIZED
            && $outstandingAmount > 0
            && !$collectThroughPlan) {
            $storeCreditAvailable = app(\App\Services\StoreCreditService::class)
                ->balance((int) $invoice->shop_id, (int) $invoice->customer_id);
        }
        $storeCreditApplicable = round(min($outstandingAmount, $storeCreditAvailable), 2);

        // Accounts for the Record payment form: UPI / bank are offered only when one is set up.
        $paymentAccounts = ShopPaymentMethod::query()
            ->where('shop_id', $invoice->shop_id)
            ->active()
            ->whereIn('type', [ShopPaymentMethod::TYPE_UPI, ShopPaymentMethod::TYPE_BANK])
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ShopPaymentMethod $m) => ['id' => (int) $m->id, 'type' => (string) $m->type, 'label' => (string) $m->account_label])
            ->values();

        return view('invoices.show', compact('invoice', 'emiMeta', 'outstandingAmount', 'storeCreditAvailable', 'storeCreditApplicable', 'paymentAccounts'));
    }

    /**
     * Money received later against a finalized invoice's due. The app's twin is
     * POST /api/mobile/invoices/{invoice}/payments; both go through InvoiceDueService.
     */
    public function storePayment(Request $request, Invoice $invoice, InvoiceDueService $dues)
    {
        $this->authorize('update', $invoice);

        $shopId = (int) $invoice->shop_id;
        $payments = $request->validate(InvoiceDueService::rules($shopId))['payments'];
        $dues->validatePaymentMethods($payments, $shopId);

        $total = DB::transaction(function () use ($invoice, $payments, $dues) {
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            return $dues->collect($locked, $payments, (int) auth()->id(), 'web')['total'];
        });

        return redirect()->route('invoices.show', $invoice)
            ->with('success', '₹' . number_format($total, 2) . ' received on ' . $invoice->invoice_number . '.');
    }

    public function edit(Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        $invoice->load('customer', 'items');

        return view('invoices.edit', compact('invoice'));
    }

    public function update(Request $request, Invoice $invoice)
    {
        $this->authorize('update', $invoice);

        $validated = $request->validate([
            'action'              => 'nullable|in:finalize,cancel',
            'gst_rate'            => 'nullable|numeric|min:0|max:100',
            'cancellation_reason' => 'nullable|string|max:1000',
        ]);

        $action = $validated['action']
            ?? (($request->input('status') === 'cancelled' || $request->filled('cancellation_reason')) ? 'cancel' : 'finalize');

        // Cancel / void is a higher-privilege action than finalizing a draft.
        // The route is gated 'sales.create' so cashiers can finalize; the void
        // branch requires 'sales.void' here.
        if ($action === 'cancel') {
            abort_unless($request->user()->can('sales.void'), 403);
        }

        try {
            if ($action === 'finalize') {
                InvoiceAccountingService::finalizeDraft($invoice, isset($validated['gst_rate']) ? (float) $validated['gst_rate'] : null);

                return redirect()->route('invoices.show', $invoice)
                    ->with('success', 'Invoice finalized successfully.');
            }

            $reason   = trim((string) ($validated['cancellation_reason'] ?? 'Cancelled by user'));
            $reversal = InvoiceAccountingService::cancelByReversal($invoice, $reason);

            return redirect()->route('invoices.show', $reversal)
                ->with('success', 'Invoice cancelled via reversal invoice ' . $reversal->invoice_number . '.');
        } catch (LogicException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }
    }

    public function print(Invoice $invoice)
    {
        $this->authorize('view', $invoice);

        $invoice->load('customer', 'items', 'payments');

        return view('invoice_print', compact('invoice'));
    }
}
