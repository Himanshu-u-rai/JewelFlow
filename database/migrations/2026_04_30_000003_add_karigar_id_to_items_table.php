<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->unsignedBigInteger('karigar_id')->nullable()->after('vendor_id');
            $table->foreign('karigar_id')->references('id')->on('karigars')->nullOnDelete();
            $table->index(['shop_id', 'karigar_id']);
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropForeign(['karigar_id']);
            $table->dropIndex(['items_shop_id_karigar_id_index']);
            $table->dropColumn('karigar_id');
        });
    }
};
