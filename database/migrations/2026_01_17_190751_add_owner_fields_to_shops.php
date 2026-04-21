<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'owner_first_name')) {
                $table->string('owner_first_name');
            }
            if (!Schema::hasColumn('shops', 'owner_last_name')) {
                $table->string('owner_last_name');
            }
            if (!Schema::hasColumn('shops', 'owner_mobile')) {
                $table->string('owner_mobile')->unique();
            }
            if (!Schema::hasColumn('shops', 'owner_email')) {
                $table->string('owner_email')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            //
        });
    }
};
