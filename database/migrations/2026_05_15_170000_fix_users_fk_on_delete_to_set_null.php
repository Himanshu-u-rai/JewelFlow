<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring five outlier foreign keys on users.id in line with the rest of the
 * schema. Most FKs to users.id already use ON DELETE SET NULL — these five
 * were created with NO ACTION / RESTRICT and silently blocked staff deletion
 * for any user who had ever performed an auditable action.
 *
 * After this migration: deleting a user nulls out their reference in these
 * historical tables (the actor name in audit_logs.description and similar
 * descriptive columns remains intact).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign('audit_logs_user_id_foreign');
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropForeign('cash_transactions_user_id_foreign');
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('imports', function (Blueprint $table) {
            $table->dropForeign('imports_created_by_foreign');
            $table->unsignedBigInteger('created_by')->nullable()->change();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('platform_impersonation_sessions', function (Blueprint $table) {
            $table->dropForeign('platform_impersonation_sessions_user_id_foreign');
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->dropForeign('stock_purchases_entered_by_user_id_foreign');
            $table->unsignedBigInteger('entered_by_user_id')->nullable()->change();
            $table->foreign('entered_by_user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Revert FK delete rules. Note: down() will fail if any of these
        // columns currently contain NULL (i.e. if a staff deletion has
        // already happened after up() ran).
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropForeign('audit_logs_user_id_foreign');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropForeign('cash_transactions_user_id_foreign');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users');
        });

        Schema::table('imports', function (Blueprint $table) {
            $table->dropForeign('imports_created_by_foreign');
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('platform_impersonation_sessions', function (Blueprint $table) {
            $table->dropForeign('platform_impersonation_sessions_user_id_foreign');
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('stock_purchases', function (Blueprint $table) {
            $table->dropForeign('stock_purchases_entered_by_user_id_foreign');
            $table->unsignedBigInteger('entered_by_user_id')->nullable(false)->change();
            $table->foreign('entered_by_user_id')->references('id')->on('users');
        });
    }
};
