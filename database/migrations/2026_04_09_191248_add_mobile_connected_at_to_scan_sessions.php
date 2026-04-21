<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_sessions', function (Blueprint $table) {
            $table->timestamp('mobile_connected_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('scan_sessions', function (Blueprint $table) {
            $table->dropColumn('mobile_connected_at');
        });
    }
};
