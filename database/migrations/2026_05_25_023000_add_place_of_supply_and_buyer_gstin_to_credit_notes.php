<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            if (!Schema::hasColumn('credit_notes', 'place_of_supply_state_code')) {
                $table->char('place_of_supply_state_code', 2)->nullable()->after('igst_amount')
                    ->comment('Copied from original invoice at CN issuance time — NULL for legacy CNs');
            }
            if (!Schema::hasColumn('credit_notes', 'buyer_gstin')) {
                $table->string('buyer_gstin', 15)->nullable()->after('place_of_supply_state_code')
                    ->comment('Copied from original invoice at CN issuance time — NULL for legacy CNs');
            }
        });
    }

    public function down(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->dropColumn(['place_of_supply_state_code', 'buyer_gstin']);
        });
    }
};
