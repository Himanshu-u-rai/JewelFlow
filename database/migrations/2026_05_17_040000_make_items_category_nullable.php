<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Karigar-received items enter Pending Stock with no category until the owner
            // assigns one via the release flow. NULL = "uncategorised, awaiting review".
            $table->string('category')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->string('category')->nullable(false)->change();
        });
    }
};
