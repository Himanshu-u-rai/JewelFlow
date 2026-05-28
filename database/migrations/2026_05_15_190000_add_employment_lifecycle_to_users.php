<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add employment lifecycle tracking to the users table.
 *
 * Users are now permanent — never hard-deleted. Termination is modelled as
 * an employment event (employment_status = 'terminated', is_active = false)
 * rather than a database deletion. This preserves full attribution on
 * financial records (invoices, sales, audit logs) and enables explicit
 * re-hire flows via the Archived Staff tab.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employment_status', 20)
                ->default('active')
                ->after('is_active');

            $table->timestamp('terminated_at')->nullable()->after('employment_status');
            $table->foreignId('terminated_by_user_id')
                ->nullable()
                ->after('terminated_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('terminated_with_role_name', 100)
                ->nullable()
                ->after('terminated_by_user_id');

            $table->timestamp('reactivated_at')->nullable()->after('terminated_with_role_name');
            $table->foreignId('reactivated_by_user_id')
                ->nullable()
                ->after('reactivated_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('anonymized_at')->nullable()->after('reactivated_by_user_id');
        });

        // Add check constraint for employment_status via raw SQL (Blueprint has no ->check())
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_employment_status_check
            CHECK (employment_status IN ('active', 'suspended', 'terminated'))");

        // Index for the common staff listing query (shop_id + employment_status)
        Schema::table('users', function (Blueprint $table) {
            $table->index(['shop_id', 'employment_status'], 'users_shop_id_employment_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_shop_id_employment_status_index');
            $table->dropConstrainedForeignId('terminated_by_user_id');
            $table->dropConstrainedForeignId('reactivated_by_user_id');
            $table->dropColumn([
                'employment_status',
                'terminated_at',
                'terminated_with_role_name',
                'reactivated_at',
                'anonymized_at',
            ]);
        });

        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_employment_status_check');
    }
};
