<?php

namespace App\Services;

use App\Models\InstallmentPlan;
use App\Models\InstallmentPayment;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\InvoicePayment;
use App\Models\CashTransaction;
use App\Models\Item;
use App\Services\InvoiceAccountingService;
use App\Services\SubscriptionGateService;
use Illuminate\Support\Facades\DB;
use LogicException;

class InstallmentService
{
    /**
     * Create an installment plan for an invoice.
     */
    public function createPlan(
        Invoice $invoice,
        Customer $customer,
        float $downPayment,
        int $totalEmis,
        ?float $emiAmount = null,
        float $interestRateAnnual = 0
    ): InstallmentPlan {
        $invoiceTotal = (float) ($invoice->total ?? $invoice->grand_total ?? 0);
        $principal = max(0, $invoiceTotal - $downPayment);
        $interestRateAnnual = max(0, $interestRateAnnual);
        $interestAmount = round($principal * ($interestRateAnnual / 100) * ($totalEmis / 12), 2);
        $totalPayable = round($principal + $interestAmount, 2);
        $remaining = $totalPayable;
        $emiAmount = $emiAmount ?? round($totalPayable / $totalEmis, 2);

        return InstallmentPlan::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'total_amount' => $invoiceTotal,
            'principal_amount' => $principal,
            'down_payment' => $downPayment,
            'interest_rate_annual' => $interestRateAnnual,
            'interest_amount' => $interestAmount,
            'total_payable' => $totalPayable,
            'remaining_amount' => $remaining,
            'emi_amount' => $emiAmount,
            'total_emis' => $totalEmis,
            'emis_paid' => 0,
            'next_due_date' => now()->addMonth()->toDateString(),
            'status' => 'active',
        ]);
    }

    /**
     * Record an EMI payment.
     */
    public function recordPayment(InstallmentPlan $plan, float $amount, string $paymentMethod = 'cash', ?string $notes = null): InstallmentPayment
    {
        return DB::transaction(function () use ($plan, $amount, $paymentMethod, $notes) {
            SubscriptionGateService::assertShopWritable((int) $plan->shop_id);

            $lockedPlan = InstallmentPlan::whereKey($plan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedPlan->status !== 'active') {
                throw new LogicException('Only active EMI plans can accept payments.');
            }

            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw new LogicException('EMI payment amount must be greater than zero.');
            }

            if ($amount > ((float) $lockedPlan->remaining_amount + 0.01)) {
                throw new LogicException('EMI payment exceeds remaining balance.');
            }

            $payment = InstallmentPayment::create([
                'plan_id' => $lockedPlan->id,
                'amount' => $amount,
                'payment_date' => now()->toDateString(),
                'payment_method' => $paymentMethod,
                'notes' => $notes,
            ]);

            $lockedPlan->increment('emis_paid');
            $lockedPlan->refresh();
            $remaining = max(0, (float) $lockedPlan->remaining_amount - $amount);

            // Update next due date
            if ($lockedPlan->emis_paid >= $lockedPlan->total_emis || $remaining <= 0.01) {
                $lockedPlan->update([
                    'status' => 'completed',
                    'next_due_date' => null,
                    'remaining_amount' => 0,
                ]);
            } else {
                $lockedPlan->update([
                    'next_due_date' => now()->addMonth()->toDateString(),
                    'remaining_amount' => $remaining,
                ]);
            }

            $invoiceMode = $this->toInvoicePaymentMode($paymentMethod);
            $invoice = Invoice::whereKey($lockedPlan->invoice_id)
                ->where('shop_id', $lockedPlan->shop_id)
                ->with('payments')
                ->lockForUpdate()
                ->first();

            if ($invoice) {
                $invoiceOutstanding = max(0, (float) $invoice->total - (float) $invoice->payments->sum('amount'));
                $principalAllocation = round(min($amount, $invoiceOutstanding), 2);

                if ($principalAllocation > 0) {
                    InvoicePayment::record([
                        'invoice_id' => $invoice->id,
                        'shop_id' => $invoice->shop_id,
                        'mode' => $invoiceMode,
                        'amount' => $principalAllocation,
                        'reference' => null,
                        'note' => 'EMI installment payment (principal allocation)',
                    ]);
                }

                CashTransaction::record([
                    'shop_id' => $lockedPlan->shop_id,
                    'user_id' => auth()->id(),
                    'type' => 'in',
                    'amount' => $amount,
                    'source_type' => 'installment',
                    'source_id' => $lockedPlan->id,
                    'invoice_id' => $invoice->id,
                    'payment_mode' => $invoiceMode,
                    'description' => "EMI installment payment - Invoice {$invoice->invoice_number}",
                ]);
            }

            return $payment;
        });
    }

    /**
     * Get all overdue plans for a shop.
     */
    public function getOverduePlans(): \Illuminate\Database\Eloquent\Collection
    {
        return InstallmentPlan::with(['customer', 'invoice'])
            ->active()
            ->where('next_due_date', '<', now()->toDateString())
            ->get();
    }

    /**
     * Total collected vs total outstanding.
     */
    public function summary(InstallmentPlan $plan): array
    {
        $paymentsTotal = $plan->relationLoaded('payments')
            ? (float) $plan->payments->sum('amount')
            : (float) InstallmentPayment::where('plan_id', $plan->id)->sum('amount');

        $totalPaid = (float) $plan->down_payment + $paymentsTotal;
        $outstanding = max(0, (float) $plan->remaining_amount);

        return [
            'total_amount' => (float) $plan->total_amount,
            'principal_amount' => (float) ($plan->principal_amount ?? 0),
            'down_payment' => (float) $plan->down_payment,
            'interest_rate_annual' => (float) ($plan->interest_rate_annual ?? 0),
            'interest_amount' => (float) ($plan->interest_amount ?? 0),
            'total_payable' => (float) ($plan->total_payable ?? 0),
            'total_paid' => $totalPaid,
            'outstanding' => $outstanding,
            'emis_remaining' => $plan->remainingEmis(),
            'is_overdue' => $plan->next_due_date && $plan->next_due_date < now()->toDateString(),
        ];
    }

    /**
     * Finalize POS EMI draft invoice and create EMI plan atomically.
     */
    public function finalizeDraftInvoiceToPlan(
        Invoice $invoice,
        float $downPayment,
        int $totalEmis,
        float $interestRateAnnual,
        string $downPaymentMethod = 'cash',
        ?string $downPaymentReference = null,
    ): InstallmentPlan {
        return DB::transaction(function () use (
            $invoice,
            $downPayment,
            $totalEmis,
            $interestRateAnnual,
            $downPaymentMethod,
            $downPaymentReference,
        ) {
            $lockedInvoice = Invoice::whereKey($invoice->id)
                ->where('shop_id', $invoice->shop_id)
                ->with(['customer', 'items'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInvoice->status !== Invoice::STATUS_DRAFT) {
                throw new LogicException('Only draft invoices can be completed from EMI checkout.');
            }

            if (!$lockedInvoice->customer_id || !$lockedInvoice->customer) {
                throw new LogicException('Draft invoice must be linked to a customer for EMI checkout.');
            }

            $itemIds = $lockedInvoice->items
                ->pluck('item_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->values();

            if ($itemIds->isEmpty()) {
                throw new LogicException('Draft invoice has no items.');
            }

            $items = Item::where('shop_id', $lockedInvoice->shop_id)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();

            if ($items->count() !== $itemIds->count()) {
                throw new LogicException('One or more invoice items are missing.');
            }

            foreach ($items as $item) {
                if ($item->status !== 'in_stock') {
                    throw new LogicException("Item {$item->barcode} is no longer available for EMI checkout.");
                }
            }

            $finalizedInvoice = InvoiceAccountingService::finalizeDraft($lockedInvoice, (float) $lockedInvoice->gst_rate);
            $invoiceTotal = (float) $finalizedInvoice->total;

            $downPayment = round($downPayment, 2);

            if ($downPayment < 0) {
                throw new LogicException('Down payment cannot be negative.');
            }

            if ($downPayment >= $invoiceTotal) {
                throw new LogicException('Down payment must be less than invoice total for EMI checkout.');
            }

            $this->recordInvoiceCollection(
                $finalizedInvoice,
                $downPayment,
                $downPaymentMethod,
                $downPaymentReference,
                'EMI down payment at plan creation'
            );

            $plan = $this->createPlan(
                $finalizedInvoice,
                $finalizedInvoice->customer,
                $downPayment,
                $totalEmis,
                null,
                $interestRateAnnual
            );

            foreach ($items as $item) {
                $item->status = 'sold';
                $item->save();
            }

            return $plan;
        });
    }

    private function recordInvoiceCollection(
        Invoice $invoice,
        float $amount,
        string $mode,
        ?string $reference,
        string $note
    ): void {
        if ($amount <= 0) {
            return;
        }

        InvoicePayment::record([
            'invoice_id' => $invoice->id,
            'shop_id' => $invoice->shop_id,
            'mode' => $mode,
            'amount' => $amount,
            'reference' => $reference,
            'note' => $note,
        ]);

        CashTransaction::record([
            'shop_id' => $invoice->shop_id,
            'user_id' => auth()->id(),
            'type' => 'in',
            'amount' => $amount,
            'source_type' => 'invoice',
            'source_id' => $invoice->id,
            'invoice_id' => $invoice->id,
            'payment_mode' => $mode,
            'description' => "{$note} - Invoice {$invoice->invoice_number}",
        ]);
    }

    private function toInvoicePaymentMode(string $installmentMethod): string
    {
        return match ($installmentMethod) {
            'bank', 'bank_transfer' => 'bank',
            'upi' => 'upi',
            'cash' => 'cash',
            'card' => 'other',
            default => 'other',
        };
    }
}
