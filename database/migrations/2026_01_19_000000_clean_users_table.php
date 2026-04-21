<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Drop only columns that exist to avoid errors on fresh installs
        $cols = ['name', 'email', 'role', 'first_name', 'last_name'];
        foreach ($cols as $col) {
            if (Schema::hasColumn('users', $col)) {
                Schema::table('users', function (Blueprint $table) use ($col) {
                    $table->dropColumn($col);
                });
            }
        }
    }

    public function down()
    {
        // Intentionally left empty — we are not preserving legacy structure
    }
};
