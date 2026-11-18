<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner "Not now" on the dashboard lock reminder: that reminder stays hidden until the month ends (per shop). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->string('lock_reminder_dismissed_key', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn('lock_reminder_dismissed_key');
        });
    }
};
