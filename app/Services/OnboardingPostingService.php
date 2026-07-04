<?php

namespace App\Services;

use App\Models\CashTransaction;
use App\Models\CustomerGoldTransaction;
use App\Models\CustomerOpeningBalance;
use App\Models\Item;
use App\Models\Karigar;
use App\Models\MetalLot;
use App\Models\MetalMovement;
use App\Models\OnboardingBatch;
use App\Models\OnboardingEntry;
use App\Models\StoreCreditMovement;
use App\Models\SupplierOpeningBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Posts a locked onboarding batch's staged entries into the canonical ledgers.
 *
 * Design guarantees (see plan §6, §7, §13):
 *  - Every posted row is back-dated to the batch as-of date so it classifies as
 *    "opening" (created_at < start_date) in every date-scoped report.
 *  - Cash and metal are independent: opening metal NEVER writes a cash row (no
 *    phantom outflow — the metal is already owned).
 *  - The four metal pools (vault, karigar-held, customer gold, opening stock)
 *    are disjoint; the returned snapshot sums each separately for reconciliation.
 *  - Every posted row is tagged reference_type=onboarding_batch / reference_id
 *    for traceability and reversal.
 *
 * Runs inside the caller's DB::transaction (OnboardingController::lock), which
 * also holds the atomic status claim that makes posting idempotent.
 */
class OnboardingPostingService
{
    private const REF_TYPE = 'onboarding_batch';

    /**
     * @return array reconciled totals snapshot captured on the batch at lock
     */
    public function post(OnboardingBatch $batch): array
    {
        $shopId = (int) $batch->shop_id;
        $userId = (int) ($batch->locked_by ?? $batch->created_by);
        $asOf   = Carbon::parse($batch->as_of_date)->startOfDay();

        $totals = [
            'cash_by_mode'        => [],
            'vault_fine'          => 0.0,
            'karigar_held_fine'   => 0.0,
            'customer_gold_fine'  => 0.0,
            'stock_item_fine'     => 0.0,
            'customer_receivable' => 0.0,
            'customer_payable'    => 0.0,
            'customer_advance'    => 0.0,
            'supplier_payable'    => 0.0,
            'supplier_receivable' => 0.0,
            'karigar_money'       => 0.0,
            'counts'              => [],
        ];

        foreach ($batch->entries()->get() as $entry) {
            $p = $entry->payload ?? [];
            $totals['counts'][$entry->kind] = ($totals['counts'][$entry->kind] ?? 0) + 1;

            match ($entry->kind) {
                OnboardingEntry::KIND_CASH                => $this->postCash($shopId, $userId, $batch->id, $asOf, $p, $totals),
                OnboardingEntry::KIND_VAULT_METAL         => $this->postMetalLot($shopId, $userId, $batch->id, $asOf, $p, MetalLot::class, 'opening', null, $totals, 'vault_fine'),
                OnboardingEntry::KIND_KARIGAR_GOLD        => $this->postMetalLot($shopId, $userId, $batch->id, $asOf, $p, MetalLot::class, MetalLot::SOURCE_KARIGAR_HELD, (int) ($p['karigar_id'] ?? 0), $totals, 'karigar_held_fine'),
                OnboardingEntry::KIND_KARIGAR_MONEY       => $this->postKarigarMoney($shopId, $asOf, $p, $totals),
                OnboardingEntry::KIND_CUSTOMER_GOLD       => $this->postCustomerGold($shopId, $batch->id, $asOf, $p, $totals),
                OnboardingEntry::KIND_CUSTOMER_ADVANCE    => $this->postCustomerAdvance($shopId, $userId, $batch->id, $asOf, $p, $totals),
                OnboardingEntry::KIND_CUSTOMER_RECEIVABLE => $this->postCustomerMoney($shopId, $userId, $batch->id, $asOf, $p, CustomerOpeningBalance::DIRECTION_RECEIVABLE, $totals),
                OnboardingEntry::KIND_CUSTOMER_PAYABLE    => $this->postCustomerMoney($shopId, $userId, $batch->id, $asOf, $p, CustomerOpeningBalance::DIRECTION_PAYABLE, $totals),
                OnboardingEntry::KIND_SUPPLIER_PAYABLE    => $this->postSupplierMoney($shopId, $userId, $batch->id, $asOf, $p, SupplierOpeningBalance::DIRECTION_PAYABLE, $totals),
                OnboardingEntry::KIND_SUPPLIER_RECEIVABLE => $this->postSupplierMoney($shopId, $userId, $batch->id, $asOf, $p, SupplierOpeningBalance::DIRECTION_RECEIVABLE, $totals),
                OnboardingEntry::KIND_STOCK_ITEM          => $this->postStockItem($shopId, $asOf, $p, $totals),
                default                                   => null, // unknown kind: skip, never fatal
            };
        }

        // Round money/metal for a clean snapshot.
        foreach (['vault_fine', 'karigar_held_fine', 'customer_gold_fine', 'stock_item_fine'] as $k) {
            $totals[$k] = round($totals[$k], 6);
        }
        $totals['total_fine'] = round(
            $totals['vault_fine'] + $totals['karigar_held_fine'] + $totals['customer_gold_fine'] + $totals['stock_item_fine'],
            6
        );

        return $totals;
    }

    private function postCash(int $shopId, int $userId, int $batchId, Carbon $asOf, array $p, array &$totals): void
    {
        $mode   = (string) ($p['payment_mode'] ?? 'cash');
        $amount = round((float) ($p['amount'] ?? 0), 2);
        if ($amount <= 0) {
            return;
        }

        // The ledger has no per-account column; the specific bank/UPI/wallet the
        // owner picked survives as a label in the description for traceability.
        $label = trim((string) ($p['account_label'] ?? ''));

        $this->insertBackdated(new CashTransaction(), [
            'shop_id'        => $shopId,
            'user_id'        => $userId,
            'type'           => 'in',
            'amount'         => $amount,
            'source_type'    => 'opening_balance',
            'payment_mode'   => $mode,
            'description'    => $label !== '' ? "Opening balance — {$label}" : 'Opening balance',
            'is_opening'     => true,
            'reference_type' => self::REF_TYPE,
            'reference_id'   => $batchId,
        ], $asOf);

        $totals['cash_by_mode'][$mode] = round(($totals['cash_by_mode'][$mode] ?? 0) + $amount, 2);
    }

    /**
     * Post a metal lot + its opening movement. source distinguishes vault
     * (source='opening') from karigar-held (source='karigar_held'). No cash row.
     */
    private function postMetalLot(int $shopId, int $userId, int $batchId, Carbon $asOf, array $p, string $lotClass, string $source, ?int $karigarId, array &$totals, string $totalKey): void
    {
        $metalType = (string) ($p['metal_type'] ?? '');
        $purity    = (float) ($p['purity'] ?? 0);
        $fine      = round((float) ($p['fine_weight'] ?? 0), 6);
        if ($metalType === '' || $fine <= 0) {
            return;
        }

        $lot = new MetalLot();
        $lot->timestamps = false;
        $lot->forceFill([
            'shop_id'               => $shopId,
            'source'                => $source,
            'metal_type'            => $metalType,
            'purity'                => $purity,
            'fine_weight_total'     => $fine,
            'fine_weight_remaining' => $fine,
            'cost_per_fine_gram'    => round((float) ($p['cost_per_fine_gram'] ?? 0), 2),
            'karigar_id'            => $karigarId ?: null,
            'notes'                 => trim((string) ($p['notes'] ?? '')) ?: 'Opening balance',
            'created_at'            => $asOf,
            'updated_at'            => $asOf,
        ]);
        $lot->save();

        $this->insertBackdated(new MetalMovement(), [
            'shop_id'        => $shopId,
            'user_id'        => $userId,
            'to_lot_id'      => $lot->id,
            'fine_weight'    => $fine,
            'type'           => 'opening',
            'metal_type'     => $metalType,
            'is_opening'     => true,
            'reference_type' => self::REF_TYPE,
            'reference_id'   => $batchId,
        ], $asOf);

        $totals[$totalKey] += $fine;
    }

    private function postKarigarMoney(int $shopId, Carbon $asOf, array $p, array &$totals): void
    {
        $amount = round((float) ($p['amount'] ?? 0), 2);
        $karigarId = (int) ($p['karigar_id'] ?? 0);
        if ($amount == 0.0 || $karigarId === 0) {
            return;
        }

        $karigar = Karigar::withoutTenant()->where('shop_id', $shopId)->whereKey($karigarId)->first();
        if (! $karigar) {
            return;
        }

        // Additive so multiple entries for one karigar accumulate; opening date
        // is the batch as-of.
        $karigar->opening_balance = round((float) $karigar->opening_balance + $amount, 2);
        $karigar->opening_balance_at = $asOf->toDateString();
        $karigar->save();

        $totals['karigar_money'] = round($totals['karigar_money'] + $amount, 2);
    }

    private function postCustomerGold(int $shopId, int $batchId, Carbon $asOf, array $p, array &$totals): void
    {
        $customerId = (int) ($p['customer_id'] ?? 0);
        $fine = round((float) ($p['fine_gold'] ?? 0), 6);
        if ($customerId === 0 || $fine == 0.0) {
            return;
        }

        $this->insertBackdated(new CustomerGoldTransaction(), [
            'shop_id'        => $shopId,
            'customer_id'    => $customerId,
            'fine_gold'      => $fine,
            'gross_weight'   => round((float) ($p['gross_weight'] ?? 0), 6),
            'purity'         => (float) ($p['purity'] ?? 0),
            'type'           => 'adjust',
            'reference_type' => self::REF_TYPE,
            'reference_id'   => $batchId,
        ], $asOf);

        $totals['customer_gold_fine'] += $fine;
    }

    private function postCustomerAdvance(int $shopId, int $userId, int $batchId, Carbon $asOf, array $p, array &$totals): void
    {
        $customerId = (int) ($p['customer_id'] ?? 0);
        $amount = round((float) ($p['amount'] ?? 0), 2);
        if ($customerId === 0 || $amount <= 0) {
            return;
        }

        $this->insertBackdated(new StoreCreditMovement(), [
            'shop_id'     => $shopId,
            'customer_id' => $customerId,
            'amount'      => $amount, // positive = credit added to wallet
            'source_type' => StoreCreditMovement::SOURCE_OPENING_ADVANCE,
            'source_id'   => $batchId,
            'notes'       => 'Opening advance',
            'user_id'     => $userId,
        ], $asOf);

        $totals['customer_advance'] = round($totals['customer_advance'] + $amount, 2);
    }

    private function postCustomerMoney(int $shopId, int $userId, int $batchId, Carbon $asOf, array $p, string $direction, array &$totals): void
    {
        $customerId = (int) ($p['customer_id'] ?? 0);
        $amount = round((float) ($p['amount'] ?? 0), 2);
        if ($customerId === 0 || $amount <= 0) {
            return;
        }

        CustomerOpeningBalance::create([
            'shop_id'             => $shopId,
            'customer_id'         => $customerId,
            'onboarding_batch_id' => $batchId,
            'direction'           => $direction,
            'amount'              => $amount,
            'as_of_date'          => $asOf->toDateString(),
            'notes'               => $p['notes'] ?? null,
            'created_by'          => $userId,
        ]);

        $totals[$direction === CustomerOpeningBalance::DIRECTION_RECEIVABLE ? 'customer_receivable' : 'customer_payable'] += $amount;
    }

    private function postSupplierMoney(int $shopId, int $userId, int $batchId, Carbon $asOf, array $p, string $direction, array &$totals): void
    {
        $vendorId = (int) ($p['vendor_id'] ?? 0);
        $amount = round((float) ($p['amount'] ?? 0), 2);
        if ($vendorId === 0 || $amount <= 0) {
            return;
        }

        SupplierOpeningBalance::create([
            'shop_id'             => $shopId,
            'vendor_id'           => $vendorId,
            'onboarding_batch_id' => $batchId,
            'direction'           => $direction,
            'amount'              => $amount,
            'as_of_date'          => $asOf->toDateString(),
            'notes'               => $p['notes'] ?? null,
            'created_by'          => $userId,
        ]);

        $totals[$direction === SupplierOpeningBalance::DIRECTION_PAYABLE ? 'supplier_payable' : 'supplier_receivable'] += $amount;
    }

    /**
     * Opening finished stock → an `items` row on the STOCK path (its own metal
     * pool). Never debits a vault lot (metal_lot_id stays null), so it can't
     * double-count against a vault entry. source='opening_stock' keeps it out
     * of the purchase report.
     */
    private function postStockItem(int $shopId, Carbon $asOf, array $p, array &$totals): void
    {
        $metalType = (string) ($p['metal_type'] ?? '');
        $gross = round((float) ($p['gross_weight'] ?? 0), 6);
        $stone = round((float) ($p['stone_weight'] ?? 0), 6);
        $purity = (float) ($p['purity'] ?? 0);
        if ($metalType === '' || $gross <= 0) {
            return;
        }
        $net = round($gross - $stone, 6);

        $item = new Item();
        $item->timestamps = false;
        $item->forceFill([
            'shop_id'                 => $shopId,
            'barcode'                 => (string) ($p['barcode'] ?? ''),
            'design'                  => $p['design'] ?? null,
            'category'                => (string) ($p['category'] ?? 'Jewellery'),
            'sub_category'            => (string) ($p['sub_category'] ?? 'General'),
            'metal_type'              => $metalType,
            'gross_weight'            => $gross,
            'stone_weight'            => $stone,
            'net_metal_weight'        => $net,
            'purity'                  => $purity,
            'making_charges'          => round((float) ($p['making_charges'] ?? 0), 2),
            'stone_charges'           => round((float) ($p['stone_charges'] ?? 0), 2),
            'cost_price'              => round((float) ($p['cost_price'] ?? 0), 2),
            'selling_price'           => round((float) ($p['selling_price'] ?? 0), 2) ?: null,
            'huid'                    => $p['huid'] ?? null,
            'hallmark_date'           => $p['hallmark_date'] ?? null,
            'metal_lot_id'            => null, // separate pool — never debits vault
            'source'                  => 'opening_stock',
            'status'                  => 'in_stock',
            'pricing_review_required' => false,
            'created_at'              => $asOf,
            'updated_at'              => $asOf,
        ]);
        $item->save();

        $multiplier = \App\Services\MetalRegistry::fineWeightMultiplier($metalType, $purity);
        if ($multiplier !== null) {
            $totals['stock_item_fine'] += round($net * $multiplier, 6);
        }
    }

    /**
     * Insert an append-only / canonical row stamped at the as-of date. Sets
     * timestamps=false so Eloquent does not overwrite created_at with now().
     */
    private function insertBackdated(Model $model, array $attributes, Carbon $asOf): Model
    {
        $model->timestamps = false;
        $model->forceFill(array_merge($attributes, [
            'created_at' => $asOf,
            'updated_at' => $asOf,
        ]));
        $model->save();

        return $model;
    }
}
