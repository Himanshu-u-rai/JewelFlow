<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('
            CREATE INDEX IF NOT EXISTS metal_movements_lot_lookup
            ON metal_movements (shop_id, from_lot_id, to_lot_id)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS metal_movements_lot_lookup');
    }
};
