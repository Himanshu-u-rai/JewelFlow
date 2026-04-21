<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Migration logic commented out to avoid duplicate index error.
    }

    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['shop_id', 'mobile']);
            $table->dropColumn(['first_name', 'last_name']);
            // Optionally rename columns back if needed
            if (Schema::hasColumn('customers', 'full_name_legacy')) {
                $table->renameColumn('full_name_legacy', 'name');
            }
            if (Schema::hasColumn('customers', 'phone_legacy')) {
                $table->renameColumn('phone_legacy', 'phone');
            }
        });
    }
};
