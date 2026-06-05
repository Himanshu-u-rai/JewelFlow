<?php

namespace App\Console\Commands\Reporting;

use App\Models\Reporting\ReportExport;
use App\Services\Reporting\ExportAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes expired transient export files (frozen §16/§20). The file is removed
 * once its 7-day window passes; the report_exports audit row PERSISTS (metadata
 * only) — its file_disk/file_path are cleared to mark the artifact swept.
 * Runs across all tenants (console has no tenant context), so the global shop
 * scope is bypassed explicitly.
 */
class SweepExpiredExports extends Command
{
    protected $signature = 'reporting:sweep-expired-exports';

    protected $description = 'Delete expired queued-export files; audit rows are retained.';

    public function handle(): int
    {
        $now = now();
        $swept = 0;

        ReportExport::withoutTenant()
            ->where('status', ExportAuditService::STATUS_DONE)
            ->whereNotNull('file_path')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->chunkById(200, function ($exports) use (&$swept): void {
                foreach ($exports as $export) {
                    if ($export->file_disk !== null && Storage::disk($export->file_disk)->exists($export->file_path)) {
                        Storage::disk($export->file_disk)->delete($export->file_path);
                    }
                    $export->update(['file_disk' => null, 'file_path' => null]);
                    $swept++;
                }
            });

        $this->info("Swept {$swept} expired export file(s).");

        return self::SUCCESS;
    }
}
