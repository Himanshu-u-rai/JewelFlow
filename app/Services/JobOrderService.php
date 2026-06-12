<?php

namespace App\Services;

use App\Models\CustomerGoldTransaction;
use App\Models\Item;
use App\Models\JobOrder;
use App\Models\JobOrderIssuance;
use App\Models\JobOrderSource;
use App\Models\JobOrderReceipt;
use App\Models\JobOrderReceiptItem;
use App\Models\KarigarPayment;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;
use App\Services\BusinessIdentifierService;

class JobOrderService
{
    public const TOLERANCE_FINE_GRAMS = 0.001;

    // Gold purity is in Karats (24 = pure); silver is fineness (999 = pure).
    private function fineWeight(float $net, float $purity, string $metalType): float
    {
        // Delegate to the single fine-weight authority. Gold → /24, silver →
        // /1000; null (non-accounting metal) is impossible in karigar flows.
        $mult = \App\Services\MetalRegistry::fineWeightMultiplier($metalType, $purity);
        if ($mult === null) {
            throw new \LogicException("Cannot derive fine weight for non-accounting metal '{$metalType}'.");
        }

        return round($net * $mult, 6);
    }

    /**
     * Issue bullion to a karigar. Creates a JobOrder, JobOrderIssuance lines,
     * MetalMovement entries (type=job_issue), and decrements MetalLot balances.
     */
    public function issue(array $data, int $shopId, int $userId): JobOrder
    {
        return DB::transaction(function () use ($data, $shopId, $userId) {
            $purity    = (float) ($data['purity'] ?? 0);
            $metalType = $data['metal_type'] ?? 'gold';
            $karigarId = (int) $data['karigar_id'];

            // Normalise the metal-source SET (empty = labor-only / 'none'), then
            // lock + validate every leg BEFORE any write (sufficiency, ownership).
            $legs     = $this->resolveSourceLegs($data);
            $prepared = array_map(
                fn ($leg) => $this->prepareSourceLeg($leg, $metalType, $purity, $shopId, $karigarId),
                $legs
            );

            $totalGross = (float) array_sum(array_map(fn ($p) => $p['gross'], $prepared));
            $totalFine  = (float) array_sum(array_map(fn ($p) => $p['fine'], $prepared));

            $allowedWastage     = (float) ($data['allowed_wastage_percent'] ?? 0);
            $expectedReturnFine = round($totalFine * (1 - $allowedWastage / 100), 6);

            $jobOrderNumber = BusinessIdentifierService::nextJobOrderIdentifier($shopId)['number'];
            $challanNumber  = BusinessIdentifierService::nextChallanIdentifier($shopId)['number'];

            $jobOrder = JobOrder::create([
                'shop_id'                     => $shopId,
                'karigar_id'                  => $karigarId,
                'job_order_number'            => $jobOrderNumber,
                'challan_number'              => $challanNumber,
                'job_type'                    => $data['job_type'] ?? JobOrder::JOB_TYPE_MANUFACTURE,
                'source_item_id'              => $data['source_item_id'] ?? null,
                'metal_type'                  => $metalType,
                'purity'                      => $purity,
                'issued_gross_weight'         => $totalGross,
                'issued_fine_weight'          => $totalFine,
                'expected_return_fine_weight' => $expectedReturnFine,
                'allowed_wastage_percent'     => $allowedWastage,
                'status'                      => JobOrder::STATUS_ISSUED,
                'issue_date'                  => $data['issue_date'] ?? now()->toDateString(),
                'expected_return_date'        => $data['expected_return_date'] ?? null,
                'notes'                       => $data['notes'] ?? null,
                'created_by_user_id'          => $userId,
            ]);

            foreach ($prepared as $p) {
                $this->commitSourceLeg($p, $jobOrder, $shopId, $userId);
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

            return $jobOrder->fresh(['issuances', 'sources', 'karigar']);
        });
    }

    /**
     * Normalise issuance input into a uniform set of metal-source legs.
     *
     * New model: an explicit 'sources' key (an array; EMPTY = labor-only/'none').
     * Legacy: a non-empty 'issuances' array → vault legs (≥1 still required, so
     * existing callers behave exactly as before).
     *
     * @return array<int, array<string, mixed>>
     */
    private function resolveSourceLegs(array $data): array
    {
        if (array_key_exists('sources', $data)) {
            return array_map(fn ($s) => [
                'source_type'  => (string) ($s['source_type'] ?? ''),
                'metal_lot_id' => isset($s['metal_lot_id']) ? (int) $s['metal_lot_id'] : null,
                'customer_id'  => isset($s['customer_id']) ? (int) $s['customer_id'] : null,
                'fine_weight'  => (float) ($s['fine_weight'] ?? 0),
                'gross_weight' => (float) ($s['gross_weight'] ?? 0),
                'purity'       => isset($s['purity']) ? (float) $s['purity'] : null,
            ], array_values($data['sources']));
        }

        $issuances = $data['issuances'] ?? [];
        if (empty($issuances)) {
            throw new LogicException('At least one issuance line is required.');
        }

        return array_map(fn ($l) => [
            'source_type'  => JobOrderSource::TYPE_VAULT,
            'metal_lot_id' => (int) ($l['metal_lot_id'] ?? 0),
            'customer_id'  => null,
            'fine_weight'  => (float) ($l['fine_weight'] ?? 0),
            'gross_weight' => (float) ($l['gross_weight'] ?? 0),
            'purity'       => isset($l['purity']) ? (float) $l['purity'] : null,
        ], array_values($issuances));
    }

    /**
     * Validate + lock one source leg (no writes). Returns the resolved leg
     * (with its lot / customer) ready to commit.
     *
     * @return array{type:string, fine:float, gross:float, purity:float, lot:?MetalLot, customer_id:?int}
     */
    private function prepareSourceLeg(array $leg, string $metalType, float $jobPurity, int $shopId, int $karigarId): array
    {
        $type = $leg['source_type'];
        $fine = (float) $leg['fine_weight'];
        if ($fine <= 0) {
            throw new LogicException('Each metal source leg must have fine weight greater than zero.');
        }
        $gross  = (float) $leg['gross_weight'];
        $purity = $leg['purity'] !== null ? (float) $leg['purity'] : $jobPurity;

        switch ($type) {
            case JobOrderSource::TYPE_VAULT:
                $lot = $this->lockVaultLot((int) $leg['metal_lot_id'], $shopId);
                $this->assertLotSufficient($lot, $fine);
                return ['type' => $type, 'fine' => $fine, 'gross' => $gross, 'purity' => $purity, 'lot' => $lot, 'customer_id' => null];

            case JobOrderSource::TYPE_KARIGAR_HELD:
                $lot = $this->resolveHoldingLot($shopId, $karigarId, $metalType, $purity);
                $this->assertLotSufficient($lot, $fine);
                return ['type' => $type, 'fine' => $fine, 'gross' => $gross, 'purity' => $purity, 'lot' => $lot, 'customer_id' => null];

            case JobOrderSource::TYPE_CUSTOMER_ADVANCE:
                $customerId = (int) ($leg['customer_id'] ?? 0);
                if (! $customerId) {
                    throw new LogicException('Customer-supplied metal requires a customer.');
                }
                // Guard reads the CONSUMING customer's own ledger balance — never
                // the pool, never another customer (CUST-2).
                $this->assertCustomerHasGold($shopId, $customerId, $fine);
                $lot = $this->lockCustomerAdvanceLot($shopId);
                return ['type' => $type, 'fine' => $fine, 'gross' => $gross, 'purity' => $purity, 'lot' => $lot, 'customer_id' => $customerId];

            default:
                throw new LogicException("Unknown metal source type '{$type}'.");
        }
    }

    /**
     * Commit one prepared source leg: emit movement(s), debit balances, and
     * write the JobOrderIssuance (receive() reads it) + JobOrderSource leg.
     */
    private function commitSourceLeg(array $p, JobOrder $jobOrder, int $shopId, int $userId): void
    {
        $lot        = $p['lot'];
        $fine       = $p['fine'];
        $movementId = null;

        if ($p['type'] === JobOrderSource::TYPE_CUSTOMER_ADVANCE) {
            // Draw down BOTH the pooled customer-advance lot AND the consuming
            // customer's liability ledger together (CUST-1 keeps pool == Σ ledger).
            // No MetalMovement — consistent with the movement-less customer-advance
            // design (deposits and sale_offset emit none either).
            $lot->fine_weight_remaining = (float) $lot->fine_weight_remaining - $fine;
            $lot->save();

            CustomerGoldTransaction::record([
                'shop_id'        => $shopId,
                'customer_id'    => $p['customer_id'],
                'fine_gold'      => -$fine,
                'type'           => 'job_consumed',
                'reference_type' => 'job_order',
                'reference_id'   => $jobOrder->id,
            ]);
        } else {
            // vault / karigar_held: a real lot debit + job_issue movement.
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
            $movementId = $movement->id;

            $lot->fine_weight_remaining = (float) $lot->fine_weight_remaining - $fine;
            $lot->save();
        }

        JobOrderIssuance::create([
            'shop_id'           => $shopId,
            'job_order_id'      => $jobOrder->id,
            'metal_lot_id'      => $lot->id,
            'metal_movement_id' => $movementId,
            'gross_weight'      => $p['gross'],
            'fine_weight'       => $fine,
            'purity'            => $p['purity'],
        ]);

        JobOrderSource::create([
            'shop_id'           => $shopId,
            'job_order_id'      => $jobOrder->id,
            'source_type'       => $p['type'],
            'metal_lot_id'      => $lot->id,
            'customer_id'       => $p['customer_id'],
            'gross_weight'      => $p['gross'],
            'fine_weight'       => $fine,
            'purity'            => $p['purity'],
            'metal_movement_id' => $movementId,
        ]);
    }

    private function lockVaultLot(int $lotId, int $shopId): MetalLot
    {
        if (! $lotId) {
            throw new LogicException('Vault source leg must reference a metal lot.');
        }
        $lot = MetalLot::query()
            ->where('id', $lotId)
            ->where('shop_id', $shopId)
            ->lockForUpdate()
            ->first();
        if (! $lot) {
            throw new LogicException("Metal lot {$lotId} not found in this shop.");
        }

        return $lot;
    }

    private function resolveHoldingLot(int $shopId, int $karigarId, string $metalType, float $purity): MetalLot
    {
        $lot = MetalLot::query()
            ->where('shop_id', $shopId)
            ->where('karigar_id', $karigarId)
            ->where('source', MetalLot::SOURCE_KARIGAR_HELD)
            ->where('metal_type', $metalType)
            ->where('purity', round($purity, 2))
            ->lockForUpdate()
            ->first();
        if (! $lot) {
            throw new LogicException("Karigar holds no {$metalType} at {$purity} to draw from.");
        }

        return $lot;
    }

    private function assertLotSufficient(MetalLot $lot, float $fine): void
    {
        if ((float) $lot->fine_weight_remaining + self::TOLERANCE_FINE_GRAMS < $fine) {
            throw new LogicException("Lot #{$lot->lot_number} only has " . number_format((float) $lot->fine_weight_remaining, 3) . 'g fine available.');
        }
    }

    private function assertCustomerHasGold(int $shopId, int $customerId, float $fine): void
    {
        $balance = (float) CustomerGoldTransaction::where('shop_id', $shopId)
            ->where('customer_id', $customerId)
            ->sum('fine_gold');
        if ($balance + self::TOLERANCE_FINE_GRAMS < $fine) {
            throw new LogicException('Customer has only ' . number_format($balance, 3) . 'g of deposited gold available.');
        }
    }

    private function lockCustomerAdvanceLot(int $shopId): MetalLot
    {
        // The single pooled per-shop customer-advance lot (the same one deposits
        // use). The balance guard runs first, so a positive balance guarantees the
        // pool exists.
        return MetalLot::query()
            ->where('shop_id', $shopId)
            ->where('source', 'customer_advance')
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Credit a karigar's holding lot with retained metal — find-or-create the
     * one-per-(shop,karigar,metal,purity) holding lot, emit a karigar_retained
     * movement (to_lot=holding lot) so the lot reconciles via the ledger, and
     * bump its balance. The partial unique index makes find-or-create race-safe.
     */
    private function creditHoldingLot(JobOrder $jobOrder, float $purity, float $fine, int $userId): MetalLot
    {
        $shopId    = (int) $jobOrder->shop_id;
        $karigarId = (int) $jobOrder->karigar_id;
        $metalType = $jobOrder->metal_type ?? 'gold';
        $purity    = round($purity, 2);

        $find = fn () => MetalLot::query()
            ->where('shop_id', $shopId)
            ->where('karigar_id', $karigarId)
            ->where('source', MetalLot::SOURCE_KARIGAR_HELD)
            ->where('metal_type', $metalType)
            ->where('purity', $purity)
            ->lockForUpdate()
            ->first();

        $lot = $find();
        if (! $lot) {
            try {
                $lot = MetalLot::create([
                    'shop_id'               => $shopId,
                    'karigar_id'            => $karigarId,
                    'source'                => MetalLot::SOURCE_KARIGAR_HELD,
                    'metal_type'            => $metalType,
                    'purity'                => $purity,
                    'fine_weight_total'     => 0,
                    'fine_weight_remaining' => 0,
                    'cost_per_fine_gram'    => 0,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $lot = $find(); // a concurrent receive created it first
            }
        }

        MetalMovement::record([
            'shop_id'        => $shopId,
            'from_lot_id'    => null,
            'to_lot_id'      => $lot->id,
            'fine_weight'    => $fine,
            'type'           => 'karigar_retained',
            'reference_type' => 'job_order',
            'reference_id'   => $jobOrder->id,
            'user_id'        => $userId,
        ]);

        $lot->fine_weight_remaining = (float) $lot->fine_weight_remaining + $fine;
        $lot->fine_weight_total     = (float) $lot->fine_weight_total + $fine;
        $lot->save();

        return $lot;
    }

    /**
     * A unique barcode for a manufactured (job-work) item. Unique by
     * construction (receipt id + per-receipt sequence); a numeric suffix is
     * appended on the rare chance of a collision.
     */
    private function receiptItemBarcode(int $shopId, int $receiptId, int $seq): string
    {
        $barcode = 'JOB' . str_pad((string) $receiptId, 6, '0', STR_PAD_LEFT) . str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
        $suffix = 1;
        while (Item::query()->where('shop_id', $shopId)->where('barcode', $barcode)->exists()) {
            $barcode .= '-' . $suffix++;
        }

        return $barcode;
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

            foreach ($computed as $idx => $row) {
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
                    'barcode'         => $this->receiptItemBarcode($jobOrder->shop_id, (int) $receipt->id, $idx + 1),
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

            $newReturnedFine = (float) $jobOrder->returned_fine_weight + $totalFine;

            // Operator-declared retained metal (the karigar keeps it). Validated
            // against the gram identity, then credited to the karigar's holding
            // lot so it persists past completion and stays consumable.
            $retained = (float) ($receiptData['retained_fine_weight'] ?? 0);
            if ($retained < 0) {
                throw new LogicException('Retained metal cannot be negative.');
            }
            if ($retained > 0) {
                $accounted = $newReturnedFine
                    + (float) $jobOrder->leftover_returned_fine_weight
                    + (float) $jobOrder->retained_returned_fine_weight
                    + $retained;
                if ($accounted > (float) $jobOrder->issued_fine_weight + self::TOLERANCE_FINE_GRAMS) {
                    throw new LogicException('Returned + leftover + retained exceeds the issued metal.');
                }

                $this->creditHoldingLot($jobOrder, (float) $jobOrder->purity, $retained, $userId);
                $jobOrder->retained_returned_fine_weight = (float) $jobOrder->retained_returned_fine_weight + $retained;
            }

            $jobOrder->returned_gross_weight = (float) $jobOrder->returned_gross_weight + $totalGross;
            $jobOrder->returned_fine_weight  = $newReturnedFine;
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
        // 4th sink: metal the karigar legitimately retained (held, not lost).
        $retained       = (float) $jobOrder->retained_returned_fine_weight;
        $allowedWastage = $issued * (float) $jobOrder->allowed_wastage_percent / 100;
        $actualWastage  = $issued - $returned - $leftover - $retained;

        $flags = [];

        if ($actualWastage < -self::TOLERANCE_FINE_GRAMS) {
            $flags[] = JobOrder::FLAG_EXCESS_RETURN;
        }
        if ($actualWastage > $allowedWastage + self::TOLERANCE_FINE_GRAMS) {
            $flags[] = JobOrder::FLAG_EXCESS_WASTAGE;
        }
        if ($returned + $leftover + $retained + $allowedWastage + self::TOLERANCE_FINE_GRAMS < $issued && $returned > 0) {
            $flags[] = JobOrder::FLAG_SHORT_RETURN;
        }

        $jobOrder->actual_wastage_fine = max(0.0, $actualWastage);
        $jobOrder->discrepancy_flags   = $flags ?: null;

        // Retained metal counts toward fulfilment — it is accounted (with the
        // karigar), so a job that legitimately left metal out still completes.
        $expectedReturn  = (float) $jobOrder->expected_return_fine_weight;
        $isFulfilled     = ($returned + $leftover + $retained + self::TOLERANCE_FINE_GRAMS) >= $expectedReturn;
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
