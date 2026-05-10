<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('metal_lots', function (Blueprint $table) {
            $table->smallInteger('iso_year')->unsigned()->nullable()->after('notes');
            $table->tinyInteger('iso_week')->unsigned()->nullable()->after('iso_year');
            $table->boolean('is_dispatched')->default(false)->after('iso_week');
            $table->timestamp('dispatched_at')->nullable()->after('is_dispatched');
            $table->string('dispatch_notes', 500)->nullable()->after('dispatched_at');

            // Race-safe unique index: only one weekly lot per shop+metal+week
            $table->unique(['shop_id', 'source', 'iso_year', 'iso_week'], 'metal_lots_weekly_unique');
        });

        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->foreignId('weekly_lot_id')
                ->nullable()
                ->after('invoice_id')
                ->constrained('metal_lots')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('weekly_lot_id');
        });

        Schema::table('metal_lots', function (Blueprint $table) {
            $table->dropUnique('metal_lots_weekly_unique');
            $table->dropColumn(['iso_year', 'iso_week', 'is_dispatched', 'dispatched_at', 'dispatch_notes']);
        });
    }
};
