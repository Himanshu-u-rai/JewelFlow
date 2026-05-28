<?php

namespace App\Services;

use App\Models\InvoiceItem;
use App\Models\Item;
use App\Models\StoneComponent;
use App\Models\ReturnLineItem;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2A — Stone snapshot orchestrator.
 *
 * Sole entry point used by SalesService / RetailerSalesService /
 * ExchangeService / ReturnService to copy stone_components from an
 * Item into a new invoice_item or return_line_item at line-creation
 * time. The snapshot becomes constitutionally immutable once the
 * parent invoice/return reaches its final state.
 *
 * Single-purpose service so the snapshot doctrine lives in exactly
 * one place (CONSTITUTION.md Article III — Orchestration in event
 * listeners / single-purpose services).
 */
final class StoneSnapshotService
{
    /**
     * Snapshot the stone_components of $item into rows tied to
     * $invoiceItem. The legacy invoice_items.stone_amount column is
     * NOT touched — it remains the authoritative source for legacy
     * readers (RefundPolicyResolver, GoldValuationService) during the
     * Stage A coexistence window.
     *
     * @return int Number of stone snapshot rows created.
     */
    public function snapshotForInvoiceItem(InvoiceItem $invoiceItem, Item $item): int
    {
        $stones = StoneComponent::query()
            ->where('item_id', $item->id)
            ->whereNull('invoice_item_id')
            ->whereNull('return_line_item_id')
            ->get();

        if ($stones->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($stones as $stone) {
            StoneComponent::record([
                'shop_id'              => (int) $invoiceItem->invoice->shop_id,
                'item_id'              => (int) $item->id,
                'invoice_item_id'      => (int) $invoiceItem->id,
                'return_line_item_id'  => null,
                'stone_type'           => $stone->stone_type,
                'carat_weight'         => $stone->carat_weight,
                'count'                => (int) $stone->count,
                'unit_value'           => $stone->unit_value,
                'total_value'          => $stone->total_value,
                'notes'                => $stone->notes,
                'migrated_from_legacy' => false,
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Snapshot the stone_components of the invoice_item being returned
     * into rows tied to $returnLineItem. The originals on the invoice
     * remain locked; this is a NEW snapshot record on the return side.
     */
    public function snapshotForReturnLineItem(ReturnLineItem $returnLineItem, InvoiceItem $sourceInvoiceItem): int
    {
        $stones = StoneComponent::query()
            ->where('invoice_item_id', $sourceInvoiceItem->id)
            ->get();

        if ($stones->isEmpty()) {
            return 0;
        }

        $count = 0;
        foreach ($stones as $stone) {
            StoneComponent::record([
                'shop_id'              => (int) $returnLineItem->shop_id,
                'item_id'              => null,
                'invoice_item_id'      => null,
                'return_line_item_id'  => (int) $returnLineItem->id,
                'stone_type'           => $stone->stone_type,
                'carat_weight'         => $stone->carat_weight,
                'count'                => (int) $stone->count,
                'unit_value'           => $stone->unit_value,
                'total_value'          => $stone->total_value,
                'notes'                => 'Phase 2A snapshot — returned from invoice_item #' . $sourceInvoiceItem->id,
                'migrated_from_legacy' => false,
            ]);
            $count++;
        }

        return $count;
    }
}
