<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repair intake challan: what the customer handed over, recorded digitally.
 * Additive and nullable: existing repairs keep NULL (nothing is backfilled or
 * invented for them). intake_snapshot is written once, when a repair is
 * received, so a later reprint can show intake details apart from later edits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->unsignedSmallInteger('item_quantity')->nullable();
            $table->text('intake_condition')->nullable();
            $table->jsonb('intake_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            $table->dropColumn(['item_quantity', 'intake_condition', 'intake_snapshot']);
        });
    }
};
