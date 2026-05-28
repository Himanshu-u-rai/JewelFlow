<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_sessions', function (Blueprint $table) {
            $table->foreignId('created_by')
                  ->nullable()
                  ->after('shop_id')
                  ->constrained('users')
                  ->nullOnDelete();

            $table->foreignId('connected_user_id')
                  ->nullable()
                  ->after('created_by')
                  ->constrained('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scan_sessions', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropForeign(['connected_user_id']);
            $table->dropColumn(['created_by', 'connected_user_id']);
        });
    }
};
