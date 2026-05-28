<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT items_status_check');
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_status_check CHECK (status IN ('in_stock','sold','returned','melted','transferred','reversed'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE items DROP CONSTRAINT items_status_check');
        DB::statement("ALTER TABLE items ADD CONSTRAINT items_status_check CHECK (status IN ('in_stock','sold','returned','melted','transferred'))");
    }
};
