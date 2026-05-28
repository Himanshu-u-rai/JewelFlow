<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('return_line_items', function (Blueprint $table) {
            $table->string('hsn_code', 20)->nullable()->after('refund_gst');
        });
    }

    public function down(): void
    {
        Schema::table('return_line_items', function (Blueprint $table) {
            $table->dropColumn('hsn_code');
        });
    }
};
