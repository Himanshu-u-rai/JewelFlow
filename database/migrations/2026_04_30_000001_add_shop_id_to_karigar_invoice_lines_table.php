<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karigar_invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable()->after('id');
        });

        // Backfill from parent invoice (PostgreSQL UPDATE ... FROM syntax)
        DB::statement('
            UPDATE karigar_invoice_lines
            SET shop_id = karigar_invoices.shop_id
            FROM karigar_invoices
            WHERE karigar_invoices.id = karigar_invoice_lines.karigar_invoice_id
        ');

        Schema::table('karigar_invoice_lines', function (Blueprint $table) {
            $table->unsignedBigInteger('shop_id')->nullable(false)->change();
            $table->foreign('shop_id')->references('id')->on('shops')->cascadeOnDelete();
            $table->index('shop_id');
        });
    }

    public function down(): void
    {
        Schema::table('karigar_invoice_lines', function (Blueprint $table) {
            $table->dropForeign(['shop_id']);
            $table->dropIndex(['shop_id']);
            $table->dropColumn('shop_id');
        });
    }
};
