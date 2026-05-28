<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expand return_orders.return_type to include 'customer_exchange' — required
     * for the Phase 3 exchange flow which creates a ReturnOrder linked to an
     * ExchangeOrder.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE return_orders DROP CONSTRAINT return_orders_type_check');
        DB::statement(
            "ALTER TABLE return_orders ADD CONSTRAINT return_orders_type_check "
            . "CHECK (return_type IN ('customer_return','customer_exchange'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE return_orders DROP CONSTRAINT return_orders_type_check');
        DB::statement(
            "ALTER TABLE return_orders ADD CONSTRAINT return_orders_type_check "
            . "CHECK (return_type IN ('customer_return'))"
        );
    }
};
