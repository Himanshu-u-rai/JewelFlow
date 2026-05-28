<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\ShopPaymentMethod;
use App\Services\InvoiceRenderSnapshotService;
use App\Support\PaymentMethodLabel;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BackfillAccountingSnapshots extends Command
{
    protected $signature = 'accounting:backfill-snapshots
        {--shop= : Optional shop_id scope}
        {--chunk=200 : Chunk size for backfill batches}';

    protected $description = 'Backfill invoice render snapshots and payment-method label snapshots.';

    public function handle(InvoiceRenderSnapshotService $snapshotService): int
    {
        $shopId = $this->option('shop') !== null ? (int) $this->option('shop') : null;
        $chunk = max(50, (int) $this->option('chunk'));

        $this->info('Backfilling invoice render snapshots...');
        $this->backfillInvoiceSnapshots($snapshotService, $shopId, $chunk);

        $this->info('Backfilling payment label snapshots...');
        $count = 0;
        $count += $this->backfillPaymentLabels('invoice_payments', 'mode', $shopId, $chunk);
        $count += $this->backfillPaymentLabels('karigar_payments', 'mode', $shopId, $chunk);
        $count += $this->backfillPaymentLabels('quick_bill_payments', 'payment_mode', $shopId, $chunk);

        $this->info("Completed. Payment rows updated: {$count}");

        return self::SUCCESS;
    }

    private function backfillInvoiceSnapshots(
        InvoiceRenderSnapshotService $snapshotService,
        ?int $shopId,
        int $chunk
    ): void {
        $query = Invoice::query()
            ->whereIn('status', [Invoice::STATUS_FINALIZED, Invoice::STATUS_CANCELLED])
            ->orderBy('id');

        if ($shopId) {
            $query->where('shop_id', $shopId);
        }

        $processed = 0;
        $query->chunkById($chunk, function ($invoices) use ($snapshotService, &$processed): void {
            foreach ($invoices as $invoice) {
                $snapshotService->captureForInvoice($invoice);
                $processed++;
            }

            $this->line("  processed invoices: {$processed}");
        });
    }

    private function backfillPaymentLabels(
        string $table,
        string $modeColumn,
        ?int $shopId,
        int $chunk
    ): int {
        $updated = 0;

        $query = DB::table($table)
            ->where(function (Builder $builder) {
                $builder->whereNull('payment_method_label_snapshot')
                    ->orWhere('payment_method_label_snapshot', '');
            })
            ->orderBy('id');

        if ($shopId) {
            $query->where('shop_id', $shopId);
        }

        $query->chunkById($chunk, function ($rows) use (&$updated, $table, $modeColumn): void {
            $methodIds = collect($rows)
                ->pluck('payment_method_id')
                ->filter(fn ($id) => ! empty($id))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            $methodsById = $methodIds->isEmpty()
                ? collect()
                : ShopPaymentMethod::withoutTenant()
                    ->whereIn('id', $methodIds)
                    ->get()
                    ->keyBy('id');

            foreach ($rows as $row) {
                $method = null;
                if (! empty($row->payment_method_id)) {
                    $method = $methodsById->get((int) $row->payment_method_id);
                }

                $label = PaymentMethodLabel::resolve($method, $row->{$modeColumn} ?? null);

                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['payment_method_label_snapshot' => $label]);

                $updated++;
            }
        });

        return $updated;
    }
}
