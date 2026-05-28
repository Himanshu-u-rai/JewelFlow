<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disposition is an EVENT, not a column. Each row records a decision: this
     * returned piece went to restock / melt / rework / written_off, by whom,
     * when, with what approval. Append-only — re-disposition appends a new row
     * (e.g. a restocked piece later found damaged → second row sending it to
     * melt). Cached pointer `items.return_disposition` is updated by trigger.
     */
    public function up(): void
    {
        Schema::create('returned_item_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('return_line_item_id')->constrained('return_line_items')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            // Where the piece went.
            $table->string('disposition', 32);

            // Routing references for melt / rework dispositions. Nullable —
            // restocked and written_off don't use these.
            $table->foreignId('target_lot_id')->nullable()->constrained('metal_lots')->nullOnDelete();
            $table->foreignId('target_job_order_id')->nullable()->constrained('job_orders')->nullOnDelete();

            $table->text('notes')->nullable();

            // Approval metadata. Phase 1 default: nothing requires approval.
            // Phase 3 wires per-shop policy thresholds.
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            // Who actioned the disposition.
            $table->foreignId('dispositioned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('dispositioned_at');

            // Phase 2+: photos / receipts. Kept as JSONB now so we don't need
            // a follow-up migration for storage. Empty JSON object by default.
            $table->jsonb('evidence_media')->default(DB::raw("'{}'::jsonb"));

            $table->timestamps();

            $table->index(['shop_id', 'return_line_item_id']);
            $table->index(['shop_id', 'item_id', 'created_at'], 'rid_item_latest_idx');
        });

        // Disposition CHECK — explicit allow-list.
        DB::statement(
            "ALTER TABLE returned_item_dispositions ADD CONSTRAINT returned_item_dispositions_disposition_check "
            . "CHECK (disposition IN ('restocked','sent_to_melt','sent_to_rework','written_off'))"
        );

        // Trigger: keep items.return_disposition synchronised with the latest
        // disposition row for that item. The cache is a query-speed shortcut
        // for the Returns Inbox filter; the events table is source of truth.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION update_item_return_disposition_cache() RETURNS trigger AS $$
BEGIN
    UPDATE items
       SET return_disposition = NEW.disposition,
           updated_at = now()
     WHERE id = NEW.item_id;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER update_item_return_disposition_cache_trigger
    AFTER INSERT ON returned_item_dispositions
    FOR EACH ROW EXECUTE FUNCTION update_item_return_disposition_cache();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS update_item_return_disposition_cache_trigger ON returned_item_dispositions');
        DB::statement('DROP FUNCTION IF EXISTS update_item_return_disposition_cache()');
        Schema::dropIfExists('returned_item_dispositions');
    }
};
