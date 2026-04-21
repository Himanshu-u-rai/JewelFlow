<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Scheme;
use App\Models\SchemeEnrollment;
use App\Models\SchemeLedgerEntry;
use App\Models\SchemePayment;
use App\Models\SchemeRedemption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class SchemeService
{
    /**
     * Enroll a customer in a gold savings scheme.
     */
    public function enroll(
        Scheme $scheme,
        Customer $customer,
        float $monthlyAmount,
        ?string $notes = null,
        bool $termsAccepted = false
    ): SchemeEnrollment {
        $shopId = (int) $scheme->shop_id;

        SubscriptionGateService::assertShopWritable($shopId);
        $this->assertFinancialLockOpen($shopId, now()->toDateString());

        if (!$scheme->isGoldSavings()) {
            throw new LogicException('Only gold savings schemes support enrollments.');
        }

        if (!$scheme->isRunning()) {
            throw new LogicException('This scheme is not currently active.');
        }

        if ((int) $customer->shop_id !== $shopId) {
            throw new LogicException('Customer does not belong to this shop.');
        }

        if ($monthlyAmount <= 0) {
            throw new LogicException('Monthly amount must be greater than zero.');
        }

        if (!$termsAccepted) {
            throw new LogicException('Please accept scheme terms before enrollment.');
        }

        $alreadyActive = SchemeEnrollment::query()
            ->where('shop_id', $shopId)
            ->where('scheme_id', $scheme->id)
            ->where('customer_id', $customer->id)
            ->whereIn('status', ['active', 'matured'])
            ->exists();

        if ($alreadyActive) {
            throw new LogicException('Customer already has an active enrollment in this scheme.');
        }

        $totalInstallments = (int) ($scheme->total_installments ?? 11);
        $bonusAmount = (float) ($scheme->bonus_month_value ?? $monthlyAmount);

        $enrollment = new SchemeEnrollment();
        $enrollment->forceFill([
            'shop_id' => $shopId,
            'scheme_id' => $scheme->id,
            'customer_id' => $customer->id,
            'start_date' => now()->toDateString(),
            'terms_accepted_at' => now(),
            'terms_version' => sha1((string) ($scheme->updated_at?->toDateTimeString() ?? now()->toDateTimeString())),
            'monthly_amount' => round($monthlyAmount, 2),
            'total_paid' => 0,
            'redeemed_amount' => 0,
            'redemption_count' => 0,
            'installments_paid' => 0,
            'total_installments' => $totalInstallments,
            'maturity_date' => now()->addMonths($totalInstallments)->toDateString(),
            'status' => 'active',
            'bonus_amount' => round($bonusAmount, 2),
            'is_bonus_accrued' => false,
            'notes' => $notes,
        ]);
        $enrollment->save();

        AccountingAuditService::log([
            'shop_id' => $shopId,
            'action' => 'scheme_enrollment_created',
            'model_type' => 'scheme_enrollment',
            'model_id' => $enrollment->id,
            'description' => "Customer enrolled in scheme {$scheme->name}",
            'after' => $enrollment->toArray(),
            'target' => ['type' => 'scheme_enrollment', 'id' => $enrollment->id],
        ]);

        return $enrollment;
    }

    /**
     * Record a monthly contribution payment.
     */
    public function recordPayment(
        SchemeEnrollment $enrollment,
        float $amount,
        string $paymentMethod = 'cash',
        ?string $receiptNumber = null,
        ?string $notes = null
    ): SchemePayment {
        return DB::transaction(function () use ($enrollment, $amount, $paymentMethod, $receiptNumber, $notes) {
            $locked = SchemeEnrollment::query()
                ->whereKey($enrollment->id)
                ->lockForUpdate()
                ->firstOrFail();

            SubscriptionGateService::assertShopWritable((int) $locked->shop_id);
            $this->assertFinancialLockOpen((int) $locked->shop_id, now()->toDateString());

            if ($locked->status !== 'active') {
                throw new LogicException('Only active scheme enrollments can receive payments.');
            }

            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw new LogicException('Payment amount must be greater than zero.');
            }

            $nextInstallment = (int) $locked->installments_paid + 1;

            $cashTransaction = CashTransaction::record([
                'shop_id' => $locked->shop_id,
                'user_id' => auth()->id(),
                'type' => 'in',
                'amount' => $amount,
                'source_type' => 'scheme_payment',
                'source_id' => $locked->id,
                'invoice_id' => null,
                'payment_mode' => $this->normalizePaymentMode($paymentMethod),
                'description' => "Scheme payment ({$nextInstallment}) - Enrollment {$locked->id}",
            ]);

            $payment = SchemePayment::record([
                'shop_id' => $locked->shop_id,
                'enrollment_id' => $locked->id,
                'amount' => $amount,
                'payment_date' => now()->toDateString(),
                'installment_number' => $nextInstallment,
                'payment_method' => $paymentMethod,
                'receipt_number' => $receiptNumber,
                'cash_transaction_id' => $cashTransaction->id,
                'notes' => $notes,
            ]);

            $totalPaid = round((float) $locked->total_paid + $amount, 2);
            $installmentsPaid = $nextInstallment;
            $isMatured = $installmentsPaid >= (int) $locked->total_installments;

            $updates = [
                'total_paid' => $totalPaid,
                'installments_paid' => $installmentsPaid,
                'last_payment_at' => now()->toDateString(),
                'status' => $isMatured ? 'matured' : 'active',
            ];

            if ($isMatured && !$locked->is_bonus_accrued) {
                $updates['is_bonus_accrued'] = true;
                $updates['maturity_bonus_accrued_at'] = now();
            }

            $locked->update($updates);
            $locked->refresh();

            $this->appendLedgerEntry(
                $locked,
                'contribution',
                'credit',
                $amount,
                [
                    'scheme_payment_id' => $payment->id,
                    'installment_number' => $nextInstallment,
                    'payment_method' => $paymentMethod,
                ],
                $payment,
                null,
                'Monthly scheme contribution'
            );

            if ($isMatured && (float) $locked->bonus_amount > 0) {
                $this->appendLedgerEntry(
                    $locked,
                    'bonus_accrual',
                    'credit',
                    round((float) $locked->bonus_amount, 2),
                    [
                        'accrued_at_maturity' => true,
                        'maturity_date' => optional($locked->maturity_date)->toDateString(),
                    ],
                    null,
                    null,
                    'Scheme maturity bonus accrued'
                );
            }

            AccountingAuditService::log([
                'shop_id' => $locked->shop_id,
                'action' => 'scheme_payment_recorded',
                'model_type' => 'scheme_payment',
                'model_id' => $payment->id,
                'description' => "Scheme payment recorded for enrollment {$locked->id}",
                'after' => $payment->toArray(),
                'target' => ['type' => 'scheme_payment', 'id' => $payment->id],
            ]);

            return $payment;
        });
    }

    /**
     * Apply scheme redemption against a finalized invoice.
     */
    public function applyRedemptionToInvoice(
        SchemeEnrollment $enrollment,
        Invoice $invoice,
        float $amount,
        ?string $note = null
    ): SchemeRedemption {
        return DB::transaction(function () use ($enrollment, $invoice, $amount, $note) {
            $lockedInvoice = Invoice::query()
                ->whereKey($invoice->id)
                ->lockForUpdate()
                ->with('payments')
                ->firstOrFail();

            if ($lockedInvoice->status !== Invoice::STATUS_FINALIZED) {
                throw new LogicException('Scheme redemption is allowed only on finalized invoices.');
            }

            $lockedEnrollment = SchemeEnrollment::query()
                ->whereKey($enrollment->id)
                ->where('shop_id', $lockedInvoice->shop_id)
                ->where('customer_id', $lockedInvoice->customer_id)
                ->lockForUpdate()
                ->firstOrFail();

            SubscriptionGateService::assertShopWritable((int) $lockedEnrollment->shop_id);
            $this->assertFinancialLockOpen((int) $lockedEnrollment->shop_id, now()->toDateString());

            if (!in_array($lockedEnrollment->status, ['active', 'matured', 'redeemed'], true)) {
                throw new LogicException('This enrollment cannot be redeemed in its current status.');
            }

            $requested = round($amount, 2);
            if ($requested <= 0) {
                throw new LogicException('Redemption amount must be greater than zero.');
            }

            $available = $this->redeemableValue($lockedEnrollment);
            if ($available <= 0) {
                throw new LogicException('No redeemable amount is available in this enrollment.');
            }

            $invoiceOutstanding = max(0, round((float) $lockedInvoice->total - (float) $lockedInvoice->payments->sum('amount'), 2));
            if ($invoiceOutstanding <= 0) {
                throw new LogicException('Invoice is already fully paid.');
            }

            $usableAmount = min($requested, $available, $invoiceOutstanding);
            if ($usableAmount <= 0) {
                throw new LogicException('Unable to apply redemption to this invoice.');
            }

            [$principalUsed, $bonusUsed] = $this->allocateRedemptionComponents($lockedEnrollment, $usableAmount);

            $invoicePayment = InvoicePayment::record([
                'invoice_id' => $lockedInvoice->id,
                'shop_id' => $lockedInvoice->shop_id,
                'mode' => InvoicePayment::MODE_SCHEME,
                'amount' => $usableAmount,
                'reference' => null,
                'note' => $note ?: 'Scheme redemption applied',
            ]);

            $redemption = SchemeRedemption::record([
                'shop_id' => $lockedEnrollment->shop_id,
                'scheme_enrollment_id' => $lockedEnrollment->id,
                'invoice_id' => $lockedInvoice->id,
                'invoice_payment_id' => $invoicePayment->id,
                'amount' => $usableAmount,
                'principal_component' => $principalUsed,
                'bonus_component' => $bonusUsed,
                'redeemed_at' => now(),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            $newRedeemed = round((float) $lockedEnrollment->redeemed_amount + $usableAmount, 2);
            $grossEntitlement = round((float) $lockedEnrollment->total_paid + $lockedEnrollment->accruedBonusAmount(), 2);
            $fullyRedeemed = $newRedeemed >= ($grossEntitlement - 0.01);

            $lockedEnrollment->update([
                'redeemed_amount' => $newRedeemed,
                'redemption_count' => (int) $lockedEnrollment->redemption_count + 1,
                'redeemed_at' => $fullyRedeemed ? now() : $lockedEnrollment->redeemed_at,
                'status' => $fullyRedeemed ? 'redeemed' : $lockedEnrollment->status,
            ]);

            $this->appendLedgerEntry(
                $lockedEnrollment,
                'redemption',
                'debit',
                $usableAmount,
                [
                    'invoice_id' => $lockedInvoice->id,
                    'invoice_number' => $lockedInvoice->invoice_number,
                    'principal_component' => $principalUsed,
                    'bonus_component' => $bonusUsed,
                ],
                null,
                $redemption,
                'Scheme redemption used in invoice'
            );

            AccountingAuditService::log([
                'shop_id' => $lockedEnrollment->shop_id,
                'action' => 'scheme_redemption_applied',
                'model_type' => 'scheme_redemption',
                'model_id' => $redemption->id,
                'description' => "Scheme redemption applied to invoice {$lockedInvoice->invoice_number}",
                'after' => $redemption->toArray(),
                'target' => ['type' => 'scheme_redemption', 'id' => $redemption->id],
            ]);

            return $redemption;
        });
    }

    public function cancelEnrollment(SchemeEnrollment $enrollment): SchemeEnrollment
    {
        SubscriptionGateService::assertShopWritable((int) $enrollment->shop_id);
        $this->assertFinancialLockOpen((int) $enrollment->shop_id, now()->toDateString());

        $enrollment->update(['status' => 'cancelled']);

        AccountingAuditService::log([
            'shop_id' => $enrollment->shop_id,
            'action' => 'scheme_enrollment_cancelled',
            'model_type' => 'scheme_enrollment',
            'model_id' => $enrollment->id,
            'description' => 'Scheme enrollment cancelled.',
            'after' => $enrollment->fresh()->toArray(),
            'target' => ['type' => 'scheme_enrollment', 'id' => $enrollment->id],
        ]);

        return $enrollment->fresh();
    }

    /**
     * Get total redeemable value (paid + matured bonus - already redeemed).
     */
    public function redeemableValue(SchemeEnrollment $enrollment): float
    {
        $enrollment->refresh();
        return $enrollment->redeemableAmount();
    }

    /**
     * @return Collection<int, SchemeEnrollment>
     */
    public function redeemableEnrollmentsForCustomer(int $shopId, int $customerId): Collection
    {
        return SchemeEnrollment::query()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customerId)
            ->whereIn('status', ['active', 'matured', 'redeemed'])
            ->with('scheme:id,name,type')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (SchemeEnrollment $enrollment) => $enrollment->redeemableAmount() > 0)
            ->values();
    }

    private function normalizePaymentMode(string $method): string
    {
        return match ($method) {
            'cash' => 'cash',
            'upi' => 'upi',
            'bank_transfer', 'bank' => 'bank',
            default => 'other',
        };
    }

    /**
     * @return array{0:float,1:float}
     */
    private function allocateRedemptionComponents(SchemeEnrollment $enrollment, float $amount): array
    {
        $principalRedeemed = (float) SchemeRedemption::query()
            ->where('shop_id', $enrollment->shop_id)
            ->where('scheme_enrollment_id', $enrollment->id)
            ->sum('principal_component');

        $bonusRedeemed = (float) SchemeRedemption::query()
            ->where('shop_id', $enrollment->shop_id)
            ->where('scheme_enrollment_id', $enrollment->id)
            ->sum('bonus_component');

        $principalRemaining = max(0, round((float) $enrollment->total_paid - $principalRedeemed, 2));
        $bonusRemaining = max(0, round($enrollment->accruedBonusAmount() - $bonusRedeemed, 2));

        $principalUsed = min($amount, $principalRemaining);
        $bonusUsed = round($amount - $principalUsed, 2);

        if ($bonusUsed > ($bonusRemaining + 0.01)) {
            throw new LogicException('Requested redemption exceeds available scheme bonus balance.');
        }

        return [round($principalUsed, 2), max(0, $bonusUsed)];
    }

    private function appendLedgerEntry(
        SchemeEnrollment $enrollment,
        string $entryType,
        string $direction,
        float $amount,
        array $meta = [],
        ?SchemePayment $payment = null,
        ?SchemeRedemption $redemption = null,
        ?string $note = null
    ): SchemeLedgerEntry {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new LogicException('Ledger entry amount must be greater than zero.');
        }

        $previousBalance = (float) SchemeLedgerEntry::query()
            ->where('shop_id', $enrollment->shop_id)
            ->where('scheme_enrollment_id', $enrollment->id)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('balance_after');

        $balanceAfter = $direction === 'credit'
            ? round($previousBalance + $amount, 2)
            : round($previousBalance - $amount, 2);

        if ($balanceAfter < -0.01) {
            throw new LogicException('Scheme ledger balance cannot become negative.');
        }

        return SchemeLedgerEntry::record([
            'shop_id' => $enrollment->shop_id,
            'scheme_enrollment_id' => $enrollment->id,
            'scheme_payment_id' => $payment?->id,
            'scheme_redemption_id' => $redemption?->id,
            'entry_type' => $entryType,
            'direction' => $direction,
            'amount' => $amount,
            'balance_after' => max(0, $balanceAfter),
            'note' => $note,
            'meta' => $meta,
            'created_by' => auth()->id(),
        ]);
    }

    private function assertFinancialLockOpen(int $shopId, string $operationDate): void
    {
        $lockDate = DB::table('shop_rules')
            ->where('shop_id', $shopId)
            ->value('financial_lock_date');

        if ($lockDate && $operationDate <= $lockDate) {
            throw new LogicException("Financial lock is active through {$lockDate}. Operation blocked.");
        }
    }
}
