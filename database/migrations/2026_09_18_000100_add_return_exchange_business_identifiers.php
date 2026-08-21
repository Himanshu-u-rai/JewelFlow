<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Returns and exchanges were the last two customer-facing documents still
 * shown as "RO#{id}" / "Exchange #{id}" — a global row id that leaks how many
 * of them exist across every shop and renumbers if rows are ever migrated.
 *
 * Neither can borrow the invoice's number: a shop may raise several partial
 * returns against one invoice (the (shop_id, invoice_id) index is NOT unique),
 * and an exchange carries a nullable return_order_id AND a nullable
 * new_invoice_id, so a draft exchange would have nothing to render at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns();
        $this->backfill();
        $this->addConstraints();
        $this->seedShopCounters();

        if (DB::getDriverName() === 'pgsql') {
            $this->createTriggers();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS return_orders_business_identifier_trigger ON return_orders');
            DB::statement('DROP TRIGGER IF EXISTS exchange_orders_business_identifier_trigger ON exchange_orders');
            DB::statement('DROP FUNCTION IF EXISTS return_orders_business_identifier_assign()');
            DB::statement('DROP FUNCTION IF EXISTS exchange_orders_business_identifier_assign()');
        }

        Schema::table('return_orders', function (Blueprint $table): void {
            $table->dropUnique('return_orders_shop_return_number_unique');
            $table->dropColumn('return_number');
        });

        Schema::table('exchange_orders', function (Blueprint $table): void {
            $table->dropUnique('exchange_orders_shop_exchange_number_unique');
            $table->dropColumn('exchange_number');
        });

        DB::table('shop_counters')->whereIn('counter_key', ['return', 'exchange'])->delete();
    }

    private function addColumns(): void
    {
        Schema::table('return_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('return_orders', 'return_number')) {
                $table->unsignedBigInteger('return_number')->nullable()->after('id');
            }
        });

        Schema::table('exchange_orders', function (Blueprint $table): void {
            if (!Schema::hasColumn('exchange_orders', 'exchange_number')) {
                $table->unsignedBigInteger('exchange_number')->nullable()->after('id');
            }
        });
    }

    private function backfill(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->backfillGeneric();

            return;
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE return_orders IN ACCESS EXCLUSIVE MODE');
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           ROW_NUMBER() OVER (PARTITION BY shop_id ORDER BY created_at ASC, id ASC) AS seq
                    FROM return_orders
                )
                UPDATE return_orders r
                SET return_number = ranked.seq
                FROM ranked
                WHERE r.id = ranked.id
                  AND r.return_number IS NULL
            SQL);

            DB::statement('LOCK TABLE exchange_orders IN ACCESS EXCLUSIVE MODE');
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           ROW_NUMBER() OVER (PARTITION BY shop_id ORDER BY created_at ASC, id ASC) AS seq
                    FROM exchange_orders
                )
                UPDATE exchange_orders e
                SET exchange_number = ranked.seq
                FROM ranked
                WHERE e.id = ranked.id
                  AND e.exchange_number IS NULL
            SQL);
        });
    }

    private function backfillGeneric(): void
    {
        foreach (['return_orders' => 'return_number', 'exchange_orders' => 'exchange_number'] as $table => $column) {
            $seq = [];

            foreach (DB::table($table)->orderBy('shop_id')->orderBy('created_at')->orderBy('id')->get() as $row) {
                $seq[$row->shop_id] = ($seq[$row->shop_id] ?? 0) + 1;
                DB::table($table)->where('id', $row->id)->whereNull($column)->update([$column => $seq[$row->shop_id]]);
            }
        }
    }

    private function addConstraints(): void
    {
        foreach (['return_orders' => 'return_number', 'exchange_orders' => 'exchange_number'] as $table => $column) {
            $duplicates = DB::select("SELECT shop_id, {$column}, COUNT(*) c FROM {$table} GROUP BY shop_id, {$column} HAVING COUNT(*) > 1");

            if (!empty($duplicates)) {
                throw new \RuntimeException("Cannot enforce unique {$column}: duplicates found in {$table}.");
            }
        }

        $this->setColumnNotNull('return_orders', 'return_number');
        $this->setColumnNotNull('exchange_orders', 'exchange_number');

        Schema::table('return_orders', function (Blueprint $table): void {
            $table->unique(['shop_id', 'return_number'], 'return_orders_shop_return_number_unique');
        });

        Schema::table('exchange_orders', function (Blueprint $table): void {
            $table->unique(['shop_id', 'exchange_number'], 'exchange_orders_shop_exchange_number_unique');
        });
    }

    private function setColumnNotNull(string $table, string $column): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} SET NOT NULL");

            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->unsignedBigInteger($column)->nullable(false)->change();
        });
    }

    private function seedShopCounters(): void
    {
        foreach (DB::table('shops')->pluck('id') as $shopId) {
            $counters = [
                'return' => (int) DB::table('return_orders')->where('shop_id', $shopId)->max('return_number'),
                'exchange' => (int) DB::table('exchange_orders')->where('shop_id', $shopId)->max('exchange_number'),
            ];

            foreach ($counters as $key => $value) {
                DB::table('shop_counters')->updateOrInsert(
                    ['shop_id' => $shopId, 'counter_key' => $key],
                    ['current_value' => max(0, $value), 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    private function createTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION return_orders_business_identifier_assign() RETURNS trigger AS $$
BEGIN
    IF NEW.return_number IS NULL THEN
        NEW.return_number := next_shop_counter(NEW.shop_id, 'return');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER IF EXISTS return_orders_business_identifier_trigger ON return_orders');
        DB::statement('CREATE TRIGGER return_orders_business_identifier_trigger BEFORE INSERT ON return_orders FOR EACH ROW EXECUTE FUNCTION return_orders_business_identifier_assign()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION exchange_orders_business_identifier_assign() RETURNS trigger AS $$
BEGIN
    IF NEW.exchange_number IS NULL THEN
        NEW.exchange_number := next_shop_counter(NEW.shop_id, 'exchange');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER IF EXISTS exchange_orders_business_identifier_trigger ON exchange_orders');
        DB::statement('CREATE TRIGGER exchange_orders_business_identifier_trigger BEFORE INSERT ON exchange_orders FOR EACH ROW EXECUTE FUNCTION exchange_orders_business_identifier_assign()');
    }
};
