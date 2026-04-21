<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant-bound tables that must always carry shop ownership.
     *
     * @var array<int, string>
     */
    private array $tenantTables = [
        'customers',
        'items',
        'invoices',
        'repairs',
        'metal_lots',
        'metal_movements',
        'customer_gold_transactions',
        'cash_transactions',
        'categories',
        'sub_categories',
        'products',
        'audit_logs',
        'shop_rules',
        'shop_billing_settings',
        'shop_preferences',
        'roles',
    ];

    /**
     * Composite indexes required for common tenant query paths.
     *
     * @var array<string, array<int, array{name: string, columns: array<int, string>}>>
     */
    private array $compositeIndexes = [
        'customers' => [
            ['name' => 'customers_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
        ],
        'items' => [
            ['name' => 'items_shop_id_status_index', 'columns' => ['shop_id', 'status']],
            ['name' => 'items_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
        ],
        'invoices' => [
            ['name' => 'invoices_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
            ['name' => 'invoices_shop_id_status_index', 'columns' => ['shop_id', 'status']],
            ['name' => 'invoices_shop_id_customer_id_index', 'columns' => ['shop_id', 'customer_id']],
        ],
        'repairs' => [
            ['name' => 'repairs_shop_id_status_index', 'columns' => ['shop_id', 'status']],
            ['name' => 'repairs_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
        ],
        'metal_lots' => [
            ['name' => 'metal_lots_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
        ],
        'metal_movements' => [
            ['name' => 'metal_movements_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
            ['name' => 'metal_movements_shop_id_type_index', 'columns' => ['shop_id', 'type']],
        ],
        'customer_gold_transactions' => [
            ['name' => 'customer_gold_txn_shop_id_customer_id_index', 'columns' => ['shop_id', 'customer_id']],
            ['name' => 'customer_gold_txn_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
        ],
        'cash_transactions' => [
            ['name' => 'cash_txn_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
            ['name' => 'cash_txn_shop_id_type_created_at_index', 'columns' => ['shop_id', 'type', 'created_at']],
        ],
        'audit_logs' => [
            ['name' => 'audit_logs_shop_id_created_at_index', 'columns' => ['shop_id', 'created_at']],
            ['name' => 'audit_logs_shop_id_user_id_created_at_index', 'columns' => ['shop_id', 'user_id', 'created_at']],
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'shop_id')) {
                continue;
            }

            $nullCount = DB::table($table)->whereNull('shop_id')->count();
            if ($nullCount > 0) {
                throw new RuntimeException("Cannot harden {$table}.shop_id: {$nullCount} rows have NULL shop_id.");
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN shop_id SET NOT NULL");

            $indexName = "{$table}_shop_id_index";
            if (!$this->indexExists($table, $indexName)) {
                DB::statement("CREATE INDEX {$indexName} ON {$table} (shop_id)");
            }

            if (!$this->shopForeignKeyExists($table)) {
                $fkName = "{$table}_shop_id_foreign";
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$fkName} FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE CASCADE");
            }

            foreach ($this->compositeIndexes[$table] ?? [] as $index) {
                if (!$this->indexExists($table, $index['name'])) {
                    $columns = implode(', ', $index['columns']);
                    DB::statement("CREATE INDEX {$index['name']} ON {$table} ({$columns})");
                }
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tenantTables as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'shop_id')) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} ALTER COLUMN shop_id DROP NOT NULL");
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ? AND indexname = ?",
            [$table, $indexName]
        );
    }

    private function shopForeignKeyExists(string $table): bool
    {
        return (bool) DB::selectOne(
            "SELECT 1
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name
              AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name
              AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = current_schema()
               AND tc.table_name = ?
               AND kcu.column_name = 'shop_id'
               AND ccu.table_name = 'shops'
             LIMIT 1",
            [$table]
        );
    }
};
