<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    /**
     * Resolve loyalty config from shop preferences (with sensible defaults).
     */
    private static function config(int $shopId): array
    {
        $prefs = Shop::find($shopId)?->preferences;

        return [
            'points_per_hundred' => (int) ($prefs->loyalty_points_per_hundred ?? 1),
            'point_value'        => (float) ($prefs->loyalty_point_value ?? 0.25),
            'expiry_months'      => (int) ($prefs->loyalty_expiry_months ?? 12),
        ];
    }

    /**
     * Earn loyalty points for a finalized purchase.
     */
    public function earnPoints(Customer $customer, float $invoiceTotal, ?int $invoiceId = null): ?LoyaltyTransaction
    {
        $cfg = self::config((int) $customer->shop_id);
        $points = (int) floor($invoiceTotal * $cfg['points_per_hundred'] / 100);

        if ($points <= 0) {
            return null; // no points for tiny invoices
        }

        $expiresAt = $cfg['expiry_months'] > 0
            ? now()->addMonths($cfg['expiry_months'])
            : null;

        return $customer->addLoyaltyPoints(
            $points,
            $invoiceId,
            "Earned on purchase of ₹" . number_format($invoiceTotal, 2),
            $expiresAt,
        );
    }

    /**
     * Reverse earned points when an invoice is cancelled.
     */
    public function reversePoints(int $invoiceId, int $shopId): void
    {
        $earnTxns = LoyaltyTransaction::where('invoice_id', $invoiceId)
            ->where('type', 'earn')
            ->whereRaw('expired IS FALSE')
            ->get();

        foreach ($earnTxns as $txn) {
            $customer = $txn->customer;
            $deduct = min($txn->points, $customer->loyalty_points);
            if ($deduct > 0) {
                $customer->decrement('loyalty_points', $deduct);
                LoyaltyTransaction::create([
                    'shop_id'       => $shopId,
                    'customer_id'   => $customer->id,
                    'invoice_id'    => $invoiceId,
                    'type'          => 'redeem',
                    'points'        => $deduct,
                    'description'   => "Reversed — invoice cancelled",
                    'balance_after' => $customer->loyalty_points,
                ]);
            }
        }
    }

    /**
     * Redeem loyalty points during a sale.
     */
    public function redeemPoints(Customer $customer, int $points, ?int $invoiceId = null): LoyaltyTransaction
    {
        return $customer->redeemLoyaltyPoints($points, $invoiceId, "Redeemed {$points} points");
    }

    /**
     * Get the ₹ value for given points.
     */
    public function pointsToRupees(int $points, int $shopId): float
    {
        $cfg = self::config($shopId);
        return $points * $cfg['point_value'];
    }

    /**
     * Manually adjust points (admin action).
     */
    public function adjustPoints(Customer $customer, int $points, string $type = 'earn', string $description = 'Manual adjustment'): LoyaltyTransaction
    {
        if ($type === 'earn') {
            return $customer->addLoyaltyPoints($points, null, $description);
        }

        return $customer->redeemLoyaltyPoints($points, null, $description);
    }

    /**
     * Expire points past their expiry date for a given shop.
     */
    public static function expirePoints(int $shopId): int
    {
        $expired = LoyaltyTransaction::where('shop_id', $shopId)
            ->where('type', 'earn')
            ->whereRaw('expired IS FALSE')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        $totalExpired = 0;

        foreach ($expired as $txn) {
            $txn->update(['expired' => true]);

            $customer = $txn->customer;
            $deduct = min($txn->points, $customer->loyalty_points);
            if ($deduct > 0) {
                $customer->decrement('loyalty_points', $deduct);
                LoyaltyTransaction::create([
                    'shop_id'       => $shopId,
                    'customer_id'   => $customer->id,
                    'invoice_id'    => $txn->invoice_id,
                    'type'          => 'redeem',
                    'points'        => $deduct,
                    'description'   => "Points expired",
                    'balance_after' => $customer->loyalty_points,
                ]);
                $totalExpired += $deduct;
            }
        }

        return $totalExpired;
    }
}
