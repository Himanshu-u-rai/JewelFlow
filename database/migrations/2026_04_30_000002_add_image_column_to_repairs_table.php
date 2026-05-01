<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('repairs', 'image')) {
            Schema::table('repairs', function (Blueprint $table) {
                $table->string('image')->nullable()->after('image_path');
            });
        }

        DB::table('repairs')
            ->whereNull('image')
            ->whereNotNull('image_path')
            ->update(['image' => DB::raw('image_path')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('repairs', 'image')) {
            Schema::table('repairs', function (Blueprint $table) {
                $table->dropColumn('image');
            });
        }
    }
};

