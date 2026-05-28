<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'gstin')) {
                $table->string('gstin', 15)->nullable()->after('state_code');
            }
            if (!Schema::hasColumn('customers', 'customer_type')) {
                $table->string('customer_type', 10)->nullable()->after('gstin')
                    ->comment('b2b or b2c — for GSTR-1 classification');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['gstin', 'customer_type']);
        });
    }
};
