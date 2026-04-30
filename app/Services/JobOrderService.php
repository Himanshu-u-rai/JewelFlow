<?php

namespace App\Services;

use App\Models\Item;
use App\Models\JobOrder;
use App\Models\JobOrderIssuance;
use App\Models\JobOrderReceipt;
use App\Models\JobOrderReceiptItem;
use App\Models\KarigarPayment;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use Illuminate\Support\Facades\DB;
use LogicException;
use App\Services\BusinessIdentifierService;

class JobOrderService
{
    public const TOLERANCE_FINE_GRAMS = 0.001;

    // Gold purity is in Karats (24 = pure); silver is fineness (999 = pure).
    private function fineWeight(float $net, float $purity, string $metalType): float
    {
        return $metalType === 'silver'
            ? round($net * ($purity / 1000), 6)
            : round($net * ($purity / 24), 6);
    }

    /**
     * Issue bullion to a karigar. Creates a JobOrder, JobOrderIssuance lines,
     * MetalMovement entries (type=job_issue), and decrements MetalLot balances.
     */
    public function issue(array $data, int $shopId, int $userId): JobOrder
    {
        return DB::transaction(function () use ($data, $shopId, $userId) {
            $issuances = $data['issuances'] ?? [];
            if (empty($issuances)) {
                throw new LogicException('At least one issuance line is required.');
            }

            $totalGross = 0.0;
            $totalFine  = 0.0;
            $purity     = (float) ($data['purity'] ?? 0);
            $metalType  = $data['metal_type'] ?? 'gold';

            $lockedLots = [];
            foreach ($issuances as $line) {
                $lotId = (int) ($line['metal_lot_id'] ?? 0);
                if (! $lotId) {
                    throw new LogicException('Each issuance line must reference a metal lot.');
                }
                // FIX #5: always scope lot lookup to the current shop
                $lot = MetalLot::query()
                    ->where('id', $lotId)
                    ->where('shop_id', $shopId)
                    ->lockForUpdate()
                    ->first();
                if (! $lot) {
                    throw new LogicException("Metal lot {$lotId} not found in this shop.");
                }
                $fine = (float) ($line['fine_weight'] ?? 0);
                if ($fine <= 0) {
                    throw new LogicException('Fine weight must be greater than zero.');
                }
                if ((float) $lot->fine_weight_remaining + self::TOLERANCE_FINE_GRAMS < $fine) {
                    throw new LogicException("Lot #{$lot->lot_number} only has " . number_format((float) $lot->fine_weight_remaining, 3) . 'g fine available.');
                }
                $lockedLots[$lotId] = $lot;
                $totalGross += (float) ($line['gross_weight'] ?? 0);
                $totalFine  += $fine;
            }

            $allowedWastage     = (float) ($data['allowed_wastage_percent'] ?? 0);
            $expectedReturnFine = round($totalFine * (1 - $allowedWastage / 100), 6);

            $jobOrderNumber = BusinessIdentifierService::nextJobOrderIdentifier($shopId)['number'];
            $challanNumber  = BusinessIdentifierService::nextChallanIdentifier($shopId)['number'];

            $jobOrder = JobOrder::create([
                'shop_id'                    => $shopId,
                'karigar_id'                 => (int) $data['karigar_id'],
                'job_order_number'           => $jobOrderNumber,
                'challan_number'             => $challanNumber,
                'metal_type'                 => $metalType,
                'purity'                     => $purity,
                'issued_gross_weight'        => $totalGross,
                'issued_fine_weight'         => $totalFine,
                'expected_return_fine_weight'=> $expectedReturnFine,
                'allowed_wastage_percent'    => $allowedWastage,
                'status'                     => JobOrder::STATUS_ISSUED,
                'issue_date'                 => $data['issue_date'] ?? now()->toDateString(),
                'expected_return_date'       => $data['expected_return_date'] ?? null,
                'notes'                      => $data['notes'] ?? null,
                'created_by_user_id'         => $userId,
            ]);

            foreach ($issuances as $line) {
                $lot   = $lockedLots[(int) $line['metal_lot_id']];
                $fine  = (float) ($line['fine_weight'] ?? 0);
                $gross = (float) ($line['gross_weight'] ?? 0);
                // FIX #7: operator precedence — wrap the whole expression in parens
                $linePurity = (float) ($line['purity'] ?? $purity);

                $movement = MetalMovement::record([
                    'shop_id'        => $shopId,
                    'from_lot_id'    => $lot->id,
                    'to_lot_id'      => null,
                    'fine_weight'    => $fine,
                    'type'           => 'job_issue',
                    'reference_type' => 'job_order',
                    'reference_id'   => $jobOrder->id,
                    'user_id'        => $userId,
                ]);

                JobOrderIssuance::create([
                    'shop_id'           => $shopId,
                    'job_order_id'      => $jobOrder->id,
                    'metal_lot_id'      => $lot->id,
                    'metal_movement_id' => $movement->id,
                    'gross_weight'      => $gross,
                    'fine_weight'       => $fine,
                    'purity'            => $linePurity,
                ]);

                $lot->fine_weight_remaining = (float) $lot->fine_weight_remaining - $fine;
                $lot->save();
            }

            // Record any advance payment given at issuance time
            $advanceAmount = (float) ($data['advance_amount'] ?? 0);
            if ($advanceAmount > 0) {
                KarigarPayment::record([
                    'shop_id'              => $shopId,
                    'karigar_id'           => (int) $data['karigar_id'],
                    'karigar_invoice_id'   => null,
                    'job_order_id'         => $jobOrder->id,
                    'payment_method_id'    => $data['advance_payment_method_id'] ?? null,
                    'amount'               => $advanceAmount,
                    'mode'                 => $data['advance_mode'] ?? 'cash',
                    'reference'            => 'Advance for ' . $jobOrder->job_order_number,
                    'paid_on'              => $data['issue_date'] ?? now()->toDateString(),
                    'notes'                => 'Advance given at job order creation',
                    'created_by_user_id'   => $userId,
                ]);

                $jobOrder->advance_amount              = $advanceAmount;
                $jobOrder->advance_mode                = $data['advance_mode'] ?? 'cash';
                $jobOrder->advance_payment_method_id   = $data['advance_payment_method_id'] ?? null;
                $jobOrder->save();
            }

            return $jobOrder->fresh(['issuances', 'karigar']);
        });
    }

    /**
     * Receive finished jewellery from karigar. Supports partial / multi-receipt.
     */
    public function receive(JobOrder $jobOrder, array $receiptData, int $userId): JobOrderReceipt
    {
        // FIX #8: lock the row inside the transaction to prevent TOCTOU race
        return DB::transaction(function () use ($jobOrder, $receiptData, $userId) {
            // Re-fetch with a row lock so concurrent requests can't both pass the status check
            $jobOrder = JobOrder::query()->where('id', $jobOrder->id)->lockForUpdate()->first();

            if (! in_array($jobOrder->status, [
                JobOrder::STATUS_ISSUED,
                JobOrder::STATUS_PARTIAL_RETURN,
            ], true)) {
                throw new LogicException('Cannot receive items for a job order in status ' . $jobOrder->status);
            }

            $rawItems = $receiptData['items'] ?? [];
            if (empty($rawItems)) {
                throw new LogicException('At least one received item is required.');
            }

            $metalType = $jobOrder->metal_type ?? 'gold';

            // FIX #11: compute per-item values once, store in array, reuse below
            $computed     = [];
            $totalPieces  = 0;
            $totalGross   = 0.0;
            $totalStone   = 0.0;
            $totalNet     = 0.0;
            $totalFine    = 0.0;

            foreach ($rawItems as $line) {
                $gross  = (float) ($line['gross_weight'] ?? 0);
                $stone  = (float) ($line['stone_weight'] ?? 0);
                $net    = (float) ($line['net_weight'] ?? max(0.0, $gross - $stone));
                // FIX #1: use karat-aware formula instead of /100
                $linePurity = (float) ($line['purity'] ?? $jobOrder->purity);
                $fine   = $this->fineWeight($net, $linePurity, $metalType);

                $computed[] = [
                    'pieces'       => (int) ($line['pieces'] ?? 1),
                    'gross_weight' => $gross,
                    'stone_weight' => $stone,
                    'net_weight'   => $net,
                    'purity'       => $linePurity,
                    'fine_weight'  => $fine,
                    'description'  => $line['description'] ?? 'Finished item',
                    'hsn_code'     => $line['hsn_code'] ?? '7113',
                ];

                $totalPieces += (int) ($line['pieces'] ?? 1);
                $totalGross  += $gross;
                $totalStone  += $stone;
                $totalNet    += $net;
                $totalFine   += $fine;
            }

            $receiptNumber = BusinessIdentifierService::nextJobReceiptIdentifier($jobOrder->shop_id)['number'];

            $receipt = JobOrderReceipt::create([
                'shop_id'            => $jobOrder->shop_id,
                'job_order_id'       => $jobOrder->id,
                'receipt_number'     => $receiptNumber,
                'receipt_date'       => $receiptData['receipt_date'] ?? now()->toDateString(),
                'total_pieces'       => $totalPieces,
                'total_gross_weight' => $totalGross,
                'total_stone_weight' => $totalStone,
                'total_net_weight'   => $totalNet,
                'total_fine_weight'  => $totalFine,
                'notes'              => $receiptData['notes'] ?? null,
                'created_by_user_id' => $userId,
            ]);

            $sourceLotId = $jobOrder->issuances()->value('metal_lot_id');

            foreach ($computed as $row) {
                $receiptItem = JobOrderReceiptItem::create([
                    'shop_id'              => $jobOrder->shop_id,
                    'job_order_receipt_id' => $receipt->id,
                    'description'          => $row['description'],
                    'hsn_code'             => $row['hsn_code'],
                    'pieces'               => $row['pieces'],
                    'gross_weight'         => $row['gross_weight'],
                    'stone_weight'         => $row['stone_weight'],
                    'net_weight'           => $row['net_weight'],
                    'purity'               => $row['purity'],
                    'fine_weight'          => $row['fine_weight'],
                ]);

                $item = Item::create([
                    'shop_id'         => $jobOrder->shop_id,
                    'metal_type'      => $metalType,
                    'category'        => $row['description'],
                    'gross_weight'    => $row['gross_weight'],
                    'stone_weight'    => $row['stone_weight'],
                    'net_metal_weight'=> $row['net_weight'],
                    'purity'          => $row['purity'],
                    'metal_lot_id'    => $sourceLotId,
                    'job_order_id'    => $jobOrder->id,
                    'source'          => 'job_work',
                    'status'          => 'in_stock',
                ]);

                MetalMovement::record([
                    'shop_id'        => $jobOrder->shop_id,
                    'from_lot_id'    => null,
                    'to_lot_id'      => null,
                    'fine_weight'    => $row['fine_weight'],
                    'type'           => 'manufacture',
                    'reference_type' => 'job_order',
                    'reference_id'   => $jobOrder->id,
                    'user_id'        => $userId,
                ]);

                $receiptItem->item_id = $item->id;
                $receiptItem->save();
            }

            $jobOrder->returned_gross_weight = (float) $jobOrder->returned_gross_weight + $totalGross;
            $jobOrder->returned_fine_weight  = (float) $jobOrder->returned_fine_weight  + $totalFine;
            $this->refreshReconciliation($jobOrder);
            $jobOrder->save();

            return $receipt->fresh(['items']);
        });
    }

    /**
     * Karigar returns leftover bullion (rare). Credits a destination MetalLot.
     */
    public function recordLeftoverReturn(JobOrder $jobOrder, array $data, int $userId): MetalMovement
    {
        return DB::transaction(function () use ($jobOrder, $data, $userId) {
            $jobOrder = JobOrder::query()->where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

            if (! $jobOrder->isOpen()) {
                throw new LogicException('Cannot accept leftover return for a closed job order.');
            }

            $fine = (float) ($data['fine_weight'] ?? 0);
            if ($fine <= 0) {
                throw new LogicException('Leftover fine weight must be greater than zero.');
            }

            $lotId = (int) ($data['metal_lot_id'] ?? 0);
            $lot   = $lotId
                ? MetalLot::query()->where('shop_id', $jobOrder->shop_id)->where('id', $lotId)->lockForUpdate()->first()
                : null;

            if (! $lot) {
                $lot = MetalLot::create([
                    'shop_id'              => $jobOrder->shop_id,
                    'metal_type'           => $jobOrder->metal_type ?? 'gold',
                    'source'               => 'job_return',
                    'purity'               => $jobOrder->purity,
                    'fine_weight_total'    => $fine,
                    'fine_weight_remaining'=> $fine,
                    'cost_per_fine_gram'   => 0,
                ]);
            } else {
                $lot->fine_weight_remaining = (float) $lot->fine_weight_remaining + $fine;
                $lot->fine_weight_total     = (float) $lot->fine_weight_total     + $fine;
                $lot->save();
            }

            $movement = MetalMovement::record([
                'shop_id'        => $jobOrder->shop_id,
                'from_lot_id'    => null,
                'to_lot_id'      => $lot->id,
                'fine_weight'    => $fine,
                'type'           => 'job_return',
                'reference_type' => 'job_order',
                'reference_id'   => $jobOrder->id,
                'user_id'        => $userId,
            ]);

            $jobOrder->leftover_returned_fine_weight = (float) $jobOrder->leftover_returned_fine_weight + $fine;
            $this->refreshReconciliation($jobOrder);
            $jobOrder->save();

            return $movement;
        });
    }

    /**
     * Cancel a job order that has not received anything yet.
     * Reverses the issuance by re-crediting source lots with type=job_return.
     */
    public function cancel(JobOrder $jobOrder, int $userId): JobOrder
    {
        return DB::transaction(function () use ($jobOrder, $userId) {
            // Re-fetch with a row lock so two concurrent cancel requests can't
            // both pass the guard and double-credit the source lots.
            $jobOrder = JobOrder::query()->where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

            if ($jobOrder->status !== JobOrder::STATUS_ISSUED) {
                throw new LogicException('Only freshly-issued job orders can be cancelled.');
            }
            if ($jobOrder->receipts()->exists()) {
                throw new LogicException('Cannot cancel a job order that has receipts.');
            }

            foreach ($jobOrder->issuances as $issuance) {
                if (! $issuance->metal_lot_id) {
                    continue;
                }
                // FIX #5: scope lot query to current shop to prevent cross-shop credit
                $lot = MetalLot::query()
                    ->where('id', $issuance->metal_lot_id)
                    ->where('shop_id', $jobOrder->shop_id)
                    ->lockForUpdate()
                    ->first();
                if ($lot) {
                    $lot->fine_weight_remaining = (float) $lot->fine_weight_remaining + (float) $issuance->fine_weight;
                    $lot->save();
                }

                MetalMovement::record([
                    'shop_id'        => $jobOrder->shop_id,
                    'from_lot_id'    => null,
                    'to_lot_id'      => $issuance->metal_lot_id,
                    'fine_weight'    => (float) $issuance->fine_weight,
                    'type'           => 'job_return',
                    'reference_type' => 'job_order',
                    'reference_id'   => $jobOrder->id,
                    'user_id'        => $userId,
                ]);
            }

            $jobOrder->status       = JobOrder::STATUS_CANCELLED;
            $jobOrder->completed_at = now();
            $jobOrder->save();

            return $jobOrder->fresh();
        });
    }

    /**
     * Mark a job order as completed even when discrepancies exist (acknowledged override).
     */
    public function acknowledgeAndComplete(JobOrder $jobOrder): JobOrder
    {
        return DB::transaction(function () use ($jobOrder) {
            $jobOrder = JobOrder::query()->where('id', $jobOrder->id)->lockForUpdate()->firstOrFail();

            if (! $jobOrder->isOpen()) {
                throw new LogicException('Job order is not open.');
            }

            $jobOrder->discrepancy_acknowledged = true;
            $jobOrder->status       = JobOrder::STATUS_COMPLETED;
            $jobOrder->completed_at = now();
            $jobOrder->save();

            return $jobOrder->fresh();
        });
    }

    /**
     * Recomputes wastage, status, and discrepancy flags from rolling totals.
     * Called after each receipt or leftover return.
     */
    public function refreshReconciliation(JobOrder $jobOrder): void
    {
        $issued         = (float) $jobOrder->issued_fine_weight;
        $returned       = (float) $jobOrder->returned_fine_weight;
        $leftover       = (float) $jobOrder->leftover_returned_fine_weight;
        $allowedWastage = $issued * (float) $jobOrder->allowed_wastage_percent / 100;
        $actualWastage  = $issued - $returned - $leftover;

        $flags = [];

        if ($actualWastage < -self::TOLERANCE_FINE_GRAMS) {
            $flags[] = JobOrder::FLAG_EXCESS_RETURN;
        }
        if ($actualWastage > $allowedWastage + self::TOLERANCE_FINE_GRAMS) {
            $flags[] = JobOrder::FLAG_EXCESS_WASTAGE;
        }
        if ($returned + $leftover + $allowedWastage + self::TOLERANCE_FINE_GRAMS < $issued && $returned > 0) {
            $flags[] = JobOrder::FLAG_SHORT_RETURN;
        }

        $jobOrder->actual_wastage_fine = max(0.0, $actualWastage);
        $jobOrder->discrepancy_flags   = $flags ?: null;

        $expectedReturn  = (float) $jobOrder->expected_return_fine_weight;
        $isFulfilled     = ($returned + $leftover + self::TOLERANCE_FINE_GRAMS) >= $expectedReturn;
        $hasCriticalFlags = in_array(JobOrder::FLAG_EXCESS_WASTAGE, $flags, true)
            || in_array(JobOrder::FLAG_SHORT_RETURN, $flags, true);

        if ($returned <= 0 && $leftover <= 0) {
            $jobOrder->status = JobOrder::STATUS_ISSUED;
        } elseif ($isFulfilled && ! $hasCriticalFlags) {
            $jobOrder->status = JobOrder::STATUS_COMPLETED;
            if (! $jobOrder->completed_at) {
                $jobOrder->completed_at = now();
            }
        } else {
            $jobOrder->status = JobOrder::STATUS_PARTIAL_RETURN;
        }
    }
}
