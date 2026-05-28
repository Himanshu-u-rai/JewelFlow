<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\StoreCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Phase 4 — customer store-credit wallet operations.
 *
 *   - Owner-only manual adjustments (goodwill / corrections).
 *   - Cashier-driven application of credit toward a finalized invoice's
 *     outstanding balance.
 *
 * Permission model:
 *   - Manual adjustment: only the shop owner (User::isOwner()). Bypasses the
 *     normal permission system because adjusting customer liabilities is a
 *     fiduciary-level action and we don't want it covered by a generic
 *     `sales.void` permission.
 *   - Apply to invoice: anyone with `sales.create` (same as recording a
 *     normal payment, route-level enforcement).
 */
class StoreCreditController extends Controller
{
    public function __construct(private StoreCreditService $service) {}

    public function adjustCreate(Customer $customer)
    {
        $this->ensureOwner();
        $this->ensureSameShop($customer);

        $balance = $this->service->balance((int) auth()->user()->shop_id, $customer->id);

        return view('store-credit.adjust', compact('customer', 'balance'));
    }

    public function adjustStore(Request $request, Customer $customer)
    {
        $this->ensureOwner();
        $this->ensureSameShop($customer);

        $validated = $request->validate([
            'direction' => 'required|in:credit,debit',
            'amount'    => 'required|numeric|min:0.01|max:1000000',
            'notes'     => 'required|string|min:5|max:500',
        ]);

        $signed = ($validated['direction'] === 'debit' ? -1 : 1) * (float) $validated['amount'];

        try {
            $this->service->manualAdjust(
                $customer,
                (int) auth()->user()->shop_id,
                $signed,
                $validated['notes'],
                (int) auth()->id(),
                (int) auth()->id(), // self-approval — owner is both actor and approver here
            );
        } catch (LogicException $e) {
            return back()->withInput()->withErrors(['adjust' => $e->getMessage()]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Catches the non-negative balance trigger if a debit would overdraft.
            return back()->withInput()->withErrors([
                'adjust' => str_contains($e->getMessage(), 'overdraft')
                    ? 'That debit would push the customer\'s balance below zero. Reduce the amount.'
                    : 'Database error: ' . $e->getMessage(),
            ]);
        }

        return redirect()->route('customers.show', $customer)
            ->with('success', 'Store credit adjusted.');
    }

    /**
     * Apply some or all of the customer's store credit toward a finalized
     * invoice's outstanding balance. Writes both a store-credit movement and
     * an InvoicePayment row (mode='wallet').
     */
    public function applyToInvoice(Request $request, Invoice $invoice)
    {
        $shopId = auth()->user()->shop_id;
        if ($invoice->shop_id !== $shopId) {
            abort(404);
        }
        $this->authorize('update', $invoice);

        if ($invoice->status !== Invoice::STATUS_FINALIZED) {
            return back()->withErrors(['store_credit' => 'Only finalized invoices can receive payments.']);
        }
        if (!$invoice->customer_id) {
            return back()->withErrors(['store_credit' => 'Invoice has no customer to charge credit from.']);
        }

        // Outstanding = total - sum(payments).
        $invoice->load('payments');
        $paid = (float) $invoice->payments->sum('amount');
        $outstanding = round((float) $invoice->total - $paid, 2);
        if ($outstanding <= 0) {
            return back()->withErrors(['store_credit' => 'Invoice has no outstanding balance.']);
        }

        $available = $this->service->balance($shopId, (int) $invoice->customer_id);
        $maxApplicable = min($outstanding, $available);

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:' . $maxApplicable,
        ]);

        $amount = round((float) $validated['amount'], 2);

        try {
            DB::transaction(function () use ($invoice, $amount, $shopId) {
                // 1. Debit the store credit ledger.
                $this->service->applyToInvoice($invoice, $amount, (int) auth()->id());

                // 2. Record an InvoicePayment with mode='wallet' so the invoice's
                //    outstanding balance reflects the application.
                InvoicePayment::record([
                    'shop_id'    => $shopId,
                    'invoice_id' => $invoice->id,
                    'mode'       => InvoicePayment::MODE_WALLET,
                    'amount'     => $amount,
                    'received_by' => auth()->id(),
                    'note'       => 'Applied from store credit wallet',
                ]);
            });
        } catch (LogicException $e) {
            return back()->withErrors(['store_credit' => $e->getMessage()]);
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->withErrors(['store_credit' => 'Database error: ' . $e->getMessage()]);
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('success', '₹' . number_format($amount, 2) . ' applied from store credit.');
    }

    private function ensureOwner(): void
    {
        $user = auth()->user();
        if (!$user || !method_exists($user, 'isOwner') || !$user->isOwner()) {
            abort(403, 'Only the shop owner can adjust store credit.');
        }
    }

    private function ensureSameShop(Customer $customer): void
    {
        if ($customer->shop_id !== auth()->user()->shop_id) {
            abort(404);
        }
    }
}
