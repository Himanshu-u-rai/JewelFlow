<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Two operations in one migration, because they're tightly coupled:
     *
     * 1. Upgrade the `invoice_items_finalized_guard` trigger to allow updates
     *    that ONLY touch the new post-finalization-mutable columns:
     *       - allocated_discount, allocated_round_off, allocated_loyalty_pts
     *         (computed at finalize-time or backfilled here)
     *       - returned_at, return_line_item_id
     *         (set when a return processes this line)
     *    The trigger continues to block any other field changes — the existing
     *    financial-integrity guard (line_total, gst_amount, weight, rate, etc.
     *    remain immutable post-finalize).
     *
     * 2. Backfill allocated_discount and allocated_round_off on existing
     *    finalized/cancelled invoices using largest-remainder rounding on
     *    paisa-integer (no cumulative ₹0.01 drift).
     *
     * Loyalty: legacy rows leave allocated_loyalty_pts=0. Future work that
     * needs proportional loyalty reversal will revisit.
     */
    public function up(): void
    {
        // 1. Smarter trigger — allows the new columns to mutate post-finalize.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_items_finalized_guard() RETURNS trigger AS $$
DECLARE
    inv_status text;
    blocked_change boolean := false;
BEGIN
    SELECT status INTO inv_status FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
    IF inv_status NOT IN ('finalized', 'cancelled') THEN
        RETURN COALESCE(NEW, OLD);
    END IF;

    -- For finalized/cancelled invoices, allow UPDATE only when the dirty
    -- columns are within the post-finalization-mutable allow-list.
    -- INSERTs and DELETEs remain forbidden (no append/remove of lines after finalize).
    IF TG_OP = 'INSERT' OR TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    -- UPDATE: check that every column that changed is in the allow-list.
    IF NEW.invoice_id            IS DISTINCT FROM OLD.invoice_id            THEN blocked_change := true; END IF;
    IF NEW.item_id               IS DISTINCT FROM OLD.item_id               THEN blocked_change := true; END IF;
    IF NEW.weight                IS DISTINCT FROM OLD.weight                THEN blocked_change := true; END IF;
    IF NEW.rate                  IS DISTINCT FROM OLD.rate                  THEN blocked_change := true; END IF;
    IF NEW.making_charges        IS DISTINCT FROM OLD.making_charges        THEN blocked_change := true; END IF;
    IF NEW.stone_amount          IS DISTINCT FROM OLD.stone_amount          THEN blocked_change := true; END IF;
    IF NEW.line_total            IS DISTINCT FROM OLD.line_total            THEN blocked_change := true; END IF;
    IF NEW.gst_rate              IS DISTINCT FROM OLD.gst_rate              THEN blocked_change := true; END IF;
    IF NEW.gst_amount            IS DISTINCT FROM OLD.gst_amount            THEN blocked_change := true; END IF;
    -- allocated_discount, allocated_round_off, allocated_loyalty_pts,
    -- returned_at, return_line_item_id, updated_at: ALLOWED to change.

    IF blocked_change THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        // 2. Backfill.
        DB::table('invoices')
            ->whereIn('status', ['finalized', 'cancelled'])
            ->orderBy('id')
            ->chunkById(200, function ($invoices) {
                foreach ($invoices as $invoice) {
                    $this->backfillInvoice($invoice);
                }
            });
    }

    public function down(): void
    {
        // Restore the original blunt trigger.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoice_items_finalized_guard() RETURNS trigger AS $$
DECLARE
    inv_status text;
BEGIN
    SELECT status INTO inv_status FROM invoices WHERE id = COALESCE(NEW.invoice_id, OLD.invoice_id);
    IF inv_status IN ('finalized', 'cancelled') THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::table('invoice_items')->update([
            'allocated_discount'  => 0,
            'allocated_round_off' => 0,
        ]);
    }

    private function backfillInvoice(object $invoice): void
    {
        $lines = DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->orderBy('id')
            ->get(['id', 'line_total']);

        if ($lines->isEmpty()) {
            return;
        }

        $totalSubtotalPaisa = (int) round(((float) $invoice->subtotal) * 100);
        if ($totalSubtotalPaisa <= 0) {
            return;
        }

        $linePaisas = $lines
            ->map(fn ($l) => ['id' => $l->id, 'subtotal_paisa' => (int) round(((float) $l->line_total) * 100)])
            ->all();

        $discountPaisa  = (int) round(((float) $invoice->discount)  * 100);
        $roundOffMicro  = (int) round(((float) $invoice->round_off) * 10000);

        $allocatedDiscount = $this->largestRemainder($linePaisas, $discountPaisa, 'subtotal_paisa');
        $allocatedRoundOff = $this->largestRemainder($linePaisas, $roundOffMicro,  'subtotal_paisa');

        foreach ($linePaisas as $line) {
            DB::table('invoice_items')
                ->where('id', $line['id'])
                ->update([
                    'allocated_discount'  => round($allocatedDiscount[$line['id']] / 100, 2),
                    'allocated_round_off' => round($allocatedRoundOff[$line['id']] / 10000, 4),
                ]);
        }
    }

    /**
     * Largest-remainder apportionment on integer values. SUM(allocated) ==
     * totalAmount exactly. Handles negative totals (e.g. mirror invoices).
     */
    private function largestRemainder(array $shares, int $totalAmount, string $weightKey): array
    {
        $ids = array_column($shares, 'id');
        if ($totalAmount === 0 || count($shares) === 0) {
            return array_combine($ids, array_fill(0, count($shares), 0));
        }

        $totalWeight = array_sum(array_column($shares, $weightKey));
        if ($totalWeight <= 0) {
            return array_combine($ids, array_fill(0, count($shares), 0));
        }

        $allocated = [];
        $remainders = [];
        $allocatedSum = 0;
        foreach ($shares as $share) {
            $exact = ($totalAmount * $share[$weightKey]) / $totalWeight;
            $floor = $totalAmount >= 0 ? (int) floor($exact) : (int) ceil($exact);
            $allocated[$share['id']] = $floor;
            $remainders[$share['id']] = $exact - $floor;
            $allocatedSum += $floor;
        }

        $remaining = $totalAmount - $allocatedSum;
        $step = $remaining >= 0 ? 1 : -1;

        $idsByRemainder = array_keys($remainders);
        usort($idsByRemainder, function ($a, $b) use ($remainders, $step) {
            $ra = $step > 0 ? $remainders[$a] : -$remainders[$a];
            $rb = $step > 0 ? $remainders[$b] : -$remainders[$b];
            return $rb <=> $ra;
        });

        $i = 0;
        while ($remaining !== 0) {
            $id = $idsByRemainder[$i % count($idsByRemainder)];
            $allocated[$id] += $step;
            $remaining -= $step;
            $i++;
        }

        return $allocated;
    }
};
