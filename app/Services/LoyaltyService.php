<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\Shop;
use Carbon\CarbonInterface;
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
     * Reverse earned points when an invoice is cancelled or returned.
     *
     * The $description is the customer-facing loyalty-ledger note. It defaults to
     * the cancellation wording so existing callers are unchanged; the returns flow
     * passes accurate return wording. Only the label differs — the reversal
     * calculation and ledger balances are identical either way.
     */
    public function reversePoints(int $invoiceId, int $shopId, string $description = 'Reversed — invoice cancelled'): void
    {
        $earnTxns = LoyaltyTransaction::where('invoice_id', $invoiceId)
            ->where('type', 'earn')
            ->whereRaw('expired IS FALSE')
            ->get();

        foreach ($earnTxns as $txn) {
            // S3-16: under the customer lock expiry takes, and never taking back
            // what expiry already took from this lot. A savepoint inside the
            // caller's transaction, so a failure here cannot abort it.
            DB::transaction(function () use ($txn, $invoiceId, $shopId, $description) {
                $customer = Customer::whereKey($txn->customer_id)->lockForUpdate()->firstOrFail();
                $expired = (int) LoyaltyTransaction::where('expires_lot_id', $txn->id)->sum('points');
                $deduct = min($txn->points - $expired, $customer->loyalty_points);
                if ($deduct > 0) {
                    $customer->decrement('loyalty_points', $deduct);
                    LoyaltyTransaction::create([
                        'shop_id'       => $shopId,
                        'customer_id'   => $customer->id,
                        'invoice_id'    => $invoiceId,
                        'type'          => 'redeem',
                        'points'        => $deduct,
                        'description'   => $description,
                        'balance_after' => $customer->loyalty_points,
                    ]);
                }
            });
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
     * S3-16. Expire, append-only, what is left of each lot that fell due on or
     * after $activeFrom: one `redeem` row per lot naming it (expires_lot_id,
     * unique). Lots that fell due before $activeFrom — or all overdue lots,
     * when $activeFrom is null — are counted as kept, never expired.
     *
     * Per customer, under the customer's row lock (the lock a reversal takes):
     * replay the ledger to find what each lot still holds, and skip — report,
     * never correct — a customer whose cached balance differs from the
     * ledger's. Needs the shop's tenant context.
     *
     * @return array{due_lots: int, due_points: int, kept_lots: int, kept_points: int, skipped: int[]}
     */
    public function expireDue(int $shopId, ?CarbonInterface $activeFrom, bool $write): array
    {
        $now = now();
        $out = ['due_lots' => 0, 'due_points' => 0, 'kept_lots' => 0, 'kept_points' => 0, 'skipped' => []];
        // ponytail: every customer with an overdue lot is replayed on every run;
        // skip fully expired lots up front if a shop's run gets slow.
        $customerIds = LoyaltyTransaction::where('shop_id', $shopId)->where('type', 'earn')
            ->where('expires_at', '<=', $now)->distinct()->orderBy('customer_id')->pluck('customer_id');

        foreach ($customerIds as $customerId) {
            DB::transaction(function () use ($customerId, $shopId, $activeFrom, $write, $now, &$out) {
                $customer = Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();
                $rows = LoyaltyTransaction::where('customer_id', $customerId)->orderBy('id')->get();
                [$left, $balance] = self::replay($rows);
                if ($balance !== (int) $customer->loyalty_points) {
                    $out['skipped'][] = (int) $customerId;

                    return;
                }

                foreach ($rows as $lot) {
                    $points = $left[$lot->id] ?? 0;
                    if ($lot->type !== 'earn' || $points <= 0 || $lot->expired || $lot->expires_at === null || $lot->expires_at > $now) {
                        continue;
                    }
                    if ($activeFrom === null || $lot->expires_at < $activeFrom) {
                        $out['kept_lots']++;
                        $out['kept_points'] += $points;

                        continue;
                    }
                    $out['due_lots']++;
                    $out['due_points'] += $points;
                    if ($write) {
                        $customer->decrement('loyalty_points', $points);
                        LoyaltyTransaction::create([
                            'shop_id'        => $shopId,
                            'customer_id'    => $customer->id,
                            'invoice_id'     => $lot->invoice_id,
                            'type'           => 'redeem',
                            'points'         => $points,
                            'description'    => 'Points expired',
                            'balance_after'  => $customer->loyalty_points,
                            'expires_lot_id' => $lot->id,
                        ]);
                    }
                }
            });
        }

        return $out;
    }

    /**
     * What each earn row (lot) still holds, and the ledger balance. A debit
     * takes first from the lot it names: an expiry by expires_lot_id; a
     * reversal — or a pre-trigger expiry — by its lot's invoice. The rest,
     * and every other debit, takes the earliest-expiring points first,
     * never-expiring points last. ponytail: that order is the assumed policy
     * (it expires the least); a different one changes only this sort.
     *
     * @param  \Illuminate\Support\Collection<int, LoyaltyTransaction>  $rows  one customer's ledger, by id
     * @return array{0: array<int, int>, 1: int}
     */
    private static function replay($rows): array
    {
        $lots = $rows->where('type', 'earn');
        $left = $lots->mapWithKeys(fn ($l) => [$l->id => (int) $l->points])->all();
        $byExpiry = $lots->sortBy(fn ($l) => [$l->expires_at === null ? 1 : 0, $l->expires_at?->getTimestamp() ?? 0, $l->id])->pluck('id')->all();
        $balance = 0;

        foreach ($rows as $row) {
            if ($row->type === 'earn') {
                $balance += (int) $row->points;

                continue;
            }
            $balance -= (int) $row->points;
            $owed = (int) $row->points;
            $own = $row->expires_lot_id !== null ? [$row->expires_lot_id]
                : ($row->invoice_id === null ? [] : $lots->where('invoice_id', $row->invoice_id)->pluck('id')->all());
            foreach ([...$own, ...$byExpiry] as $id) {
                if ($owed === 0) {
                    break;
                }
                if ($id > $row->id || ($left[$id] ?? 0) === 0) {
                    continue;   // not yet earned when this debit was written, or empty
                }
                $take = min($owed, $left[$id]);
                $left[$id] -= $take;
                $owed -= $take;
            }
        }

        return [$left, $balance];
    }
}
