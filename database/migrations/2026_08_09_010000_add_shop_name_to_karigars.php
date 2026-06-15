<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A karigar is usually a workshop (karkhana). 'name' is the karigar/person;
 * this adds the business/workshop name alongside it. Nullable + optional, so
 * existing karigars stay valid with it blank until edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('karigars', function (Blueprint $table) {
            if (! Schema::hasColumn('karigars', 'shop_name')) {
                $table->string('shop_name', 150)->nullable()->after('name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('karigars', function (Blueprint $table) {
            if (Schema::hasColumn('karigars', 'shop_name')) {
                $table->dropColumn('shop_name');
            }
        });
    }
};
