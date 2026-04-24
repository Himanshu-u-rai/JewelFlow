<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('metal_lots')) {
            return;
        }

        $violations = DB::select("
            SELECT id, fine_weight_total, fine_weight_remaining
            FROM metal_lots
            WHERE fine_weight_remaining < 0
               OR fine_weight_total < 0
            ORDER BY id
        ");

        if (!empty($violations)) {
            $rows = array_map(
                fn ($v) => ['id' => $v->id, 'fine_weight_total' => $v->fine_weight_total, 'fine_weight_remaining' => $v->fine_weight_remaining],
                $violations
            );

            throw new RuntimeException(
                'Cannot apply metal lot non-negative constraints. Violating rows: ' . json_encode($rows, JSON_UNESCAPED_SLASHES)
            );
        }

        if (DB::getDriverName() !== 'pgsql') {
            $this->createSqliteGuards();

            return;
        }

        DB::statement('ALTER TABLE metal_lots DROP CONSTRAINT IF EXISTS metal_lots_non_negative_remaining');
        DB::statement('ALTER TABLE metal_lots DROP CONSTRAINT IF EXISTS metal_lots_non_negative_total');
        DB::statement('ALTER TABLE metal_lots ADD CONSTRAINT metal_lots_non_negative_remaining CHECK (fine_weight_remaining >= 0)');
        DB::statement('ALTER TABLE metal_lots ADD CONSTRAINT metal_lots_non_negative_total CHECK (fine_weight_total >= 0)');
    }

    public function down(): void
    {
        if (!Schema::hasTable('metal_lots')) {
            return;
        }

        if (DB::getDriverName() !== 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS metal_lots_non_negative_insert');
            DB::statement('DROP TRIGGER IF EXISTS metal_lots_non_negative_update');

            return;
        }

        DB::statement('ALTER TABLE metal_lots DROP CONSTRAINT IF EXISTS metal_lots_non_negative_remaining');
        DB::statement('ALTER TABLE metal_lots DROP CONSTRAINT IF EXISTS metal_lots_non_negative_total');
    }

    private function createSqliteGuards(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS metal_lots_non_negative_insert');
        DB::statement('DROP TRIGGER IF EXISTS metal_lots_non_negative_update');

        DB::unprepared(<<<'SQL'
CREATE TRIGGER metal_lots_non_negative_insert
BEFORE INSERT ON metal_lots
FOR EACH ROW
WHEN NEW.fine_weight_remaining < 0 OR NEW.fine_weight_total < 0
BEGIN
    SELECT RAISE(ABORT, 'metal_lots balances cannot be negative');
END;
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER metal_lots_non_negative_update
BEFORE UPDATE ON metal_lots
FOR EACH ROW
WHEN NEW.fine_weight_remaining < 0 OR NEW.fine_weight_total < 0
BEGIN
    SELECT RAISE(ABORT, 'metal_lots balances cannot be negative');
END;
SQL);
    }
};
