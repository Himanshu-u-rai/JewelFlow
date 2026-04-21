<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('owner_first_name');
            $table->string('owner_last_name');
            $table->string('owner_mobile');
            $table->string('owner_email')->nullable();
        });
    }

    public function down()
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'owner_first_name',
                'owner_last_name',
                'owner_mobile',
                'owner_email'
            ]);
        });
    }
};
