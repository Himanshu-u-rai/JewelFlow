<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_sessions', function (Blueprint $table) {
            $table->timestamp('last_seen_at')->nullable()->after('mobile_connected_at');
            $table->timestamp('unpaired_at')->nullable()->after('last_seen_at');
            $table->string('unpaired_reason', 32)->nullable()->after('unpaired_at');
            $table->string('device_install_id', 64)->nullable()->after('unpaired_reason');

            $table->index(['shop_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::table('scan_sessions', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'last_seen_at']);
            $table->dropColumn(['last_seen_at', 'unpaired_at', 'unpaired_reason', 'device_install_id']);
        });
    }
};
