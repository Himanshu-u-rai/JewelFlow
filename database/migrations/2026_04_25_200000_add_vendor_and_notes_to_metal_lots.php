<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metal_lots', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete()->after('shop_id');
            $table->string('metal_type', 20)->nullable()->after('vendor_id');
            $table->text('notes')->nullable()->after('cost_per_fine_gram');
        });
    }

    public function down(): void
    {
        Schema::table('metal_lots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn(['metal_type', 'notes']);
        });
    }
};
