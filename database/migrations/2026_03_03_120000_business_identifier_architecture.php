<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropGlobalInvoiceUniqueConstraint();
        $this->createShopCountersTable();
        $this->addBusinessIdentifierColumns();
        $this->backfillBusinessIdentifiers();
        $this->addBusinessIdentifierConstraints();
        $this->seedShopCounters();
        $this->enforceUsersShopIdNotNull();

        if (DB::getDriverName() === 'pgsql') {
            $this->createCounterFunctionAndTriggers();
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS imports_business_identifier_trigger ON imports');
            DB::statement('DROP TRIGGER IF EXISTS customers_business_identifier_trigger ON customers');
            DB::statement('DROP TRIGGER IF EXISTS repairs_business_identifier_trigger ON repairs');
            DB::statement('DROP TRIGGER IF EXISTS metal_lots_business_identifier_trigger ON metal_lots');
            DB::statement('DROP TRIGGER IF EXISTS invoices_numbering_guard_trigger ON invoices');

            DB::statement('DROP FUNCTION IF EXISTS imports_business_identifier_assign()');
            DB::statement('DROP FUNCTION IF EXISTS customers_business_identifier_assign()');
            DB::statement('DROP FUNCTION IF EXISTS repairs_business_identifier_assign()');
            DB::statement('DROP FUNCTION IF EXISTS metal_lots_business_identifier_assign()');
            DB::statement('DROP FUNCTION IF EXISTS invoices_numbering_guard()');
            DB::statement('DROP FUNCTION IF EXISTS next_shop_counter(bigint, text)');
        }

        Schema::table('imports', function (Blueprint $table): void {
            if (Schema::hasColumn('imports', 'import_reference')) {
                $table->dropUnique('imports_shop_import_reference_unique');
                $table->dropColumn('import_reference');
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (Schema::hasColumn('customers', 'customer_code')) {
                $table->dropUnique('customers_shop_customer_code_unique');
                $table->dropColumn('customer_code');
            }
        });

        Schema::table('repairs', function (Blueprint $table): void {
            if (Schema::hasColumn('repairs', 'repair_number')) {
                $table->dropUnique('repairs_shop_repair_number_unique');
                $table->dropColumn('repair_number');
            }
        });

        Schema::table('metal_lots', function (Blueprint $table): void {
            if (Schema::hasColumn('metal_lots', 'lot_number')) {
                $table->dropUnique('metal_lots_shop_lot_number_unique');
                $table->dropColumn('lot_number');
            }
        });

        Schema::dropIfExists('shop_counters');

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('shop_id')->nullable()->change();
        });

        if (Schema::hasTable('invoices')) {
            $duplicates = DB::select(<<<'SQL'
                SELECT invoice_number, COUNT(*) AS c
                FROM invoices
                GROUP BY invoice_number
                HAVING COUNT(*) > 1
            SQL);

            if (!empty($duplicates)) {
                throw new \RuntimeException('Cannot restore global invoices.invoice_number unique constraint. Duplicate invoice_number values exist across shops.');
            }

            Schema::table('invoices', function (Blueprint $table): void {
                $table->unique('invoice_number', 'invoices_invoice_number_unique');
            });
        }
    }

    private function dropGlobalInvoiceUniqueConstraint(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_invoice_number_unique');
            DB::statement('DROP INDEX IF EXISTS invoices_invoice_number_unique');

            return;
        }

        try {
            Schema::table('invoices', function (Blueprint $table): void {
                $table->dropUnique('invoices_invoice_number_unique');
            });
        } catch (\Throwable) {
            // Constraint may already be absent on non-pg drivers.
        }
    }

    private function createShopCountersTable(): void
    {
        if (Schema::hasTable('shop_counters')) {
            return;
        }

        Schema::create('shop_counters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shop_id')->constrained('shops')->restrictOnDelete();
            $table->string('counter_key', 40);
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();

            $table->unique(['shop_id', 'counter_key'], 'shop_counters_shop_key_unique');
        });
    }

    private function addBusinessIdentifierColumns(): void
    {
        Schema::table('metal_lots', function (Blueprint $table): void {
            if (!Schema::hasColumn('metal_lots', 'lot_number')) {
                $table->unsignedBigInteger('lot_number')->nullable()->after('id');
            }
        });

        Schema::table('repairs', function (Blueprint $table): void {
            if (!Schema::hasColumn('repairs', 'repair_number')) {
                $table->unsignedBigInteger('repair_number')->nullable()->after('id');
            }
        });

        Schema::table('customers', function (Blueprint $table): void {
            if (!Schema::hasColumn('customers', 'customer_code')) {
                $table->unsignedBigInteger('customer_code')->nullable()->after('id');
            }
        });

        Schema::table('imports', function (Blueprint $table): void {
            if (!Schema::hasColumn('imports', 'import_reference')) {
                $table->string('import_reference', 50)->nullable()->after('id');
            }
        });
    }

    private function backfillBusinessIdentifiers(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->backfillBusinessIdentifiersGeneric();
            return;
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE metal_lots IN ACCESS EXCLUSIVE MODE');
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           ROW_NUMBER() OVER (PARTITION BY shop_id ORDER BY created_at ASC, id ASC) AS seq
                    FROM metal_lots
                )
                UPDATE metal_lots m
                SET lot_number = ranked.seq
                FROM ranked
                WHERE m.id = ranked.id
                  AND m.lot_number IS NULL
            SQL);

            DB::statement('LOCK TABLE repairs IN ACCESS EXCLUSIVE MODE');
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           ROW_NUMBER() OVER (PARTITION BY shop_id ORDER BY created_at ASC, id ASC) AS seq
                    FROM repairs
                )
                UPDATE repairs r
                SET repair_number = ranked.seq
                FROM ranked
                WHERE r.id = ranked.id
                  AND r.repair_number IS NULL
            SQL);

            DB::statement('LOCK TABLE customers IN ACCESS EXCLUSIVE MODE');
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           ROW_NUMBER() OVER (PARTITION BY shop_id ORDER BY created_at ASC, id ASC) AS seq
                    FROM customers
                )
                UPDATE customers c
                SET customer_code = ranked.seq
                FROM ranked
                WHERE c.id = ranked.id
                  AND c.customer_code IS NULL
            SQL);

            DB::statement('LOCK TABLE imports IN ACCESS EXCLUSIVE MODE');
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           shop_id,
                           ROW_NUMBER() OVER (PARTITION BY shop_id ORDER BY created_at ASC, id ASC) AS seq
                    FROM imports
                )
                UPDATE imports i
                SET import_reference = 'IMP-' || lpad(ranked.seq::text, 6, '0')
                FROM ranked
                WHERE i.id = ranked.id
                  AND i.import_reference IS NULL
            SQL);
        });
    }

    private function backfillBusinessIdentifiersGeneric(): void
    {
        foreach (DB::table('metal_lots')->orderBy('shop_id')->orderBy('created_at')->orderBy('id')->get() as $row) {
            static $lotSeq = [];
            $lotSeq[$row->shop_id] = ($lotSeq[$row->shop_id] ?? 0) + 1;
            DB::table('metal_lots')->where('id', $row->id)->whereNull('lot_number')->update(['lot_number' => $lotSeq[$row->shop_id]]);
        }

        foreach (DB::table('repairs')->orderBy('shop_id')->orderBy('created_at')->orderBy('id')->get() as $row) {
            static $repairSeq = [];
            $repairSeq[$row->shop_id] = ($repairSeq[$row->shop_id] ?? 0) + 1;
            DB::table('repairs')->where('id', $row->id)->whereNull('repair_number')->update(['repair_number' => $repairSeq[$row->shop_id]]);
        }

        foreach (DB::table('customers')->orderBy('shop_id')->orderBy('created_at')->orderBy('id')->get() as $row) {
            static $customerSeq = [];
            $customerSeq[$row->shop_id] = ($customerSeq[$row->shop_id] ?? 0) + 1;
            DB::table('customers')->where('id', $row->id)->whereNull('customer_code')->update(['customer_code' => $customerSeq[$row->shop_id]]);
        }

        foreach (DB::table('imports')->orderBy('shop_id')->orderBy('created_at')->orderBy('id')->get() as $row) {
            static $importSeq = [];
            $importSeq[$row->shop_id] = ($importSeq[$row->shop_id] ?? 0) + 1;
            DB::table('imports')->where('id', $row->id)->whereNull('import_reference')->update([
                'import_reference' => 'IMP-' . str_pad((string) $importSeq[$row->shop_id], 6, '0', STR_PAD_LEFT),
            ]);
        }
    }

    private function addBusinessIdentifierConstraints(): void
    {
        $lotDupes = DB::select('SELECT shop_id, lot_number, COUNT(*) c FROM metal_lots GROUP BY shop_id, lot_number HAVING COUNT(*) > 1');
        if (!empty($lotDupes)) {
            throw new \RuntimeException('Cannot enforce unique lot_number: duplicates found.');
        }

        $repairDupes = DB::select('SELECT shop_id, repair_number, COUNT(*) c FROM repairs GROUP BY shop_id, repair_number HAVING COUNT(*) > 1');
        if (!empty($repairDupes)) {
            throw new \RuntimeException('Cannot enforce unique repair_number: duplicates found.');
        }

        $customerDupes = DB::select('SELECT shop_id, customer_code, COUNT(*) c FROM customers GROUP BY shop_id, customer_code HAVING COUNT(*) > 1');
        if (!empty($customerDupes)) {
            throw new \RuntimeException('Cannot enforce unique customer_code: duplicates found.');
        }

        $importDupes = DB::select('SELECT shop_id, import_reference, COUNT(*) c FROM imports GROUP BY shop_id, import_reference HAVING COUNT(*) > 1');
        if (!empty($importDupes)) {
            throw new \RuntimeException('Cannot enforce unique import_reference: duplicates found.');
        }

        $this->setColumnNotNull('metal_lots', 'lot_number', 'unsignedBigInteger');
        $this->setColumnNotNull('repairs', 'repair_number', 'unsignedBigInteger');
        $this->setColumnNotNull('customers', 'customer_code', 'unsignedBigInteger');
        $this->setColumnNotNull('imports', 'import_reference', 'string', 50);

        Schema::table('metal_lots', function (Blueprint $table): void {
            $table->unique(['shop_id', 'lot_number'], 'metal_lots_shop_lot_number_unique');
        });

        Schema::table('repairs', function (Blueprint $table): void {
            $table->unique(['shop_id', 'repair_number'], 'repairs_shop_repair_number_unique');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->unique(['shop_id', 'customer_code'], 'customers_shop_customer_code_unique');
        });

        Schema::table('imports', function (Blueprint $table): void {
            $table->unique(['shop_id', 'import_reference'], 'imports_shop_import_reference_unique');
        });
    }

    private function seedShopCounters(): void
    {
        $shopIds = DB::table('shops')->pluck('id');

        foreach ($shopIds as $shopId) {
            $counters = [
                'lot' => (int) DB::table('metal_lots')->where('shop_id', $shopId)->max('lot_number'),
                'repair' => (int) DB::table('repairs')->where('shop_id', $shopId)->max('repair_number'),
                'customer' => (int) DB::table('customers')->where('shop_id', $shopId)->max('customer_code'),
                'import' => (int) DB::table('imports')->where('shop_id', $shopId)->count(),
                'invoice' => (int) DB::table('invoices')->where('shop_id', $shopId)->max('invoice_sequence'),
            ];

            foreach ($counters as $key => $value) {
                DB::table('shop_counters')->updateOrInsert(
                    ['shop_id' => $shopId, 'counter_key' => $key],
                    ['current_value' => max(0, (int) $value), 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }

    private function enforceUsersShopIdNotNull(): void
    {
        if (!Schema::hasColumn('users', 'shop_id')) {
            return;
        }

        $orphans = DB::table('users')->whereNull('shop_id')->count();
        if ($orphans > 0) {
            throw new \RuntimeException("Cannot enforce users.shop_id NOT NULL: {$orphans} users have NULL shop_id.");
        }

        $this->setColumnNotNull('users', 'shop_id', 'unsignedBigInteger');
    }

    private function setColumnNotNull(string $tableName, string $column, string $type, ?int $length = null): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$tableName} ALTER COLUMN {$column} SET NOT NULL");

            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($column, $type, $length): void {
            $definition = $length !== null
                ? $table->{$type}($column, $length)
                : $table->{$type}($column);

            $definition->nullable(false)->change();
        });
    }

    private function createCounterFunctionAndTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION next_shop_counter(p_shop_id bigint, p_counter_key text)
RETURNS bigint AS $$
DECLARE
    v_current bigint;
    v_next bigint;
BEGIN
    INSERT INTO shop_counters (shop_id, counter_key, current_value, created_at, updated_at)
    VALUES (p_shop_id, p_counter_key, 0, now(), now())
    ON CONFLICT (shop_id, counter_key) DO NOTHING;

    SELECT current_value INTO v_current
    FROM shop_counters
    WHERE shop_id = p_shop_id AND counter_key = p_counter_key
    FOR UPDATE;

    IF v_current IS NULL THEN
        RAISE EXCEPTION 'Counter not found for shop % key %', p_shop_id, p_counter_key;
    END IF;

    v_next := v_current + 1;

    UPDATE shop_counters
    SET current_value = v_next,
        updated_at = now()
    WHERE shop_id = p_shop_id
      AND counter_key = p_counter_key;

    RETURN v_next;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION invoices_numbering_guard() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.invoice_sequence IS NULL THEN
            NEW.invoice_sequence := next_shop_counter(NEW.shop_id, 'invoice');
        END IF;

        IF NEW.invoice_number IS NULL OR trim(NEW.invoice_number) = '' THEN
            NEW.invoice_number := 'INV-' || NEW.shop_id || '-' || lpad(NEW.invoice_sequence::text, 10, '0');
        END IF;
        RETURN NEW;
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.status IN ('finalized', 'cancelled') AND NEW.invoice_number <> OLD.invoice_number THEN
            RAISE EXCEPTION 'invoice_number is immutable after finalization';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER IF EXISTS invoices_numbering_guard_trigger ON invoices');
        DB::statement('CREATE TRIGGER invoices_numbering_guard_trigger BEFORE INSERT OR UPDATE ON invoices FOR EACH ROW EXECUTE FUNCTION invoices_numbering_guard()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION metal_lots_business_identifier_assign() RETURNS trigger AS $$
BEGIN
    IF NEW.lot_number IS NULL THEN
        NEW.lot_number := next_shop_counter(NEW.shop_id, 'lot');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER IF EXISTS metal_lots_business_identifier_trigger ON metal_lots');
        DB::statement('CREATE TRIGGER metal_lots_business_identifier_trigger BEFORE INSERT ON metal_lots FOR EACH ROW EXECUTE FUNCTION metal_lots_business_identifier_assign()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION repairs_business_identifier_assign() RETURNS trigger AS $$
BEGIN
    IF NEW.repair_number IS NULL THEN
        NEW.repair_number := next_shop_counter(NEW.shop_id, 'repair');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER IF EXISTS repairs_business_identifier_trigger ON repairs');
        DB::statement('CREATE TRIGGER repairs_business_identifier_trigger BEFORE INSERT ON repairs FOR EACH ROW EXECUTE FUNCTION repairs_business_identifier_assign()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION customers_business_identifier_assign() RETURNS trigger AS $$
BEGIN
    IF NEW.customer_code IS NULL THEN
        NEW.customer_code := next_shop_counter(NEW.shop_id, 'customer');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER IF EXISTS customers_business_identifier_trigger ON customers');
        DB::statement('CREATE TRIGGER customers_business_identifier_trigger BEFORE INSERT ON customers FOR EACH ROW EXECUTE FUNCTION customers_business_identifier_assign()');

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION imports_business_identifier_assign() RETURNS trigger AS $$
DECLARE
    v_seq bigint;
BEGIN
    IF NEW.import_reference IS NULL OR trim(NEW.import_reference) = '' THEN
        v_seq := next_shop_counter(NEW.shop_id, 'import');
        NEW.import_reference := 'IMP-' || lpad(v_seq::text, 6, '0');
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
        DB::statement('DROP TRIGGER IF EXISTS imports_business_identifier_trigger ON imports');
        DB::statement('CREATE TRIGGER imports_business_identifier_trigger BEFORE INSERT ON imports FOR EACH ROW EXECUTE FUNCTION imports_business_identifier_assign()');
    }
};
