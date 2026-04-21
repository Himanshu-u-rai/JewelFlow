<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Services\BuybackService;
use App\Services\SalesService;
use App\Models\CashTransaction;

class ExchangeService
{
    public static function exchange(
        $customerId,
        $oldGoldWeight,
        $oldGoldPurity,
        $testLossPercent,
        $itemId,
        $goldRate,
        $making,
        $stone,
        array $rateContext = []
    ) {
        return DB::transaction(function () use (
            $customerId,
            $oldGoldWeight,
            $oldGoldPurity,
            $testLossPercent,
            $itemId,
            $goldRate,
            $making,
            $stone,
            $rateContext
        ) {

            // 1) Buy old gold
            $lot = BuybackService::buyGold($oldGoldWeight, $oldGoldPurity, $testLossPercent, (float) $goldRate);

            // Credit amount
            $fine = $lot->fine_weight_total;
            $credit = $fine * $goldRate;

            // 2) Sell item — pass credit as creditOffset, no discount/roundoff/payments
            //    (the exchange flow pre-dates the new payment system; the credit
            //     is handled as a subtotal reduction, not as a payment mode)
            $invoice = SalesService::sellItem(
                $customerId,
                $itemId,
                $goldRate,
                $making,
                $stone,
                0,          // discount
                0,          // round_off
                [],         // payments (recorded below)
                $credit,    // creditOffset
                $rateContext
            );

            // 3) Amount payable after credit-adjusted invoice
            $payable = $invoice->total;

            CashTransaction::record([
                'shop_id' => auth()->user()->shop_id,
                'user_id' => auth()->id(),
                'type' => 'exchange',
                'amount' => $payable,
                'reference_type' => 'invoice',
                'reference_id' => $invoice->id,
                'invoice_id' => $invoice->id,
                'source_type' => 'exchange',
                'source_id' => $invoice->id,
            ]);

            return [
                'invoice' => $invoice,
                'credit' => $credit,
                'payable' => $payable
            ];
        });
    }
}
