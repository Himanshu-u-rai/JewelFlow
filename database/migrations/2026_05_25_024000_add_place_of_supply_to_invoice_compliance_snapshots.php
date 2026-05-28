<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoice_compliance_snapshots')) {
            return;
        }

        Schema::table('invoice_compliance_snapshots', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_compliance_snapshots', 'snapshot_place_of_supply_state_code')) {
                $table->char('snapshot_place_of_supply_state_code', 2)->nullable()
                    ->comment('State code frozen at snapshot time — NULL for pre-launch snapshots');
            }
            if (!Schema::hasColumn('invoice_compliance_snapshots', 'snapshot_buyer_gstin')) {
                $table->string('snapshot_buyer_gstin', 15)->nullable()
                    ->comment('GSTIN frozen at snapshot time — NULL means not captured');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('invoice_compliance_snapshots')) {
            return;
        }
        Schema::table('invoice_compliance_snapshots', function (Blueprint $table) {
            $table->dropColumn(['snapshot_place_of_supply_state_code', 'snapshot_buyer_gstin']);
        });
    }
};
