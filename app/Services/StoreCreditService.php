<?php

namespace App\Services;

use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\StoreCreditMovement;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Customer store-credit wallet — Phase 4 of the Returns & Exchanges domain.
 *
 * All operations write to the append-only `store_credit_movements` ledger.
 * Balance is computed on read, never cached. The DB trigger
 * `store_credit_non_negative_guard` ensures the running balance per
 * (shop, customer) can never go below zero — service-level checks here are
 * defensive guidance with clearer error messages, but the DB has the last word.
 */
class StoreCreditService
{
    /**
     * Current available balance for a customer at a shop, in rupees.
     */
    public function balance(int $shopId, int $customerId): float
    {
        return (float) StoreCreditMovement::query()
            ->withoutTenant()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customerId)
            ->sum('amount');
    }

    /**
     * Credit a customer from a credit-note refund (the customer chose store
     * credit instead of cash). Called by ReturnService when refund_settlement
     * = 'store_credit'.
     */
    public function creditFromCreditNote(
        CreditNote $creditNote,
        int $userId,
        ?\Carbon\CarbonInterface $expiresAt = null,
    ): StoreCreditMovement {
        if (!$creditNote->customer_id) {
            throw new LogicException('Cannot credit store wallet: credit note has no customer.');
        }

        return DB::transaction(function () use ($creditNote, $userId, $expiresAt) {
            return StoreCreditMovement::create([
                'shop_id'             => $creditNote->shop_id,
                'customer_id'         => $creditNote->customer_id,
                'amount'              => (float) $creditNote->total,
                'source_type'         => StoreCreditMovement::SOURCE_CREDIT_NOTE_ISSUED,
                'source_id'           => $creditNote->id,
                'expires_at'          => $expiresAt,
                'notes'               => "Refund from {$creditNote->credit_note_number} taken as store credit.",
                'user_id'             => $userId,
                'approved_by_user_id' => null,
            ]);
        });
    }

    /**
     * Apply store credit toward a customer's invoice payment. Writes a
     * negative-amount movement; the DB trigger blocks if balance would go negative.
     *
     * Returns the movement row. Caller is responsible for creating the
     * corresponding InvoicePayment row (mode='wallet') to reflect the payment
     * against the invoice.
     */
    public function applyToInvoice(Invoice $invoice, float $amount, int $userId): StoreCreditMovement
    {
        if ($amount <= 0) {
            throw new LogicException('Amount to apply must be positive.');
        }
        if (!$invoice->customer_id) {
            throw new LogicException('Cannot apply store credit: invoice has no customer.');
        }
        $available = $this->balance((int) $invoice->shop_id, (int) $invoice->customer_id);
        if ($available + 0.005 < $amount) {
            throw new LogicException(sprintf(
                'Insufficient store credit: ₹%.2f available, ₹%.2f requested.',
                $available,
                $amount,
            ));
        }

        return DB::transaction(function () use ($invoice, $amount, $userId) {
            return StoreCreditMovement::create([
                'shop_id'             => $invoice->shop_id,
                'customer_id'         => $invoice->customer_id,
                'amount'              => -1 * round($amount, 2),
                'source_type'         => StoreCreditMovement::SOURCE_SALE_APPLIED,
                'source_id'           => $invoice->id,
                'expires_at'          => null,
                'notes'               => "Applied to invoice {$invoice->invoice_number}.",
                'user_id'             => $userId,
                'approved_by_user_id' => null,
            ]);
        });
    }

    /**
     * Owner-only goodwill / correction adjustment. Sign of $amount indicates
     * direction. Negative amounts can be used to clawback credit (subject to
     * the non-negative balance guard).
     */
    public function manualAdjust(
        Customer $customer,
        int $shopId,
        float $amount,
        string $notes,
        int $userId,
        int $approvedByUserId,
    ): StoreCreditMovement {
        if (abs($amount) < 0.01) {
            throw new LogicException('Adjustment amount must be non-zero.');
        }
        if (trim($notes) === '') {
            throw new LogicException('Manual adjustments require a reason in notes.');
        }

        return DB::transaction(function () use ($customer, $shopId, $amount, $notes, $userId, $approvedByUserId) {
            return StoreCreditMovement::create([
                'shop_id'             => $shopId,
                'customer_id'         => $customer->id,
                'amount'              => round($amount, 2),
                'source_type'         => StoreCreditMovement::SOURCE_MANUAL_ADJUSTMENT,
                'source_id'           => null,
                'expires_at'          => null,
                'notes'               => $notes,
                'user_id'             => $userId,
                'approved_by_user_id' => $approvedByUserId,
            ]);
        });
    }

    /**
     * Ledger history for a customer's wallet, most-recent-first. Use for the
     * customer profile page and the wallet details view.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, StoreCreditMovement>
     */
    public function history(int $shopId, int $customerId, int $limit = 50)
    {
        return StoreCreditMovement::query()
            ->withoutTenant()
            ->where('shop_id', $shopId)
            ->where('customer_id', $customerId)
            ->with(['user', 'approvedBy'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
