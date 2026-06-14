<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itemise hallmark charges on customer invoice lines (parity with making_charges
 * and stone_amount, and with quick_bill_items which already store it). This is
 * the data the RefundPolicyResolver needs to honour the existing
 * "Refund hallmark charges: No (retain)" return-policy setting, which was inert
 * because hallmark was folded into line_total with no separate column to deduct.
 *
 * SAFETY (empirically verified against the live accounting triggers):
 *  - The invoices_accounting_guard_trigger enforces only subtotal=SUM(line_total)
 *    and total=subtotal+gst+wastage-discount+round_off. It never reads the
 *    per-charge columns, so this additive column cannot trip it. line_total is
 *    NOT changed.
 *  - Default 0 → every existing line is valid immediately; no backfill. Existing
 *    hallmark was always inside line_total and was always refunded under the old
 *    rule, so 0 ("no separate hallmark broken out") is the correct historical
 *    state — historical refunds are not rewritten.
 *  - GST is computed on the whole line value, so itemising a component changes
 *    no tax.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'hallmark_charges')) {
                $table->decimal('hallmark_charges', 12, 2)->default(0)->after('stone_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'hallmark_charges')) {
                $table->dropColumn('hallmark_charges');
            }
        });
    }
};
