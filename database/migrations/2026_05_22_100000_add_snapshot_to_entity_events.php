<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_events', function (Blueprint $table) {
            $table->jsonb('snapshot')->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('entity_events', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
