<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS customers_phone_unique');
        }

        Schema::table('customers', function (Blueprint $table) {
            // Drop legacy columns if they exist
            if (Schema::hasColumn('customers', 'full_name_legacy')) {
                $table->dropColumn('full_name_legacy');
            }

            if (Schema::hasColumn('customers', 'phone_legacy')) {
                $table->dropColumn('phone_legacy');
            }

            // Ensure our real modern columns exist (safety net)
            if (!Schema::hasColumn('customers', 'first_name')) {
                $table->string('first_name')->nullable();
            }

            if (!Schema::hasColumn('customers', 'last_name')) {
                $table->string('last_name')->nullable();
            }

            if (!Schema::hasColumn('customers', 'mobile')) {
                $table->string('mobile')->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
