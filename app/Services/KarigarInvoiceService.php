<?php

namespace App\Services;

use App\Models\JobOrder;
use App\Models\KarigarInvoice;
use App\Models\KarigarInvoiceLine;
use App\Models\KarigarPayment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;

class KarigarInvoiceService
{
    public const WEIGHT_TOLERANCE = 0.05;
    public const AMOUNT_TOLERANCE = 0.05;

    public function create(array $data, array $lines, ?UploadedFile $invoiceFile, int $shopId, int $userId): KarigarInvoice
    {
        return DB::transaction(function () use ($data, $lines, $invoiceFile, $shopId, $userId) {
            $invoice = KarigarInvoice::create([
                'shop_id' => $shopId,
                'karigar_id' => (int) $data['karigar_id'],
                'job_order_id' => $data['job_order_id'] ?? null,
                'mode' => $data['mode'] ?? KarigarInvoice::MODE_PURCHASE,
                'karigar_invoice_number' => $data['karigar_invoice_number'],
                'karigar_invoice_date' => $data['karigar_invoice_date'],
                'state_code' => $data['state_code'] ?? null,
                'is_interstate' => (bool) ($data['is_interstate'] ?? false),
                'cgst_rate' => (float) ($data['cgst_rate'] ?? 0),
                'sgst_rate' => (float) ($data['sgst_rate'] ?? 0),
                'igst_rate' => (float) ($data['igst_rate'] ?? 0),
                'amount_in_words' => $data['amount_in_words'] ?? null,
                'tax_amount_in_words' => $data['tax_amount_in_words'] ?? null,
                'jurisdiction' => $data['jurisdiction'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'payment_status' => KarigarInvoice::PAYMENT_UNPAID,
                'amount_paid' => 0,
                'created_by_user_id' => $userId,
            ]);

            foreach ($lines as $line) {
                $this->createLine($invoice, $line);
            }

            if ($invoiceFile) {
                // Supplier invoices are confidential (GST numbers, amounts,
                // counterparty identity) — private disk only, never 'public'.
                // Served by the authenticated karigar-invoices.file route.
                $path = $invoiceFile->store("karigar-invoices/{$shopId}", KarigarInvoice::ATTACHMENT_DISK);
                $invoice->invoice_file_path = $path;
                $invoice->invoice_file_disk = KarigarInvoice::ATTACHMENT_DISK;
            }

            $this->recalculate($invoice);
            $invoice->discrepancy_flags = $this->validate($invoice);
            $invoice->save();

            // Apply any selected advances to this invoice (owner 2026-11-13: compensating entries).
            $advanceIds = array_filter((array) ($data['advance_payment_ids'] ?? []));
            if (! empty($advanceIds)) {
                $locked = $this->lockInvoice($invoice);
                foreach ($advanceIds as $payId) {
                    $this->applyAdvance($locked, (int) $payId, $userId);
                }
                $this->refreshPaymentState($locked);
            }

            return $invoice->fresh(['lines', 'karigar', 'jobOrder']);
        });
    }

    public function update(KarigarInvoice $invoice, array $data, array $lines, ?UploadedFile $invoiceFile): KarigarInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $lines, $invoiceFile) {
            // Decided on the locked invoice and its payments, not on the status the page loaded (audit N15).
            $invoice = $this->lockInvoice($invoice);
            if ($invoice->payments()->exists()) {
                throw new LogicException('Cannot edit an invoice that has recorded payments.');
            }

            $invoice->fill([
                'mode' => $data['mode'] ?? $invoice->mode,
                'karigar_invoice_number' => $data['karigar_invoice_number'] ?? $invoice->karigar_invoice_number,
                'karigar_invoice_date' => $data['karigar_invoice_date'] ?? $invoice->karigar_invoice_date,
                'state_code' => $data['state_code'] ?? $invoice->state_code,
                'is_interstate' => (bool) ($data['is_interstate'] ?? $invoice->is_interstate),
                'cgst_rate' => (float) ($data['cgst_rate'] ?? $invoice->cgst_rate),
                'sgst_rate' => (float) ($data['sgst_rate'] ?? $invoice->sgst_rate),
                'igst_rate' => (float) ($data['igst_rate'] ?? $invoice->igst_rate),
                'amount_in_words' => $data['amount_in_words'] ?? $invoice->amount_in_words,
                'tax_amount_in_words' => $data['tax_amount_in_words'] ?? $invoice->tax_amount_in_words,
                'jurisdiction' => $data['jurisdiction'] ?? $invoice->jurisdiction,
                'payment_terms' => $data['payment_terms'] ?? $invoice->payment_terms,
            ]);

            $invoice->lines()->delete();
            foreach ($lines as $line) {
                $this->createLine($invoice, $line);
            }

            if ($invoiceFile) {
                // Delete the outgoing file from whichever disk it is RECORDED on.
                // Hard-coding 'public' here would silently orphan every attachment
                // already relocated to the private disk.
                if ($invoice->invoice_file_path && $invoice->invoice_file_disk) {
                    Storage::disk($invoice->invoice_file_disk)->delete($invoice->invoice_file_path);
                }
                $invoice->invoice_file_path = $invoiceFile->store("karigar-invoices/{$invoice->shop_id}", KarigarInvoice::ATTACHMENT_DISK);
                $invoice->invoice_file_disk = KarigarInvoice::ATTACHMENT_DISK;
            }

            $this->recalculate($invoice);
            $invoice->discrepancy_flags = $this->validate($invoice);
            $invoice->save();

            return $invoice->fresh(['lines']);
        });
    }

    public function recordPayment(KarigarInvoice $invoice, array $data, int $userId): KarigarPayment
    {
        return $this->recordPayments($invoice, [$data], $userId)[0];
    }

    /**
     * Record one payment, possibly split across modes, against the invoice re-read under its row lock (audit N15):
     * all splits or none, never more than is due, and amount_paid / payment_status recomputed from the payments
     * actually on the invoice.
     *
     * @return list<KarigarPayment>
     */
    public function recordPayments(KarigarInvoice $invoice, array $splits, int $userId): array
    {
        return DB::transaction(function () use ($invoice, $splits, $userId) {
            $locked = $this->lockInvoice($invoice);

            $total = 0.0;
            foreach ($splits as $data) {
                $amount = round((float) ($data['amount'] ?? 0), 2);
                if ($amount <= 0) {
                    throw new LogicException('Payment amount must be greater than zero.');
                }
                $total += $amount;
            }
            $due = round((float) $locked->total_after_tax - (float) $locked->payments()->sum('amount'), 2);
            if ($total > $due + self::AMOUNT_TOLERANCE) {
                throw new LogicException('Payment of ₹' . number_format($total, 2) . ' is more than the ₹' . number_format(max(0, $due), 2) . ' due on this invoice.');
            }

            $payments = [];
            foreach ($splits as $data) {
                $payments[] = KarigarPayment::record([
                    'shop_id' => $locked->shop_id,
                    'karigar_id' => $locked->karigar_id,
                    'karigar_invoice_id' => $locked->id,
                    'payment_method_id' => $data['payment_method_id'] ?? null,
                    'amount' => round((float) $data['amount'], 2),
                    'mode' => $data['mode'] ?? 'cash',
                    'reference' => $data['reference'] ?? null,
                    'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                    'notes' => $data['notes'] ?? null,
                    'created_by_user_id' => $userId,
                ]);
            }
            $this->refreshPaymentState($locked);

            return $payments;
        });
    }

    // ── Advances: used by compensating entries (owner 2026-11-13) ───────────────────────────────────────────
    // karigar_payments is append-only (CONSTITUTION trigger #11): an advance row is never edited. Using it appends
    // ADVANCE-USED:{id} (minus, no invoice) and FROM-ADVANCE:{id} (plus, on the invoice); reversing a payment made
    // from an advance appends ADVANCE-RESTORED:{id} (plus, no invoice). Each pair cancels out: no cash moves.

    public const ADVANCE_USED = 'ADVANCE-USED:';
    public const FROM_ADVANCE = 'FROM-ADVANCE:';
    public const ADVANCE_RESTORED = 'ADVANCE-RESTORED:';

    /** An original advance: paid to the karigar with no invoice, and not one of the bookkeeping rows above. */
    private function originalAdvances(int $shopId): \Illuminate\Database\Eloquent\Builder
    {
        return KarigarPayment::query()
            ->where('shop_id', $shopId)
            ->whereNull('karigar_invoice_id')
            ->where('amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('reference')
                ->orWhere(fn ($q) => $q->where('reference', 'not like', self::ADVANCE_USED . '%')
                    ->where('reference', 'not like', self::ADVANCE_RESTORED . '%')));
    }

    /** What is left of an advance: its amount, less what was used, plus what was given back by reversals. */
    private function advanceLeft(KarigarPayment $advance): float
    {
        $moved = (float) KarigarPayment::query()
            ->where('shop_id', $advance->shop_id)
            ->whereNull('karigar_invoice_id')
            ->whereIn('reference', [self::ADVANCE_USED . $advance->id, self::ADVANCE_RESTORED . $advance->id])
            ->sum('amount');

        return round(max(0, (float) $advance->amount + $moved), 2);
    }

    /**
     * Advances with money left, for the invoice form.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, karigar_id: int, amount: float, available: float, paid_on: string, mode: string, reference: ?string}>
     */
    public function availableAdvances(int $shopId, ?int $karigarId = null): \Illuminate\Support\Collection
    {
        return $this->originalAdvances($shopId)
            ->when($karigarId, fn ($q) => $q->where('karigar_id', $karigarId))
            ->orderBy('paid_on')->orderBy('id')
            ->get()
            ->map(fn (KarigarPayment $p) => [
                'id' => (int) $p->id, 'karigar_id' => (int) $p->karigar_id, 'amount' => (float) $p->amount,
                'available' => $this->advanceLeft($p), 'paid_on' => $p->paid_on->format('d M Y'),
                'mode' => $p->mode, 'reference' => $p->reference,
            ])
            ->filter(fn ($a) => $a['available'] > self::AMOUNT_TOLERANCE)
            ->values();
    }

    /** Use what is left of one advance (up to what the locked invoice still owes), once, under the advance's lock. */
    private function applyAdvance(KarigarInvoice $locked, int $advanceId, int $userId): void
    {
        $advance = $this->originalAdvances((int) $locked->shop_id)
            ->where('karigar_id', $locked->karigar_id)
            ->whereKey($advanceId)
            ->lockForUpdate()
            ->first();
        if (! $advance) {
            return;
        }
        $due = round((float) $locked->total_after_tax - (float) $locked->payments()->sum('amount'), 2);
        $use = round(min($this->advanceLeft($advance), $due), 2);
        if ($use <= self::AMOUNT_TOLERANCE) {
            return;
        }

        $common = [
            'shop_id' => $locked->shop_id, 'karigar_id' => $locked->karigar_id,
            'payment_method_id' => $advance->payment_method_id, 'mode' => $advance->mode,
            'paid_on' => now()->toDateString(), 'created_by_user_id' => $userId,
        ];
        KarigarPayment::record($common + [
            'karigar_invoice_id' => null, 'amount' => -$use, 'reference' => self::ADVANCE_USED . $advance->id,
            'notes' => "Advance #{$advance->id} used on invoice {$locked->karigar_invoice_number}",
        ]);
        KarigarPayment::record($common + [
            'karigar_invoice_id' => $locked->id, 'amount' => $use, 'reference' => self::FROM_ADVANCE . $advance->id,
            'notes' => "Paid from advance #{$advance->id}",
        ]);
    }

    /** The invoice re-read under its row lock: every money decision on it is made on this copy. */
    private function lockInvoice(KarigarInvoice $invoice): KarigarInvoice
    {
        return KarigarInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
    }

    /** amount_paid and payment_status always follow the payments on the (locked) invoice. */
    private function refreshPaymentState(KarigarInvoice $locked): void
    {
        $totalPaid = round((float) $locked->payments()->sum('amount'), 2);
        $locked->amount_paid = $totalPaid;
        $locked->payment_status = $totalPaid + self::AMOUNT_TOLERANCE >= (float) $locked->total_after_tax
            ? KarigarInvoice::PAYMENT_PAID
            : ($totalPaid > 0 ? KarigarInvoice::PAYMENT_PARTIAL : KarigarInvoice::PAYMENT_UNPAID);
        $locked->save();
    }

    /** Delete an invoice that has no payments, decided under its row lock. */
    public function delete(KarigarInvoice $invoice): void
    {
        DB::transaction(function () use ($invoice) {
            $locked = $this->lockInvoice($invoice);
            if ($locked->payments()->exists()) {
                throw new LogicException('Cannot delete an invoice with recorded payments.');
            }
            $file = [$locked->invoice_file_disk, $locked->invoice_file_path];
            $locked->lines()->delete();
            $locked->delete();
            if ($file[0] && $file[1]) {
                DB::afterCommit(fn () => Storage::disk($file[0])->delete($file[1]));
            }
        });
    }

    /**
     * Reverse a previously-recorded karigar payment via a COMPENSATING entry.
     *
     * KarigarPayment is an immutable ledger (BelongsToShop + ImmutableLedger), so
     * we never edit or delete the original — we append a negative offset that
     * nets it out, mirroring the CONSTITUTION §3 compensating-entry doctrine.
     * Reversing all of an invoice's payments returns it to 'unpaid', which
     * re-enables correcting/editing the invoice (blocked while paid). The
     * reversal is tagged 'REVERSAL:{id}' so it cannot be double-reversed and so a
     * reversal can never itself be reversed.
     */
    public function reversePayment(KarigarInvoice $invoice, KarigarPayment $payment, ?string $reason, int $userId): KarigarPayment
    {
        return DB::transaction(function () use ($invoice, $payment, $reason, $userId) {
            $locked = KarigarInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ((int) $payment->karigar_invoice_id !== (int) $locked->id
                || (int) $payment->shop_id !== (int) $locked->shop_id) {
                throw new LogicException('Payment does not belong to this invoice.');
            }
            if ((float) $payment->amount <= 0) {
                throw new LogicException('Only an actual payment can be reversed, not a reversal entry.');
            }

            $marker = 'REVERSAL:' . $payment->id;
            if (KarigarPayment::query()
                ->where('karigar_invoice_id', $locked->id)
                ->where('reference', $marker)
                ->exists()) {
                throw new LogicException('This payment has already been reversed.');
            }

            $reversal = KarigarPayment::record([
                'shop_id'            => $locked->shop_id,
                'karigar_id'         => $locked->karigar_id,
                'karigar_invoice_id' => $locked->id,
                'payment_method_id'  => $payment->payment_method_id,
                'amount'             => -1 * (float) $payment->amount,
                'mode'               => $payment->mode,
                'reference'          => $marker,
                'paid_on'            => now()->toDateString(),
                'notes'              => 'Reversal of payment #' . $payment->id . ($reason ? ': ' . $reason : ''),
                'created_by_user_id' => $userId,
            ]);

            // A payment made from an advance goes back to that advance (it was never cash on this day).
            if (str_starts_with((string) $payment->reference, self::FROM_ADVANCE)) {
                KarigarPayment::record([
                    'shop_id'            => $locked->shop_id,
                    'karigar_id'         => $locked->karigar_id,
                    'karigar_invoice_id' => null,
                    'payment_method_id'  => $payment->payment_method_id,
                    'amount'             => (float) $payment->amount,
                    'mode'               => $payment->mode,
                    'reference'          => self::ADVANCE_RESTORED . substr((string) $payment->reference, strlen(self::FROM_ADVANCE)),
                    'paid_on'            => now()->toDateString(),
                    'notes'              => 'Advance given back by reversal of payment #' . $payment->id,
                    'created_by_user_id' => $userId,
                ]);
            }

            $totalPaid = (float) $locked->payments()->sum('amount');
            $locked->amount_paid = $totalPaid;
            if ($totalPaid + self::AMOUNT_TOLERANCE >= (float) $locked->total_after_tax) {
                $locked->payment_status = KarigarInvoice::PAYMENT_PAID;
            } elseif ($totalPaid > 0) {
                $locked->payment_status = KarigarInvoice::PAYMENT_PARTIAL;
            } else {
                $locked->payment_status = KarigarInvoice::PAYMENT_UNPAID;
            }
            $locked->save();

            \App\Models\AuditLog::create([
                'shop_id'     => $locked->shop_id,
                'user_id'     => $userId,
                'action'      => 'karigar_payment_reversed',
                'model_type'  => 'karigar_payment',
                'model_id'    => $payment->id,
                'description' => 'Reversed karigar payment #' . $payment->id . ' of '
                    . number_format((float) $payment->amount, 2) . ' on invoice ' . $locked->karigar_invoice_number . '.'
                    . ($reason ? ' Reason: ' . $reason : ''),
                'data'        => [
                    'karigar_invoice_id'   => $locked->id,
                    'reversed_payment_id'  => $payment->id,
                    'reversal_payment_id'  => $reversal->id,
                    'amount'               => (float) $payment->amount,
                    'new_amount_paid'      => $totalPaid,
                    'new_payment_status'   => $locked->payment_status,
                ],
            ]);

            return $reversal;
        });
    }

    public function recalculate(KarigarInvoice $invoice): void
    {
        $lines = $invoice->lines()->get();

        $totalPieces = (int) $lines->sum('pieces');
        $totalGross = (float) $lines->sum('gross_weight');
        $totalStone = (float) $lines->sum('stone_weight');
        $totalNet = (float) $lines->sum('net_weight');
        $totalMetal = (float) $lines->sum('metal_amount');
        $totalMaking = (float) $lines->sum('making_charge');
        $totalWastage = (float) $lines->sum('wastage_charge');
        $totalExtra = (float) $lines->sum('extra_amount');
        $totalBeforeTax = (float) $lines->sum('line_total');

        $cgst = round($totalBeforeTax * (float) $invoice->cgst_rate / 100, 2);
        $sgst = round($totalBeforeTax * (float) $invoice->sgst_rate / 100, 2);
        $igst = round($totalBeforeTax * (float) $invoice->igst_rate / 100, 2);
        $totalTax = round($cgst + $sgst + $igst, 2);
        $totalAfterTax = round($totalBeforeTax + $totalTax, 2);

        $invoice->total_pieces = $totalPieces;
        $invoice->total_gross_weight = $totalGross;
        $invoice->total_stone_weight = $totalStone;
        $invoice->total_net_weight = $totalNet;
        $invoice->total_metal_amount = round($totalMetal, 2);
        $invoice->total_making_amount = round($totalMaking, 2);
        $invoice->total_wastage_amount = round($totalWastage, 2);
        $invoice->total_extra_amount = round($totalExtra, 2);
        $invoice->total_before_tax = round($totalBeforeTax, 2);
        $invoice->cgst_amount = $cgst;
        $invoice->sgst_amount = $sgst;
        $invoice->igst_amount = $igst;
        $invoice->total_tax = $totalTax;
        $invoice->total_after_tax = $totalAfterTax;
    }

    /**
     * @return array<int, string>  list of discrepancy flag codes
     */
    public function validate(KarigarInvoice $invoice): array
    {
        $flags = [];

        if (($invoice->is_interstate && (float) $invoice->igst_rate <= 0)
            || (! $invoice->is_interstate && (float) $invoice->igst_rate > 0)) {
            $flags[] = 'GST_TYPE_MISMATCH';
        }

        if ($invoice->isJobWorkMode() && (float) $invoice->total_metal_amount > 0) {
            $flags[] = 'JOBWORK_MODE_HAS_METAL';
        }

        if ($invoice->mode === KarigarInvoice::MODE_PURCHASE && (float) $invoice->total_metal_amount <= 0) {
            $flags[] = 'PURCHASE_MODE_NO_METAL';
        }

        if ($invoice->job_order_id) {
            $jo = JobOrder::query()->where('shop_id', $invoice->shop_id)->find($invoice->job_order_id);
            if ($jo) {
                if ($jo->karigar_id !== $invoice->karigar_id) {
                    $flags[] = 'KARIGAR_MISMATCH';
                }
                $invoiceNet     = (float) $invoice->lines()->sum('net_weight');
                $jobReceiptsNet = (float) $jo->receipts()->sum('total_net_weight');
                if ($jobReceiptsNet > 0 && abs($invoiceNet - $jobReceiptsNet) > self::WEIGHT_TOLERANCE) {
                    $flags[] = 'WEIGHT_MISMATCH_VS_RECEIPTS';
                }
            }
        }

        return $flags;
    }

    private function createLine(KarigarInvoice $invoice, array $line): KarigarInvoiceLine
    {
        $pieces = (int) ($line['pieces'] ?? 1);
        $gross = (float) ($line['gross_weight'] ?? 0);
        $stone = (float) ($line['stone_weight'] ?? 0);
        $net = (float) ($line['net_weight'] ?? max(0, $gross - $stone));
        $purity = (float) ($line['purity'] ?? 0);
        $rate = (float) ($line['rate_per_gram'] ?? 0);
        $making = (float) ($line['making_charge'] ?? 0);
        $wastage = (float) ($line['wastage_charge'] ?? 0);
        $extra = (float) ($line['extra_amount'] ?? 0);

        $metalAmount = (float) ($line['metal_amount'] ?? round($net * $rate, 2));
        $lineTotal = round($metalAmount + $making + $wastage + $extra, 2);

        return KarigarInvoiceLine::create([
            'shop_id' => $invoice->shop_id,
            'karigar_invoice_id' => $invoice->id,
            'linked_receipt_item_id' => $line['linked_receipt_item_id'] ?? null,
            'description' => $line['description'] ?? 'Item',
            'hsn_code' => $line['hsn_code'] ?? '7113',
            'pieces' => $pieces,
            'gross_weight' => $gross,
            'stone_weight' => $stone,
            'net_weight' => $net,
            'purity' => $purity,
            'rate_per_gram' => $rate,
            'metal_amount' => $metalAmount,
            'making_charge' => $making,
            'wastage_charge' => $wastage,
            'extra_amount' => $extra,
            'line_total' => $lineTotal,
            'note' => $line['note'] ?? null,
        ]);
    }
}
