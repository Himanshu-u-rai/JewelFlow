<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\InstallmentPlan;
use App\Services\InvoiceAccountingService;
use Illuminate\Http\Request;
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

        $emiMeta = [
            'is_retailer' => (bool) ($shop?->isRetailer()),
            'has_plan'    => $hasInstallmentPlan,
            'plan_id'     => $installmentPlan?->id,
            'outstanding' => $outstandingAmount,
            'eligible'    => (bool) ($shop?->isRetailer())
                && $invoice->status === Invoice::STATUS_FINALIZED
                && !$hasInstallmentPlan
                && $outstandingAmount > 0,
        ];

        return view('invoices.show', compact('invoice', 'emiMeta'));
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
