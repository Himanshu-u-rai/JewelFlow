<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'name')) {
                $table->string('name')->nullable()->after('id');
            }

            if (!Schema::hasColumn('users', 'email')) {
                $table->string('email')->nullable()->after('mobile_number');
            }

            if (!Schema::hasColumn('users', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('users', 'name')) {
                $columns[] = 'name';
            }
            if (Schema::hasColumn('users', 'email')) {
                $columns[] = 'email';
            }
            if (Schema::hasColumn('users', 'email_verified_at')) {
                $columns[] = 'email_verified_at';
            }

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
