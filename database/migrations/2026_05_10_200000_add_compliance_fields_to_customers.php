<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('compliance_verified_at')->nullable()->after('id_number');
            $table->foreignId('compliance_verified_by')->nullable()->after('compliance_verified_at')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('consent_given_at')->nullable()->after('compliance_verified_by');
            $table->foreignId('consent_given_by')->nullable()->after('consent_given_at')
                ->constrained('users')->nullOnDelete();

            $table->index(['shop_id', 'compliance_verified_at'], 'customers_compliance_verified_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_compliance_verified_idx');
            $table->dropConstrainedForeignId('consent_given_by');
            $table->dropColumn('consent_given_at');
            $table->dropConstrainedForeignId('compliance_verified_by');
            $table->dropColumn('compliance_verified_at');
        });
    }
};
