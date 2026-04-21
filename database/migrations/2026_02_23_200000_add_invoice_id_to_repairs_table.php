<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            if (!Schema::hasColumn('repairs', 'invoice_id')) {
                $table->foreignId('invoice_id')->nullable()->after('customer_id')
                    ->constrained('invoices')
                    ->restrictOnDelete();
                $table->index(['shop_id', 'invoice_id']);
            }
        });

        $this->backfillInvoiceIdsFromAuditLogs();
    }

    public function down(): void
    {
        Schema::table('repairs', function (Blueprint $table) {
            if (Schema::hasColumn('repairs', 'invoice_id')) {
                $table->dropIndex('repairs_shop_id_invoice_id_index');
                $table->dropConstrainedForeignId('invoice_id');
            }
        });
    }

    private function backfillInvoiceIdsFromAuditLogs(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                UPDATE repairs r
                SET invoice_id = (al.data->>'invoice_id')::bigint
                FROM audit_logs al
                WHERE al.shop_id = r.shop_id
                  AND al.model_type = 'repair'
                  AND al.model_id = r.id
                  AND al.action = 'repair_deliver'
                  AND al.data IS NOT NULL
                  AND (al.data->>'invoice_id') IS NOT NULL
                  AND r.invoice_id IS NULL
            SQL);

            return;
        }

        $logs = DB::table('audit_logs')
            ->where('model_type', 'repair')
            ->where('action', 'repair_deliver')
            ->whereNotNull('data')
            ->orderBy('id')
            ->get(['shop_id', 'model_id', 'data']);

        foreach ($logs as $log) {
            $payload = $log->data;

            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            } elseif (is_object($payload)) {
                $payload = (array) $payload;
            }

            $invoiceId = (int) data_get($payload, 'invoice_id', 0);
            if ($invoiceId <= 0) {
                continue;
            }

            DB::table('repairs')
                ->where('shop_id', $log->shop_id)
                ->where('id', $log->model_id)
                ->whereNull('invoice_id')
                ->update(['invoice_id' => $invoiceId]);
        }
    }
};
