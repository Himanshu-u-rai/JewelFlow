<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Year-end snapshots (owner-approved 2026-11-16): a frozen copy of what the shop held on 31 March, valued at the
 * lower of cost and closing rate (AS-2 / ICDS-IV). Both tables are append-only through the shared
 * prevent_ledger_mutation() function; a new count is a new version, never an edit.
 *
 * CONSTITUTION §4 rule 2: pre-validation stops the migration if the shared function is missing. No trigger is disabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::selectOne("SELECT 1 AS ok FROM pg_proc WHERE proname = 'prevent_ledger_mutation'")) {
            throw new RuntimeException('prevent_ledger_mutation() is missing; cannot create the snapshot append-only triggers.');
        }

        Schema::create('fiscal_year_snapshots', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shop_id');       // no FK: a snapshot is history and outlives anything else
            $table->string('fiscal_year', 7);
            $table->unsignedInteger('version');
            $table->string('kind', 12);
            $table->date('as_at_date');
            $table->timestamp('taken_at');
            $table->unsignedBigInteger('taken_by')->nullable();
            $table->jsonb('totals');
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['shop_id', 'fiscal_year', 'version']);
        });
        DB::statement("ALTER TABLE fiscal_year_snapshots ADD CONSTRAINT fiscal_year_snapshots_kind_check CHECK (kind IN ('automatic','closing'))");

        Schema::create('fiscal_year_snapshot_items', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('snapshot_id')->index();
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('item_id');
            $table->string('barcode', 100)->nullable();
            $table->string('design')->nullable();
            $table->string('category')->nullable();
            $table->string('metal_type', 30)->nullable();
            $table->decimal('purity', 8, 3)->nullable();
            $table->decimal('gross_weight', 18, 6)->nullable();
            $table->decimal('net_metal_weight', 18, 6)->nullable();
            $table->decimal('acquisition_cost', 18, 2)->nullable();
            $table->string('acquisition_cost_source', 20)->nullable();
            $table->decimal('closing_rate_per_gram', 18, 4)->nullable();
            // The business date of that rate: 31 March, or the last day before it the shop entered rates.
            $table->date('closing_rate_date')->nullable();
            $table->decimal('value_at_closing_rate', 18, 2)->nullable();
            $table->decimal('valuation', 18, 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign('snapshot_id')->references('id')->on('fiscal_year_snapshots');
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fiscal_year_snapshots_append_only_trigger ON fiscal_year_snapshots;
            CREATE TRIGGER fiscal_year_snapshots_append_only_trigger BEFORE UPDATE OR DELETE ON fiscal_year_snapshots
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation();
            DROP TRIGGER IF EXISTS fiscal_year_snapshot_items_append_only_trigger ON fiscal_year_snapshot_items;
            CREATE TRIGGER fiscal_year_snapshot_items_append_only_trigger BEFORE UPDATE OR DELETE ON fiscal_year_snapshot_items
            FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation();
        SQL);
    }

    public function down(): void
    {
        // Dev rollback only; production never rolls back a constitutional trigger.
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fiscal_year_snapshot_items_append_only_trigger ON fiscal_year_snapshot_items;
            DROP TRIGGER IF EXISTS fiscal_year_snapshots_append_only_trigger ON fiscal_year_snapshots;
        SQL);
        Schema::dropIfExists('fiscal_year_snapshot_items');
        Schema::dropIfExists('fiscal_year_snapshots');
    }
};
