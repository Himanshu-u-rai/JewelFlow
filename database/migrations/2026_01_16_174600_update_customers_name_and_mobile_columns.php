<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            // Add new columns if they don't exist
            if (!Schema::hasColumn('customers', 'first_name')) {
                $table->string('first_name')->nullable();
            }

            if (!Schema::hasColumn('customers', 'last_name')) {
                $table->string('last_name')->nullable();
            }

            if (!Schema::hasColumn('customers', 'mobile')) {
                $table->string('mobile')->nullable();
            }

            // Rename legacy columns only if they exist
            if (Schema::hasColumn('customers', 'name')) {
                $table->renameColumn('name', 'full_name_legacy');
            }

            if (Schema::hasColumn('customers', 'phone')) {
                $table->renameColumn('phone', 'phone_legacy');
            }
        });

        // Add composite unique index ONLY if it does not already exist
        $indexName = 'customers_shop_id_mobile_unique';

        $driver = DB::getDriverName();
        $indexExists = false;

        if ($driver === 'pgsql') {
            $indexExists = DB::selectOne(
                "SELECT 1 FROM pg_indexes WHERE indexname = ?",
                [$indexName]
            ) !== null;
        } elseif ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('customers')");
            foreach ($indexes as $index) {
                if (isset($index->name) && $index->name === $indexName) {
                    $indexExists = true;
                    break;
                }
            }
        } else {
            // MySQL/MariaDB fallback
            $indexExists = !empty(DB::select(
                "SHOW INDEX FROM customers WHERE Key_name = ?",
                [$indexName]
            ));
        }

        if (!$indexExists) {
            Schema::table('customers', function (Blueprint $table) use ($indexName) {
                $table->unique(['shop_id', 'mobile'], $indexName);
            });
        }
    }
};
