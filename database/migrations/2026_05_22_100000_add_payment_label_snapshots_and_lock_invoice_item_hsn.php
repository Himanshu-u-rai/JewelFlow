<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_payments', 'payment_method_label_snapshot')) {
                $table->string('payment_method_label_snapshot', 255)->nullable()->after('payment_method_id');
            }
        });

        Schema::table('karigar_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('karigar_payments', 'payment_method_label_snapshot')) {
                $table->string('payment_method_label_snapshot', 255)->nullable()->after('payment_method_id');
            }
        });

        Schema::table('quick_bill_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('quick_bill_payments', 'payment_method_label_snapshot')) {
                $table->string('payment_method_label_snapshot', 255)->nullable()->after('payment_method_id');
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Harden finalized/cancelled invoice line immutability:
        // hsn_code is part of historical truth and must not drift post-finalize.
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

    IF TG_OP = 'INSERT' OR TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    IF NEW.invoice_id            IS DISTINCT FROM OLD.invoice_id            THEN blocked_change := true; END IF;
    IF NEW.item_id               IS DISTINCT FROM OLD.item_id               THEN blocked_change := true; END IF;
    IF NEW.weight                IS DISTINCT FROM OLD.weight                THEN blocked_change := true; END IF;
    IF NEW.rate                  IS DISTINCT FROM OLD.rate                  THEN blocked_change := true; END IF;
    IF NEW.making_charges        IS DISTINCT FROM OLD.making_charges        THEN blocked_change := true; END IF;
    IF NEW.stone_amount          IS DISTINCT FROM OLD.stone_amount          THEN blocked_change := true; END IF;
    IF NEW.line_total            IS DISTINCT FROM OLD.line_total            THEN blocked_change := true; END IF;
    IF NEW.gst_rate              IS DISTINCT FROM OLD.gst_rate              THEN blocked_change := true; END IF;
    IF NEW.gst_amount            IS DISTINCT FROM OLD.gst_amount            THEN blocked_change := true; END IF;
    IF NEW.hsn_code              IS DISTINCT FROM OLD.hsn_code              THEN blocked_change := true; END IF;
    -- allocated_discount, allocated_round_off, allocated_loyalty_pts,
    -- returned_at, return_line_item_id, updated_at are intentionally mutable.

    IF blocked_change THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_payments', 'payment_method_label_snapshot')) {
                $table->dropColumn('payment_method_label_snapshot');
            }
        });

        Schema::table('karigar_payments', function (Blueprint $table) {
            if (Schema::hasColumn('karigar_payments', 'payment_method_label_snapshot')) {
                $table->dropColumn('payment_method_label_snapshot');
            }
        });

        Schema::table('quick_bill_payments', function (Blueprint $table) {
            if (Schema::hasColumn('quick_bill_payments', 'payment_method_label_snapshot')) {
                $table->dropColumn('payment_method_label_snapshot');
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Restore prior trigger shape (hsn_code mutable post-finalize).
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

    IF TG_OP = 'INSERT' OR TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    IF NEW.invoice_id            IS DISTINCT FROM OLD.invoice_id            THEN blocked_change := true; END IF;
    IF NEW.item_id               IS DISTINCT FROM OLD.item_id               THEN blocked_change := true; END IF;
    IF NEW.weight                IS DISTINCT FROM OLD.weight                THEN blocked_change := true; END IF;
    IF NEW.rate                  IS DISTINCT FROM OLD.rate                  THEN blocked_change := true; END IF;
    IF NEW.making_charges        IS DISTINCT FROM OLD.making_charges        THEN blocked_change := true; END IF;
    IF NEW.stone_amount          IS DISTINCT FROM OLD.stone_amount          THEN blocked_change := true; END IF;
    IF NEW.line_total            IS DISTINCT FROM OLD.line_total            THEN blocked_change := true; END IF;
    IF NEW.gst_rate              IS DISTINCT FROM OLD.gst_rate              THEN blocked_change := true; END IF;
    IF NEW.gst_amount            IS DISTINCT FROM OLD.gst_amount            THEN blocked_change := true; END IF;

    IF blocked_change THEN
        RAISE EXCEPTION 'Cannot mutate invoice_items for finalized/cancelled invoice';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
    }
};

