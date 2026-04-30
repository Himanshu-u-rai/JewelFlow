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
                $path = $invoiceFile->store("karigar-invoices/{$shopId}", 'public');
                $invoice->invoice_file_path = $path;
            }

            $this->recalculate($invoice);
            $invoice->discrepancy_flags = $this->validate($invoice);
            $invoice->save();

            // Apply any selected advance payments to this invoice
            $advanceIds = array_filter((array) ($data['advance_payment_ids'] ?? []));
            if (! empty($advanceIds)) {
                foreach ($advanceIds as $payId) {
                    $payment = KarigarPayment::query()
                        ->where('shop_id', $shopId)
                        ->where('karigar_id', (int) $data['karigar_id'])
                        ->whereNull('karigar_invoice_id')
                        ->where('id', (int) $payId)
                        ->first();
                    if ($payment) {
                        $payment->karigar_invoice_id = $invoice->id;
                        $payment->save();
                    }
                }
                $totalPaid = (float) $invoice->payments()->sum('amount');
                $invoice->amount_paid = $totalPaid;
                $invoice->payment_status = $totalPaid + self::AMOUNT_TOLERANCE >= (float) $invoice->total_after_tax
                    ? KarigarInvoice::PAYMENT_PAID
                    : ($totalPaid > 0 ? KarigarInvoice::PAYMENT_PARTIAL : KarigarInvoice::PAYMENT_UNPAID);
                $invoice->save();
            }

            return $invoice->fresh(['lines', 'karigar', 'jobOrder']);
        });
    }

    public function update(KarigarInvoice $invoice, array $data, array $lines, ?UploadedFile $invoiceFile): KarigarInvoice
    {
        return DB::transaction(function () use ($invoice, $data, $lines, $invoiceFile) {
            if ($invoice->payment_status !== KarigarInvoice::PAYMENT_UNPAID) {
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
                if ($invoice->invoice_file_path) {
                    Storage::disk('public')->delete($invoice->invoice_file_path);
                }
                $invoice->invoice_file_path = $invoiceFile->store("karigar-invoices/{$invoice->shop_id}", 'public');
            }

            $this->recalculate($invoice);
            $invoice->discrepancy_flags = $this->validate($invoice);
            $invoice->save();

            return $invoice->fresh(['lines']);
        });
    }

    public function recordPayment(KarigarInvoice $invoice, array $data, int $userId): KarigarPayment
    {
        return DB::transaction(function () use ($invoice, $data, $userId) {
            $amount = (float) ($data['amount'] ?? 0);
            if ($amount <= 0) {
                throw new LogicException('Payment amount must be greater than zero.');
            }

            $payment = KarigarPayment::record([
                'shop_id' => $invoice->shop_id,
                'karigar_id' => $invoice->karigar_id,
                'karigar_invoice_id' => $invoice->id,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'amount' => $amount,
                'mode' => $data['mode'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'paid_on' => $data['paid_on'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by_user_id' => $userId,
            ]);

            $totalPaid = (float) $invoice->payments()->sum('amount');
            $invoice->amount_paid = $totalPaid;

            if ($totalPaid + self::AMOUNT_TOLERANCE >= (float) $invoice->total_after_tax) {
                $invoice->payment_status = KarigarInvoice::PAYMENT_PAID;
            } elseif ($totalPaid > 0) {
                $invoice->payment_status = KarigarInvoice::PAYMENT_PARTIAL;
            } else {
                $invoice->payment_status = KarigarInvoice::PAYMENT_UNPAID;
            }
            $invoice->save();

            return $payment;
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
