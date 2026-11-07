<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-11-07: a lot must say which metal it is. Lots created without one showed in Vault Balances as
 * "Unknown material" and were refused by every gold-only step.
 *
 * Enforced on INSERT only. Older lots that already have no metal keep working (their weight still changes as they
 * are used); they are corrected separately, never guessed here. The existing CHECK (gold/silver or NULL) stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('metal_lots')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION metal_lots_require_metal_type() RETURNS trigger AS $$
BEGIN
    IF NEW.metal_type IS NULL THEN
        RAISE EXCEPTION 'metal_lots: a new lot must have a metal (gold or silver)';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS metal_lots_require_metal_type ON metal_lots;
CREATE TRIGGER metal_lots_require_metal_type BEFORE INSERT ON metal_lots
    FOR EACH ROW EXECUTE FUNCTION metal_lots_require_metal_type();
SQL);

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS metal_lots_require_metal_type');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER metal_lots_require_metal_type
BEFORE INSERT ON metal_lots
FOR EACH ROW
WHEN NEW.metal_type IS NULL
BEGIN
    SELECT RAISE(ABORT, 'metal_lots: a new lot must have a metal (gold or silver)');
END;
SQL);
    }

    public function down(): void
    {
        if (! Schema::hasTable('metal_lots')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS metal_lots_require_metal_type ON metal_lots; DROP FUNCTION IF EXISTS metal_lots_require_metal_type();');

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS metal_lots_require_metal_type');
    }
};
