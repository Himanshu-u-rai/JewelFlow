<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->boolean('compliance_enabled')->default(true)->after('stock_value_display');
            $table->decimal('compliance_threshold', 14, 2)->default(200000.00)->after('compliance_enabled');
            $table->boolean('compliance_pan_mandatory')->default(true)->after('compliance_threshold');
            $table->boolean('compliance_mobile_mandatory')->default(true)->after('compliance_pan_mandatory');
            $table->boolean('compliance_address_mandatory')->default(true)->after('compliance_mobile_mandatory');
        });
    }

    public function down(): void
    {
        Schema::table('shop_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'compliance_enabled',
                'compliance_threshold',
                'compliance_pan_mandatory',
                'compliance_mobile_mandatory',
                'compliance_address_mandatory',
            ]);
        });
    }
};
