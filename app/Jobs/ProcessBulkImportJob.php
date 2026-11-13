<?php

namespace App\Jobs;

use App\Exceptions\ImportRunFailed;
use App\Models\Import;
use App\Services\BulkImportService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessBulkImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $importId,
        public string $mode
    ) {
    }

    /**
     * The whole run is one transaction holding the import's row: its "running" claim is never committed on its
     * own, so a run that dies (crash, timeout) rolls back to the import as it was, ready to run again — nothing is
     * left "running" or "queued" (audit N6). A run that fails is rolled back too, then recorded as failed.
     */
    public function handle(BulkImportService $service): void
    {
        try {
            DB::transaction(function () use ($service): void {
                $import = Import::withoutTenant()
                    ->where('id', $this->importId)
                    ->lockForUpdate()
                    ->first();
                if (!$import) {
                    return;
                }

                TenantContext::runFor((int) $import->shop_id, function () use ($service, $import): void {
                    $service->execute($import, $this->mode);
                });
            });
        } catch (ImportRunFailed $e) {
            $import = Import::withoutTenant()->findOrFail($this->importId);
            TenantContext::runFor((int) $import->shop_id, fn () => $service->recordFailure($import, $this->mode, $e->getPrevious() ?? $e));

            throw $e;
        }
    }
}
