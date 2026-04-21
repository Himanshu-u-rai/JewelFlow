<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'is_super_admin')) {
                $table->boolean('is_super_admin')->default(false)->after('role_id');
                $table->index('is_super_admin');
            }

            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_super_admin');
                $table->index('is_active');
            }
        });

        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('gst_rate');
                $table->index('is_active');
            }

            if (!Schema::hasColumn('shops', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_active')) {
                $table->dropIndex(['is_active']);
                $table->dropColumn('is_active');
            }

            if (Schema::hasColumn('users', 'is_super_admin')) {
                $table->dropIndex(['is_super_admin']);
                $table->dropColumn('is_super_admin');
            }
        });

        Schema::table('shops', function (Blueprint $table) {
            if (Schema::hasColumn('shops', 'deactivated_at')) {
                $table->dropColumn('deactivated_at');
            }

            if (Schema::hasColumn('shops', 'is_active')) {
                $table->dropIndex(['is_active']);
                $table->dropColumn('is_active');
            }
        });
    }
};
