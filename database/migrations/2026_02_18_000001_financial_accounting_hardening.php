<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addInvoiceLifecycleColumns();
        $this->addExplicitLedgerInvoiceReferences();
        $this->addFinancialAuditColumns();
        $this->addFinancialLockDate();

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->enforceInvoiceStatusCheck();
        $this->backfillLedgerInvoiceReferences();
        $this->hardenShopDeletionConstraints();
        $this->createImmutabilityTriggers();
    }

    public function down(): void
    {
        if (Schema::hasTable('invoices')) {
            Schema::table('invoices', function (Blueprint $table) {
                foreach ([
                    'finalized_at',
                    'finalized_by',
                    'cancelled_at',
                    'cancelled_by',
                    'cancellation_reason',
                    'reversal_of_invoice_id',
                    'reversed_by_invoice_id',
                ] as $column) {
                    if (Schema::hasColumn('invoices', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        foreach (['cash_transactions', 'metal_movements', 'customer_gold_transactions'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'invoice_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('invoice_id');
                });
            }
        }

        if (Schema::hasTable('audit_logs')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                foreach (['actor', 'target', 'before', 'after', 'ip', 'user_agent'] as $column) {
                    if (Schema::hasColumn('audit_logs', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('shop_rules') && Schema::hasColumn('shop_rules', 'financial_lock_date')) {
            Schema::table('shop_rules', function (Blueprint $table) {
                $table->dropColumn('financial_lock_date');
            });
        }
    }

    private function addInvoiceLifecycleColumns(): void
    {
        if (!Schema::hasTable('invoices')) {
            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'finalized_at')) {
                $table->timestamp('finalized_at')->nullable()->after('status');
            }
            if (!Schema::hasColumn('invoices', 'finalized_by')) {
                $table->foreignId('finalized_by')->nullable()->after('finalized_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('finalized_by');
            }
            if (!Schema::hasColumn('invoices', 'cancelled_by')) {
                $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            }
            if (!Schema::hasColumn('invoices', 'reversal_of_invoice_id')) {
                $table->foreignId('reversal_of_invoice_id')->nullable()->after('cancellation_reason')
                    ->constrained('invoices')->restrictOnDelete();
            }
            if (!Schema::hasColumn('invoices', 'reversed_by_invoice_id')) {
                $table->foreignId('reversed_by_invoice_id')->nullable()->after('reversal_of_invoice_id')
                    ->constrained('invoices')->nullOnDelete();
            }
        });

        DB::table('invoices')->where('status', 'paid')->update([
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);
    }

    private function addExplicitLedgerInvoiceReferences(): void
    {
        if (Schema::hasTable('cash_transactions') && !Schema::hasColumn('cash_transactions', 'invoice_id')) {
            Schema::table('cash_transactions', function (Blueprint $table) {
                $table->foreignId('invoice_id')->nullable()->after('source_id')->constrained('invoices')->restrictOnDelete();
                $table->index(['shop_id', 'invoice_id']);
                $table->index(['shop_id', 'created_at']);
            });
        }

        if (Schema::hasTable('metal_movements') && !Schema::hasColumn('metal_movements', 'invoice_id')) {
            Schema::table('metal_movements', function (Blueprint $table) {
                $table->foreignId('invoice_id')->nullable()->after('reference_id')->constrained('invoices')->restrictOnDelete();
                $table->index(['shop_id', 'invoice_id']);
            });
        }

        if (Schema::hasTable('customer_gold_transactions') && !Schema::hasColumn('customer_gold_transactions', 'invoice_id')) {
            Schema::table('customer_gold_transactions', function (Blueprint $table) {
                $table->foreignId('invoice_id')->nullable()->after('reference_id')->constrained('invoices')->restrictOnDelete();
                $table->index(['shop_id', 'invoice_id']);
            });
        }
    }

    private function addFinancialAuditColumns(): void
    {
        if (!Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('audit_logs', 'actor')) {
                $table->json('actor')->nullable()->after('data');
            }
            if (!Schema::hasColumn('audit_logs', 'target')) {
                $table->json('target')->nullable()->after('actor');
            }
            if (!Schema::hasColumn('audit_logs', 'before')) {
                $table->json('before')->nullable()->after('target');
            }
            if (!Schema::hasColumn('audit_logs', 'after')) {
                $table->json('after')->nullable()->after('before');
            }
            if (!Schema::hasColumn('audit_logs', 'ip')) {
                $table->string('ip', 64)->nullable()->after('after');
            }
            if (!Schema::hasColumn('audit_logs', 'user_agent')) {
                $table->text('user_agent')->nullable()->after('ip');
            }
        });
    }

    private function addFinancialLockDate(): void
    {
        if (Schema::hasTable('shop_rules') && !Schema::hasColumn('shop_rules', 'financial_lock_date')) {
            Schema::table('shop_rules', function (Blueprint $table) {
                $table->date('financial_lock_date')->nullable()->after('rounding_precision');
            });
        }
    }

    private function enforceInvoiceStatusCheck(): void
    {
        DB::statement("ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_status_check");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft','finalized','cancelled'))");
    }

    private function backfillLedgerInvoiceReferences(): void
    {
        DB::statement("UPDATE cash_transactions SET invoice_id = source_id WHERE source_type = 'invoice' AND source_id IS NOT NULL");
        DB::statement("UPDATE cash_transactions SET invoice_id = reference_id WHERE invoice_id IS NULL AND reference_type = 'invoice' AND reference_id IS NOT NULL");
        DB::statement("UPDATE metal_movements SET invoice_id = reference_id WHERE reference_type = 'invoice' AND reference_id IS NOT NULL");
        DB::statement("UPDATE customer_gold_transactions SET invoice_id = reference_id WHERE reference_type = 'invoice' AND reference_id IS NOT NULL");
    }

    private function hardenShopDeletionConstraints(): void
    {
        foreach (['invoices', 'cash_transactions', 'metal_movements', 'customer_gold_transactions'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_shop_id_foreign");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_shop_id_foreign FOREIGN KEY (shop_id) REFERENCES shops(id) ON DELETE RESTRICT");
        }
    }

    private function createImmutabilityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION prevent_ledger_mutation() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Ledger table % is append-only', TG_TABLE_NAME;
END;
$$ LANGUAGE plpgsql;
SQL);

        foreach (['cash_transactions', 'metal_movements', 'customer_gold_transactions'] as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_immutable_trigger ON {$table}");
            DB::statement("CREATE TRIGGER {$table}_immutable_trigger BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION prevent_ledger_mutation()");
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_invoice_immutability() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'Invoices cannot be deleted';
    END IF;

    IF OLD.status IN ('finalized','cancelled') THEN
        IF NOT (
            OLD.status = 'finalized'
            AND NEW.status = 'cancelled'
            AND OLD.cancelled_at IS NULL
            AND NEW.cancelled_at IS NOT NULL
        ) THEN
            RAISE EXCEPTION 'Finalized/cancelled invoice is immutable';
        END IF;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement("DROP TRIGGER IF EXISTS invoices_immutable_trigger ON invoices");
        DB::statement("CREATE TRIGGER invoices_immutable_trigger BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION enforce_invoice_immutability()");
    }
};
