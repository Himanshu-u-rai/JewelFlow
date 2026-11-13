<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Repair;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * The one way a repair is delivered and billed, for the web and the app (audit H1, 2026-11-13).
 *
 * The repair is re-read under a row lock inside the billing transaction, so a second click or a delivery from the
 * other client that landed after the request loaded the repair is refused instead of billed again.
 */
class RepairDeliveryService
{
    public const SOURCE_WEB = 'web';
    public const SOURCE_APP = 'mobile_app';

    /**
     * @return array{repair: Repair, invoice: Invoice, gst_rate: float, gst: float, total: float}
     *
     * @throws \App\Exceptions\RepairAlreadyDelivered
     */
    public function deliver(Repair $repair, float $amount, bool $includeGst, ?float $gstRate, string $paymentMode, int $userId, string $source): array
    {
        $shopId = (int) $repair->shop_id;
        $gstRate = $includeGst
            ? (float) ($gstRate ?? (Shop::find($shopId)?->gst_rate ?? config('business.gst_rate_default')))
            : 0.0;
        $breakdown = app(PricingEngine::class)->computeRepair($shopId, $amount, $includeGst, $gstRate);

        return DB::transaction(function () use ($repair, $shopId, $breakdown, $paymentMode, $userId, $source) {
            $locked = Repair::lockOpen((int) $repair->id);

            $invoice = InvoiceAccountingService::createFinalized([
                'shop_id' => $shopId,
                'customer_id' => $locked->customer_id,
                'gold_rate' => 0,
                'subtotal' => $breakdown->subtotal,
                'gst' => $breakdown->gst,
                'gst_rate' => $breakdown->gstRate,
                'wastage_charge' => 0,
                'total' => $breakdown->finalTotal,
                'round_off' => $breakdown->roundingAdjustment,
            ]);

            InvoicePayment::record([
                'invoice_id' => $invoice->id,
                'shop_id' => $shopId,
                'mode' => $paymentMode,
                'amount' => $breakdown->finalTotal,
            ]);

            if ($paymentMode === InvoicePayment::MODE_CASH) {
                CashTransaction::record([
                    'shop_id' => $shopId,
                    'user_id' => $userId,
                    'type' => 'in',
                    'amount' => $breakdown->finalTotal,
                    'source_type' => 'invoice',
                    'source_id' => $invoice->id,
                    'invoice_id' => $invoice->id,
                    'payment_mode' => 'cash',
                    'description' => 'Repair delivery - ' . $locked->item_description,
                    'reference_type' => 'invoice',
                    'reference_id' => $invoice->id,
                ]);
            }

            $locked->final_cost = $breakdown->finalTotal;
            $locked->invoice_id = $invoice->id;
            $locked->status = Repair::STATUS_DELIVERED;
            $locked->save();

            AuditLog::create([
                'shop_id' => $shopId,
                'user_id' => $userId,
                'action' => 'repair_deliver',
                'model_type' => 'repair',
                'model_id' => (int) $locked->id,
                'description' => $source === self::SOURCE_APP ? 'Repair delivered and billed from mobile app.' : 'Repair delivered and billed.',
                'data' => [
                    'source' => $source,
                    'repair_number' => $locked->repair_number,
                    'subtotal' => $breakdown->subtotal,
                    'gst_rate' => $breakdown->gstRate,
                    'gst' => $breakdown->gst,
                    'round_off' => $breakdown->roundingAdjustment,
                    'amount' => $breakdown->finalTotal,
                    'payment_mode' => $paymentMode,
                    'invoice_id' => $invoice->id,
                ],
            ]);

            return ['repair' => $locked, 'invoice' => $invoice, 'gst_rate' => $breakdown->gstRate, 'gst' => $breakdown->gst, 'total' => $breakdown->finalTotal];
        });
    }
}
