<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_events', function (Blueprint $table) {
            $table->foreignId('posted_by_user_id')
                  ->nullable()
                  ->after('barcode')
                  ->constrained('users')
                  ->nullOnDelete();

            $table->foreignId('posted_by_token_id')
                  ->nullable()
                  ->after('posted_by_user_id')
                  ->constrained('personal_access_tokens')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scan_events', function (Blueprint $table) {
            $table->dropForeign(['posted_by_user_id']);
            $table->dropForeign(['posted_by_token_id']);
            $table->dropColumn(['posted_by_user_id', 'posted_by_token_id']);
        });
    }
};
