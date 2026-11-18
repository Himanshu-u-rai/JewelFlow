<?php

use App\Services\StockHistoryBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Financial-year stock (owner-approved 2026-11-16).
 *
 *  1. items.acquisition_cost / acquisition_cost_source: the real cost of a piece, set once at intake. cost_price
 *     stays the current replacement cost that daily-rate saves overwrite. A trigger refuses any change to a set
 *     acquisition_cost. Unknown costs stay NULL (CONSTITUTION §5): they are never guessed.
 *  2. item_movements: append-only history of every item's status, written by triggers on items so no code path or raw
 *     SQL can change stock unrecorded. StockAsAt rebuilds "stock on 31 March" from it.
 *
 * CONSTITUTION §4 rule 2: pre-validation below stops the migration if the shared append-only function is missing or
 * any item has an unusable status. No trigger is disabled (Article IX.B).
 */
return new class extends Migration
{
    public function up(): void
    {
        $fn = DB::selectOne("SELECT 1 AS ok FROM pg_proc WHERE proname = 'prevent_ledger_mutation'");
        if (! $fn) {
            throw new RuntimeException('prevent_ledger_mutation() is missing; cannot create the item_movements append-only trigger.');
        }
        $badStatus = DB::table('items')->whereNull('status')->count();
        if ($badStatus > 0) {
            throw new RuntimeException("{$badStatus} items have a NULL status; fix them before the stock history is backfilled.");
        }

        Schema::table('items', function ($table) {
            $table->decimal('acquisition_cost', 18, 2)->nullable();
            $table->string('acquisition_cost_source', 20)->nullable();
        });
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_acquisition_cost_source_check CHECK (acquisition_cost_source IS NULL OR acquisition_cost_source IN ('purchase','entered','opening','import','unknown'))");

        // Backfill BEFORE the guard exists would also be fine, but the guard allows NULL -> value once, so order is free.
        StockHistoryBackfill::acquisitionCosts();

        Schema::create('item_movements', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shop_id')->index();
            $table->unsignedBigInteger('item_id');      // no FK: history must outlive a deleted item
            $table->string('movement_type', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->decimal('gross_weight', 18, 6)->nullable();
            $table->decimal('net_metal_weight', 18, 6)->nullable();
            $table->decimal('purity', 5, 2)->nullable();
            $table->string('metal_type', 30)->nullable();
            $table->decimal('acquisition_cost', 18, 2)->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->timestamp('occurred_at');           // IST business wall-clock time
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->boolean('is_backfilled')->default(false);
            $table->index(['shop_id', 'occurred_at']);
            $table->index(['item_id', 'occurred_at']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION item_movement_type(p_from text, p_to text) RETURNS text AS $$
            BEGIN
                RETURN CASE
                    WHEN p_to IS NULL THEN 'deleted'
                    WHEN p_from IS NULL THEN 'intake'
                    WHEN p_to = 'sold' THEN 'sold'
                    WHEN p_to = 'in_stock' THEN 'returned_to_stock'
                    WHEN p_to IN ('melted', 'transferred', 'reversed', 'returned') THEN p_to
                    ELSE 'status_change'
                END;
            END;
            $$ LANGUAGE plpgsql IMMUTABLE;
        SQL);

        StockHistoryBackfill::movements();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION items_acquisition_cost_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.acquisition_cost_source IS NULL THEN
                        NEW.acquisition_cost_source := CASE WHEN NEW.acquisition_cost IS NULL THEN 'unknown' ELSE 'entered' END;
                    END IF;
                    IF NEW.acquisition_cost IS NOT NULL AND NEW.acquisition_cost_source = 'unknown' THEN
                        RAISE EXCEPTION 'items.acquisition_cost needs a real source, not unknown';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.acquisition_cost IS NOT NULL
                   AND (NEW.acquisition_cost IS DISTINCT FROM OLD.acquisition_cost
                        OR NEW.acquisition_cost_source IS DISTINCT FROM OLD.acquisition_cost_source) THEN
                    RAISE EXCEPTION 'items.acquisition_cost is immutable once set (item %)', OLD.id;
                END IF;
                IF OLD.acquisition_cost IS NULL AND NEW.acquisition_cost IS NOT NULL
                   AND COALESCE(NEW.acquisition_cost_source, 'unknown') = 'unknown' THEN
                    RAISE EXCEPTION 'items.acquisition_cost needs a real source, not unknown (item %)', OLD.id;
                END IF;
                IF NEW.acquisition_cost IS NULL AND NEW.acquisition_cost_source IS NULL THEN
                    NEW.acquisition_cost_source := 'unknown';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS items_acquisition_cost_guard_trigger ON items;
            CREATE TRIGGER items_acquisition_cost_guard_trigger
            BEFORE INSERT OR UPDATE OF acquisition_cost, acquisition_cost_source ON items
            FOR EACH ROW EXECUTE FUNCTION items_acquisition_cost_guard();

            CREATE OR REPLACE FUNCTION items_record_movement() RETURNS trigger AS $$
            DECLARE
                r items%ROWTYPE;
                v_from text;
                v_to text;
                v_when timestamp;
                v_ref_type text;
                v_ref_id bigint;
                v_user bigint;
            BEGIN
                v_user := NULLIF(current_setting('jewelflow.user_id', true), '')::bigint;
                IF TG_OP = 'DELETE' THEN
                    r := OLD; v_from := OLD.status; v_to := NULL;
                    v_when := now() AT TIME ZONE 'Asia/Kolkata';
                ELSIF TG_OP = 'INSERT' THEN
                    r := NEW; v_from := NULL; v_to := NEW.status;
                    v_when := COALESCE(NEW.created_at, now() AT TIME ZONE 'Asia/Kolkata');
                    IF NEW.stock_purchase_id IS NOT NULL THEN
                        v_ref_type := 'stock_purchase'; v_ref_id := NEW.stock_purchase_id;
                    ELSIF NEW.job_order_id IS NOT NULL THEN
                        v_ref_type := 'job_order'; v_ref_id := NEW.job_order_id;
                    END IF;
                ELSE
                    r := NEW; v_from := OLD.status; v_to := NEW.status;
                    v_when := CASE WHEN NEW.updated_at IS DISTINCT FROM OLD.updated_at AND NEW.updated_at IS NOT NULL
                                   THEN NEW.updated_at ELSE now() AT TIME ZONE 'Asia/Kolkata' END;
                END IF;

                INSERT INTO item_movements (shop_id, item_id, movement_type, from_status, to_status, gross_weight,
                    net_metal_weight, purity, metal_type, acquisition_cost, reference_type, reference_id, occurred_at, created_by)
                VALUES (r.shop_id, r.id, item_movement_type(v_from, v_to), v_from, v_to, r.gross_weight, r.net_metal_weight,
                    r.purity, r.metal_type, r.acquisition_cost, v_ref_type, v_ref_id, v_when, v_user);
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS items_intake_movement_trigger ON items;
            CREATE TRIGGER items_intake_movement_trigger AFTER INSERT ON items
            FOR EACH ROW EXECUTE FUNCTION items_record_movement();

            DROP TRIGGER IF EXISTS items_status_movement_trigger ON items;
            CREATE TRIGGER items_status_movement_trigger AFTER UPDATE OF status ON items
            FOR EACH ROW WHEN (OLD.status IS DISTINCT FROM NEW.status)
            EXECUTE FUNCTION items_record_movement();

            DROP TRIGGER IF EXISTS items_delete_movement_trigger ON items;
            CREATE TRIGGER items_delete_movement_trigger AFTER DELETE ON items
            FOR EACH ROW EXECUTE FUNCTION items_record_movement();

            DROP TRIGGER IF EXISTS item_movements_append_only_trigger ON item_movements;
            CREATE TRIGGER item_movements_append_only_trigger BEFORE UPDATE OR DELETE ON item_movements
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation();
        SQL);
    }

    public function down(): void
    {
        // Removing constitutional triggers on rollback is deliberate only in dev; production never rolls this back.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS items_delete_movement_trigger ON items;
            DROP TRIGGER IF EXISTS items_status_movement_trigger ON items;
            DROP TRIGGER IF EXISTS items_intake_movement_trigger ON items;
            DROP TRIGGER IF EXISTS items_acquisition_cost_guard_trigger ON items;
            DROP FUNCTION IF EXISTS items_record_movement();
            DROP FUNCTION IF EXISTS items_acquisition_cost_guard();
        SQL);
        Schema::dropIfExists('item_movements');
        DB::unprepared('DROP FUNCTION IF EXISTS item_movement_type(text, text)');
        DB::statement('ALTER TABLE items DROP CONSTRAINT IF EXISTS items_acquisition_cost_source_check');
        Schema::table('items', function ($table) {
            $table->dropColumn(['acquisition_cost', 'acquisition_cost_source']);
        });
    }
};
