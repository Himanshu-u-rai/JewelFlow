<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('shop_billing_settings', 'digital_signature_path')) {
                $table->string('digital_signature_path')->nullable()->after('upi_id');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'show_digital_signature')) {
                $table->boolean('show_digital_signature')->default(false)->after('digital_signature_path');
            }
            if (!Schema::hasColumn('shop_billing_settings', 'show_bis_logo')) {
                $table->boolean('show_bis_logo')->default(false)->after('show_digital_signature');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shop_billing_settings', function (Blueprint $table) {
            $table->dropColumn(['digital_signature_path', 'show_digital_signature', 'show_bis_logo']);
        });
    }
};
