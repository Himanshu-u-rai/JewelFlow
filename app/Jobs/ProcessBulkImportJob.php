<?php

namespace App\Jobs;

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

    public function handle(BulkImportService $service): void
    {
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
    }
}
