<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Phase E adversarial validation for the Returns & Exchanges domain.
 *
 * Runs 10 integrity checks against live data to surface accounting drift,
 * disposition gaps, and policy consistency issues. Safe to run on production
 * (all read-only queries).
 *
 * Exit codes: 0 = all checks passed, 1 = one or more checks failed.
 */
class ValidateReturnsIntegrity extends Command
{
    protected $signature   = 'returns:validate
                                {--shop= : Limit checks to a specific shop_id}
                                {--verbose-sql : Print the raw SQL for each check}';

    protected $description = 'Run adversarial integrity checks on the returns & exchanges domain.';

    private int $failures = 0;

    public function handle(): int
    {
        $shopId = $this->option('shop') ? (int) $this->option('shop') : null;

        $this->info('Running returns integrity checks' . ($shopId ? " for shop #{$shopId}" : ' (all shops)') . '...');
        $this->newLine();

        $this->check1_CreditNoteTotalNoOverflow($shopId);
        $this->check2_SettledReturnHasExactlyOneCreditNote($shopId);
        $this->check3_CreditNoteLineItemsSum($shopId);
        $this->check4_GstRetentionConsistency($shopId);
        $this->check5_ReturnLineItemAllocatedNoOverflow($shopId);
        $this->check6_MeltDispositionHasMetalMovement($shopId);
        $this->check7_StoreCreditNonNegativeBalance($shopId);
        $this->check8_StoreCreditSourcesExist($shopId);
        $this->check9_ExchangeOrderLinkedEntitiesExist($shopId);
        $this->check10_ReturnedItemStatusConsistency($shopId);
        $this->check11_CnGstMatchesLineSum($shopId);
        $this->check12_CgstSgstSumToGst($shopId);

        $this->newLine();

        if ($this->failures === 0) {
            $this->info('All checks passed.');
            return self::SUCCESS;
        }

        $this->error("{$this->failures} check(s) failed. Review the rows above and investigate.");
        return self::FAILURE;
    }

    // ─── Check 1 ─────────────────────────────────────────────────────────────
    // SUM(credit_notes.total WHERE invoice_id = X) must never exceed invoice.total.
    private function check1_CreditNoteTotalNoOverflow(?int $shopId): void
    {
        $sql = "
            SELECT cn.invoice_id,
                   SUM(cn.total)  AS total_credited,
                   i.total        AS invoice_total
            FROM   credit_notes cn
            JOIN   invoices i ON i.id = cn.invoice_id
            WHERE  cn.status = 'issued'
              " . ($shopId ? "AND cn.shop_id = {$shopId}" : '') . "
            GROUP  BY cn.invoice_id, i.total
            HAVING SUM(cn.total) > i.total + 0.005
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            1,
            'Credit-note totals do not exceed original invoice total',
            $rows,
            fn ($r) => "invoice_id={$r->invoice_id}: credited={$r->total_credited}, invoice={$r->invoice_total}",
        );
    }

    // ─── Check 2 ─────────────────────────────────────────────────────────────
    // Every settled return has exactly one issued credit note.
    private function check2_SettledReturnHasExactlyOneCreditNote(?int $shopId): void
    {
        $sql = "
            SELECT ro.id AS return_order_id,
                   COUNT(cn.id) AS cn_count
            FROM   return_orders ro
            LEFT   JOIN credit_notes cn ON cn.return_order_id = ro.id AND cn.status = 'issued'
            WHERE  ro.status = 'settled'
              " . ($shopId ? "AND ro.shop_id = {$shopId}" : '') . "
            GROUP  BY ro.id
            HAVING COUNT(cn.id) != 1
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            2,
            'Every settled return has exactly one issued credit note',
            $rows,
            fn ($r) => "return_order_id={$r->return_order_id}: credit_note_count={$r->cn_count}",
        );
    }

    // ─── Check 3 ─────────────────────────────────────────────────────────────
    // Credit note total matches the sum of its return line item refund_totals.
    // Only checks CNs created after Phase A shipped (policy_breakdown column added).
    private function check3_CreditNoteLineItemsSum(?int $shopId): void
    {
        $sql = "
            SELECT cn.id AS credit_note_id,
                   cn.total AS cn_total,
                   SUM(rl.refund_total) AS lines_total
            FROM   credit_notes cn
            JOIN   return_orders ro ON ro.id = cn.return_order_id
            JOIN   return_line_items rl ON rl.return_order_id = ro.id
            WHERE  cn.status = 'issued'
              AND  rl.policy_breakdown IS NOT NULL
              " . ($shopId ? "AND cn.shop_id = {$shopId}" : '') . "
            GROUP  BY cn.id, cn.total
            HAVING ABS(cn.total - SUM(rl.refund_total)) > 0.01
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            3,
            'Credit note total matches sum of line item refund_totals',
            $rows,
            fn ($r) => "credit_note_id={$r->credit_note_id}: cn_total={$r->cn_total}, lines_sum={$r->lines_total}",
        );
    }

    // ─── Check 4 ─────────────────────────────────────────────────────────────
    // When refund_gst=false on a credit note, the CN gst_amount must be 0.
    // Scoped to CNs issued after return_policy_configured_at — pre-policy CNs
    // legitimately included GST before the setting existed.
    private function check4_GstRetentionConsistency(?int $shopId): void
    {
        $sql = "
            SELECT cn.id AS credit_note_id, cn.gst,
                   sp.refund_gst
            FROM   credit_notes cn
            JOIN   return_orders ro ON ro.id = cn.return_order_id
            JOIN   shops s ON s.id = cn.shop_id
            JOIN   shop_preferences sp ON sp.shop_id = s.id
            WHERE  cn.status = 'issued'
              AND  sp.refund_gst = false
              AND  cn.gst != 0
              AND  sp.return_policy_configured_at IS NOT NULL
              AND  cn.issued_at > sp.return_policy_configured_at
              " . ($shopId ? "AND cn.shop_id = {$shopId}" : '') . "
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            4,
            'Credit notes respect the refund_gst=false policy (gst_amount=0 when GST not refunded)',
            $rows,
            fn ($r) => "credit_note_id={$r->credit_note_id}: gst={$r->gst} but refund_gst=false",
        );
    }

    // ─── Check 5 ─────────────────────────────────────────────────────────────
    // Cumulative returned_amount per invoice_item must not exceed the line's total paid.
    private function check5_ReturnLineItemAllocatedNoOverflow(?int $shopId): void
    {
        $sql = "
            SELECT ii.id AS invoice_item_id,
                   ii.line_total + ii.gst_amount
                    - COALESCE(ii.allocated_discount, 0)
                    + COALESCE(ii.allocated_round_off, 0) AS max_refundable,
                   SUM(rl.refund_total) AS total_returned
            FROM   invoice_items ii
            JOIN   return_line_items rl ON rl.invoice_item_id = ii.id
            " . ($shopId ? "JOIN invoices inv ON inv.id = ii.invoice_id AND inv.shop_id = {$shopId}" : '') . "
            GROUP  BY ii.id, ii.line_total, ii.gst_amount,
                      ii.allocated_discount, ii.allocated_round_off
            HAVING SUM(rl.refund_total) >
                   (ii.line_total + ii.gst_amount
                    - COALESCE(ii.allocated_discount, 0)
                    + COALESCE(ii.allocated_round_off, 0)) + 0.01
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            5,
            'No invoice line has been refunded more than its locked allocated amount',
            $rows,
            fn ($r) => "invoice_item_id={$r->invoice_item_id}: returned={$r->total_returned}, max={$r->max_refundable}",
        );
    }

    // ─── Check 6 ─────────────────────────────────────────────────────────────
    // Every sent_to_melt disposition where target_lot_id is set (melt was recorded)
    // must have a corresponding return_melt_recovery MetalMovement on the line item.
    private function check6_MeltDispositionHasMetalMovement(?int $shopId): void
    {
        $sql = "
            SELECT rid.id AS disposition_id,
                   rid.return_line_item_id,
                   rid.target_lot_id
            FROM   returned_item_dispositions rid
            WHERE  rid.disposition = 'sent_to_melt'
              AND  rid.target_lot_id IS NOT NULL
              " . ($shopId ? "AND rid.shop_id = {$shopId}" : '') . "
              AND  NOT EXISTS (
                       SELECT 1 FROM metal_movements mm
                       WHERE  mm.reference_type = 'return_line_item'
                         AND  mm.reference_id   = rid.return_line_item_id
                         AND  mm.type           = 'return_melt_recovery'
                   )
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            6,
            'Every sent_to_melt disposition with a recorded lot has a return_melt_recovery MetalMovement',
            $rows,
            fn ($r) => "disposition_id={$r->disposition_id}: return_line_item_id={$r->return_line_item_id}, lot={$r->target_lot_id}",
        );
    }

    // ─── Check 7 ─────────────────────────────────────────────────────────────
    // No customer has a negative store-credit balance (DB trigger should prevent this,
    // but verify no historical data slipped through).
    private function check7_StoreCreditNonNegativeBalance(?int $shopId): void
    {
        $sql = "
            SELECT customer_id,
                   shop_id,
                   SUM(amount) AS balance
            FROM   store_credit_movements
            " . ($shopId ? "WHERE shop_id = {$shopId}" : '') . "
            GROUP  BY customer_id, shop_id
            HAVING SUM(amount) < -0.005
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            7,
            'No customer has a negative store-credit balance',
            $rows,
            fn ($r) => "customer_id={$r->customer_id}, shop_id={$r->shop_id}: balance={$r->balance}",
        );
    }

    // ─── Check 8 ─────────────────────────────────────────────────────────────
    // Every store_credit_movement has a valid source record (no orphaned entries).
    private function check8_StoreCreditSourcesExist(?int $shopId): void
    {
        $shopFilter = $shopId ? "AND scm.shop_id = {$shopId}" : '';
        $sql = "
            SELECT scm.id, scm.source_type, scm.source_id
            FROM   store_credit_movements scm
            WHERE  scm.source_type IS NOT NULL
              AND  scm.source_id   IS NOT NULL
              AND  (
                       (scm.source_type = 'credit_note_issued'
                        AND NOT EXISTS (SELECT 1 FROM credit_notes WHERE id = scm.source_id))
                    OR (scm.source_type = 'sale_applied'
                        AND NOT EXISTS (SELECT 1 FROM invoices WHERE id = scm.source_id))
                   )
              {$shopFilter}
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            8,
            'All store_credit_movements reference existing source records',
            $rows,
            fn ($r) => "movement_id={$r->id}: source_type={$r->source_type}, source_id={$r->source_id} not found",
        );
    }

    // ─── Check 9 ─────────────────────────────────────────────────────────────
    // Every exchange_order has both a valid return_order and a valid invoice.
    private function check9_ExchangeOrderLinkedEntitiesExist(?int $shopId): void
    {
        $sql = "
            SELECT eo.id AS exchange_order_id
            FROM   exchange_orders eo
            WHERE  " . ($shopId ? "eo.shop_id = {$shopId} AND " : '') . "
                   (
                       eo.return_order_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM return_orders ro WHERE ro.id = eo.return_order_id)
                    OR eo.new_invoice_id IS NOT NULL
                       AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.id = eo.new_invoice_id)
                   )
        ";

        $rows = DB::select($sql);

        $this->reportCheck(
            9,
            'Every exchange_order has valid linked return_order and new invoice',
            $rows,
            fn ($r) => "exchange_order_id={$r->exchange_order_id}: missing return_order or new invoice",
        );
    }

    // ─── Check 10 ────────────────────────────────────────────────────────────
    // Items with status='returned' must have a corresponding return_line_item.
    // Items referenced by a settled return must have status='returned' (or later: restocked/melted/etc.).
    private function check10_ReturnedItemStatusConsistency(?int $shopId): void
    {
        // Items marked 'returned' with no matching return line item
        $sql = "
            SELECT i.id AS item_id, i.status
            FROM   items i
            WHERE  i.status = 'returned'
              " . ($shopId ? "AND i.shop_id = {$shopId}" : '') . "
              AND  NOT EXISTS (
                       SELECT 1 FROM return_line_items rl WHERE rl.item_id = i.id
                   )
        ";

        $orphaned = DB::select($sql);

        $this->reportCheck(
            10,
            'All returned-status items have a matching return_line_item record',
            $orphaned,
            fn ($r) => "item_id={$r->item_id}: status=returned but no return_line_item found",
        );
    }

    // ─── Check 11 ────────────────────────────────────────────────────────────
    // CN gst must match sum of its return_line_items.refund_gst.
    private function check11_CnGstMatchesLineSum(?int $shopId): void
    {
        $rows = DB::select("
            SELECT cn.id, cn.credit_note_number, cn.gst,
                   COALESCE(SUM(rl.refund_gst), 0) as line_sum
            FROM credit_notes cn
            JOIN return_orders ro ON ro.id = cn.return_order_id
            JOIN return_line_items rl ON rl.return_order_id = ro.id
            WHERE cn.shop_id " . ($shopId ? "= {$shopId}" : "IS NOT NULL") . "
              AND cn.return_order_id IS NOT NULL
              AND rl.policy_breakdown IS NOT NULL
            GROUP BY cn.id, cn.credit_note_number, cn.gst
            HAVING ABS(cn.gst - COALESCE(SUM(rl.refund_gst), 0)) > 0.01
        ");

        $this->reportCheck(
            11,
            'All CN gst values match sum of line refund_gst',
            $rows,
            fn ($r) => "CN {$r->credit_note_number} gst={$r->gst} but line_sum={$r->line_sum}",
        );
    }

    // ─── Check 12 ────────────────────────────────────────────────────────────
    // CGST + SGST + IGST must sum to GST on all finalized invoices and issued CNs
    // that have the CGST columns populated.
    private function check12_CgstSgstSumToGst(?int $shopId): void
    {
        $shopFilter = $shopId ? "= {$shopId}" : "IS NOT NULL";

        $invoiceRows = DB::select("
            SELECT id, invoice_number, gst, cgst_amount, sgst_amount, igst_amount
            FROM invoices
            WHERE shop_id {$shopFilter}
              AND status = 'finalized'
              AND cgst_amount IS NOT NULL
              AND ABS(COALESCE(cgst_amount,0) + COALESCE(sgst_amount,0) + COALESCE(igst_amount,0) - gst) > 0.01
        ");

        $cnRows = DB::select("
            SELECT cn.id, cn.credit_note_number, cn.gst, cn.cgst_amount, cn.sgst_amount, cn.igst_amount
            FROM credit_notes cn
            WHERE cn.shop_id {$shopFilter}
              AND cn.cgst_amount IS NOT NULL
              AND ABS(COALESCE(cn.cgst_amount,0) + COALESCE(cn.sgst_amount,0) + COALESCE(cn.igst_amount,0) - cn.gst) > 0.01
        ");

        $allRows = array_merge(
            array_map(fn ($r) => ['type' => 'invoice', 'row' => $r], $invoiceRows),
            array_map(fn ($r) => ['type' => 'cn', 'row' => $r], $cnRows),
        );

        $failRows = [];
        foreach ($allRows as $item) {
            $r = $item['row'];
            if ($item['type'] === 'invoice') {
                $failRows[] = (object) [
                    'label' => "Invoice {$r->invoice_number} cgst+sgst+igst≠gst (gst={$r->gst})",
                ];
            } else {
                $failRows[] = (object) [
                    'label' => "CN {$r->credit_note_number} cgst+sgst+igst≠gst (gst={$r->gst})",
                ];
            }
        }

        $this->reportCheck(
            12,
            'All CGST+SGST+IGST values sum to GST correctly',
            $failRows,
            fn ($r) => $r->label,
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function reportCheck(int $n, string $label, array $rows, callable $formatter, int $total = 12): void
    {
        $icon = count($rows) === 0 ? '<fg=green>✓</>' : '<fg=red>✗</>';

        $this->line("  {$icon}  [{$n}/{$total}] {$label}");

        if (count($rows) > 0) {
            $this->failures++;
            foreach (array_slice($rows, 0, 10) as $row) {
                $this->line("       <fg=yellow>→</> " . $formatter($row));
            }
            if (count($rows) > 10) {
                $this->line("       <fg=yellow>→</> ... and " . (count($rows) - 10) . ' more');
            }
        }
    }
}
