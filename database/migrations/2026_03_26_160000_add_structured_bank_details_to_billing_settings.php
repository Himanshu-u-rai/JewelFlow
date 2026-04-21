<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            $table->string('bank_name', 100)->nullable()->after('bank_details');
            $table->string('bank_account_number', 30)->nullable()->after('bank_name');
            $table->string('bank_ifsc', 20)->nullable()->after('bank_account_number');
            $table->string('bank_account_type', 20)->nullable()->after('bank_ifsc');
            $table->string('bank_account_holder', 100)->nullable()->after('bank_account_type');
            $table->string('bank_branch', 100)->nullable()->after('bank_account_holder');
        });

        // Existing free-text bank_details is preserved as-is.
        // The invoice template falls back to bank_details when structured fields are empty,
        // so old data continues to display until the user fills in the structured form.
    }

    public function down(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'bank_name',
                'bank_account_number',
                'bank_ifsc',
                'bank_account_type',
                'bank_account_holder',
                'bank_branch',
            ]);
        });
    }
};
