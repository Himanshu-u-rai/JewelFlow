<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\CustomerGoldTransaction;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\MetalMovement;
use Illuminate\Support\Facades\DB;
use LogicException;

class InvoiceAccountingService
{
    public static function createDraft(array $attributes): Invoice
    {
        unset($attributes['invoice_number'], $attributes['invoice_sequence']);

        // Don't assign invoice number yet — defer to finalization
        $invoice = new Invoice();
        $invoice->forceFill(array_merge($attributes, [
            'status' => Invoice::STATUS_DRAFT,
            'subtotal' => 0,
            'gst' => 0,
            'total' => 0,
        ]));
        $invoice->save();

        return $invoice;
    }

    public static function finalizeDraft(Invoice $invoice, ?float $gstRate = null): Invoice
    {
        $invoice->refresh();
        if ($invoice->status !== Invoice::STATUS_DRAFT) {
            throw new LogicException('Only draft invoices can be finalized.');
        }

        SubscriptionGateService::assertShopWritable((int) $invoice->shop_id);
        self::assertFinancialLock($invoice);

        $invoice->load('items');
        $lineTotal = (float) $invoice->items->sum('line_total');
        $wastage = (float) ($invoice->wastage_charge ?? 0);
        $discount = (float) ($invoice->discount ?? 0);
        $roundOff = (float) ($invoice->round_off ?? 0);
        $subtotal = round($lineTotal, 2);
        $rate = $gstRate ?? (float) ($invoice->gst_rate ?? auth()->user()?->shop?->gst_rate ?? config('business.gst_rate_default'));
        $taxable = round($subtotal - $discount, 2);
        $gst = round($taxable * ($rate / 100), 2);
        $total = round($subtotal + $gst + $wastage - $discount + $roundOff, 2);

        $before = $invoice->toArray();

        // Assign invoice number at finalization time (not at draft)
        if (empty($invoice->invoice_number)) {
            $identity = \App\Services\BusinessIdentifierService::nextInvoiceIdentifier((int) $invoice->shop_id);
            $invoice->forceFill([
                'invoice_sequence' => $identity['sequence'],
                'invoice_number'   => $identity['number'],
            ]);
        }

        $invoice->forceFill([
            'subtotal' => $subtotal,
            'gst_rate' => $rate,
            'gst' => $gst,
            'total' => $total,
            'status' => Invoice::STATUS_FINALIZED,
            'finalized_at' => now(),
            'finalized_by' => auth()->id(),
        ]);
        $invoice->save();

        AccountingAuditService::log([
            'shop_id' => $invoice->shop_id,
            'action' => 'invoice_finalized',
            'model_type' => 'invoice',
            'model_id' => $invoice->id,
            'description' => "Draft invoice {$invoice->invoice_number} finalized",
            'before' => $before,
            'after' => $invoice->toArray(),
            'target' => ['type' => 'invoice', 'id' => $invoice->id],
        ]);

        // Auto-earn loyalty points
        if ($invoice->customer_id && $invoice->total > 0) {
            try {
                (new LoyaltyService())->earnPoints($invoice->customer, (float) $invoice->total, $invoice->id);
            } catch (\Throwable $e) {
                // Loyalty is non-critical — log and continue
                \Log::warning('Loyalty earn failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            }
        }

        return $invoice;
    }

    public static function createFinalized(array $attributes): Invoice
    {
        SubscriptionGateService::assertShopWritable((int) $attributes['shop_id']);
        self::assertShopLockForDate((int) $attributes['shop_id'], now()->toDateString());
        unset($attributes['invoice_number'], $attributes['invoice_sequence']);

        $attributes['subtotal'] = round((float) ($attributes['subtotal'] ?? 0), 2);
        $attributes['gst'] = round((float) ($attributes['gst'] ?? 0), 2);
        $attributes['wastage_charge'] = round((float) ($attributes['wastage_charge'] ?? 0), 2);
        $attributes['total'] = round((float) ($attributes['total'] ?? 0), 2);

        $invoice = Invoice::issue(array_merge($attributes, [
            'status' => Invoice::STATUS_FINALIZED,
            'finalized_at' => now(),
            'finalized_by' => auth()->id(),
        ]));

        AccountingAuditService::log([
            'shop_id' => $invoice->shop_id,
            'action' => 'invoice_finalized',
            'model_type' => 'invoice',
            'model_id' => $invoice->id,
            'description' => "Invoice {$invoice->invoice_number} finalized",
            'after' => $invoice->toArray(),
            'target' => ['type' => 'invoice', 'id' => $invoice->id],
        ]);

        return $invoice;
    }

    public static function cancelByReversal(Invoice $invoice, string $reason): Invoice
    {
        $invoice->refresh();

        if ($invoice->status !== Invoice::STATUS_FINALIZED) {
            throw new LogicException('Only finalized invoices can be cancelled.');
        }

        if ($invoice->reversed_by_invoice_id) {
            throw new LogicException('Invoice is already reversed.');
        }

        SubscriptionGateService::assertShopWritable((int) $invoice->shop_id);
        self::assertFinancialLock($invoice);

        return DB::transaction(function () use ($invoice, $reason) {
            $before = $invoice->toArray();

            $reversal = Invoice::issue([
                'shop_id' => $invoice->shop_id,
                'customer_id' => $invoice->customer_id,
                'gold_rate' => $invoice->gold_rate,
                'subtotal' => -1 * (float) $invoice->subtotal,
                'gst' => -1 * (float) $invoice->gst,
                'gst_rate' => $invoice->gst_rate,
                'wastage_charge' => -1 * (float) $invoice->wastage_charge,
                'total' => -1 * (float) $invoice->total,
                'status' => Invoice::STATUS_FINALIZED,
                'finalized_at' => now(),
                'finalized_by' => auth()->id(),
                'reversal_of_invoice_id' => $invoice->id,
            ]);

            // Compensating cash entry.
            CashTransaction::record([
                'shop_id' => $invoice->shop_id,
                'user_id' => auth()->id(),
                'type' => 'out',
                'amount' => abs((float) $invoice->total),
                'source_type' => 'invoice_reversal',
                'source_id' => $reversal->id,
                'invoice_id' => $reversal->id,
                'description' => "Reversal for {$invoice->invoice_number}",
                'reference_type' => 'invoice',
                'reference_id' => $reversal->id,
            ]);

            // Compensating customer-gold entries.
            $goldRows = CustomerGoldTransaction::query()
                ->where('shop_id', $invoice->shop_id)
                ->where('invoice_id', $invoice->id)
                ->get();

            foreach ($goldRows as $row) {
                CustomerGoldTransaction::record([
                    'shop_id' => $row->shop_id,
                    'customer_id' => $row->customer_id,
                    'gross_weight' => $row->gross_weight,
                    'purity' => $row->purity,
                    'fine_gold' => -1 * (float) $row->fine_gold,
                    'type' => 'invoice_reversal',
                    'reference_type' => 'invoice',
                    'reference_id' => $reversal->id,
                    'invoice_id' => $reversal->id,
                ]);
            }

            // Compensating metal entries and inventory rollback.
            $invoice->load('items.item');
            foreach ($invoice->items as $line) {
                if (!$line->item) {
                    continue;
                }

                $item = $line->item;
                $fineWeight = (float) $line->weight * ((float) $item->purity / 24);

                // Only create metal movement if item was sourced from a lot (manufacturer flow)
                if ($item->metal_lot_id) {
                    MetalMovement::record([
                        'shop_id' => $invoice->shop_id,
                        'from_lot_id' => null,
                        'to_lot_id' => $item->metal_lot_id,
                        'fine_weight' => $fineWeight,
                        'type' => 'invoice_reversal',
                        'reference_type' => 'invoice',
                        'reference_id' => $reversal->id,
                        'invoice_id' => $reversal->id,
                        'user_id' => auth()->id(),
                    ]);
                }

                Item::where('id', $item->id)->where('shop_id', $invoice->shop_id)->update(['status' => 'in_stock']);
            }

            // Reverse old-metal-in movements (from old gold/silver payments)
            $oldMetalMovements = MetalMovement::where('invoice_id', $invoice->id)
                ->where('type', 'old_metal_in')
                ->get();

            foreach ($oldMetalMovements as $mm) {
                MetalMovement::record([
                    'shop_id' => $invoice->shop_id,
                    'from_lot_id' => $mm->to_lot_id,
                    'to_lot_id' => null,
                    'fine_weight' => (float) $mm->fine_weight,
                    'type' => 'old_metal_in_reversal',
                    'reference_type' => 'invoice',
                    'reference_id' => $reversal->id,
                    'invoice_id' => $reversal->id,
                    'user_id' => auth()->id(),
                ]);
            }

            // Cancel original invoice as a state transition.
            DB::table('invoices')
                ->where('id', $invoice->id)
                ->update([
                    'status' => Invoice::STATUS_CANCELLED,
                    'cancelled_at' => now(),
                    'cancelled_by' => auth()->id(),
                    'cancellation_reason' => $reason,
                    'reversed_by_invoice_id' => $reversal->id,
                    'updated_at' => now(),
                ]);

            AccountingAuditService::log([
                'shop_id' => $invoice->shop_id,
                'action' => 'invoice_reversal_created',
                'model_type' => 'invoice',
                'model_id' => $invoice->id,
                'description' => "Invoice {$invoice->invoice_number} cancelled via reversal {$reversal->invoice_number}",
                'before' => $before,
                'after' => DB::table('invoices')->where('id', $invoice->id)->first(),
                'data' => [
                    'reversal_invoice_id' => $reversal->id,
                    'reason' => $reason,
                ],
                'target' => ['type' => 'invoice', 'id' => $invoice->id],
            ]);

            // Reverse loyalty points earned on this invoice
            try {
                (new LoyaltyService())->reversePoints($invoice->id, (int) $invoice->shop_id);
            } catch (\Throwable $e) {
                \Log::warning('Loyalty reversal failed for invoice ' . $invoice->id . ': ' . $e->getMessage());
            }

            return $reversal;
        });
    }

    private static function assertFinancialLock(Invoice $invoice): void
    {
        $lockDate = DB::table('shop_rules')
            ->where('shop_id', $invoice->shop_id)
            ->value('financial_lock_date');

        if (!$lockDate) {
            return;
        }

        if ($invoice->created_at->toDateString() <= $lockDate) {
            throw new LogicException("Invoice is before lock date {$lockDate} and cannot be reversed.");
        }
    }

    private static function assertShopLockForDate(int $shopId, string $date): void
    {
        $lockDate = DB::table('shop_rules')
            ->where('shop_id', $shopId)
            ->value('financial_lock_date');

        if ($lockDate && $date <= $lockDate) {
            throw new LogicException("Financial lock is active through {$lockDate}.");
        }
    }
}
