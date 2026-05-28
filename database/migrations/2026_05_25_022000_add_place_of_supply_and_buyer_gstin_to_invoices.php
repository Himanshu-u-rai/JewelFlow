<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'place_of_supply_state_code')) {
                $table->char('place_of_supply_state_code', 2)->nullable()->after('igst_amount')
                    ->comment('Indian state code at sale time — NULL for pre-launch invoices (intentional)');
            }
            if (!Schema::hasColumn('invoices', 'buyer_gstin')) {
                $table->string('buyer_gstin', 15)->nullable()->after('place_of_supply_state_code')
                    ->comment('Customer GSTIN at sale time — NULL means not captured');
            }
            if (!Schema::hasColumn('invoices', 'buyer_customer_type')) {
                $table->string('buyer_customer_type', 10)->nullable()->after('buyer_gstin')
                    ->comment('b2b or b2c at sale time — NULL means not captured');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['place_of_supply_state_code', 'buyer_gstin', 'buyer_customer_type']);
        });
    }
};
