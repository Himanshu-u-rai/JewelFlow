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
        Schema::table('users', function (Blueprint $table) {
            // Add new columns
            $table->string('first_name');
            $table->string('last_name')->nullable();

            // Make mobile compulsory (you already want this)
            $table->string('mobile_number')->nullable(false)->change();

            // Make role nullable (you said no role at registration)
            $table->string('role')->nullable()->change();
            // shop_id already exists, skip adding it
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            //
        });
    }
};
