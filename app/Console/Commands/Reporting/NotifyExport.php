<?php

namespace App\Console\Commands\Reporting;

use App\Models\Reporting\ReportExport;
use App\Services\Reporting\ExportAuditService;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * S3-17. Deliver a finished export's ready-notification again — only the
 * notification: the file, its row and its status are not touched, and nothing
 * is regenerated. Without an id, lists the unexpired finished queued exports
 * not recorded as notified — a failed delivery, or a worker that died before
 * trying; with --send, delivers each. Exports finished before the delivery
 * record existed (notified_at) are listed too: nothing shows whether they were
 * notified. Delivery takes the export's row lock, so a retry racing the job
 * cannot notify twice.
 */
class NotifyExport extends Command
{
    protected $signature = 'reporting:notify-export
        {export? : Id of one finished export to notify again}
        {--send : With no id, deliver every listed export}';

    protected $description = 'Retry the ready-notification of a finished queued export, without regenerating it (S3-17).';

    public function handle(ExportAuditService $audit): int
    {
        if ($this->argument('export') === null) {
            $pending = ReportExport::withoutGlobalScopes()
                ->where('mode', ExportAuditService::MODE_QUEUED)->where('status', ExportAuditService::STATUS_DONE)
                ->whereNull('notified_at')->where('expires_at', '>', now())
                ->orderBy('id')->get();
            $this->line($pending->count().' unexpired finished export(s) not recorded as notified.');
            if (! $this->option('send')) {
                foreach ($pending as $export) {
                    $this->line("  export {$export->id}  shop {$export->shop_id}: ".($export->notification_error ?? 'no delivery attempt recorded'));
                }

                return self::SUCCESS;
            }

            $failed = 0;
            foreach ($pending as $export) {
                $failed += $this->deliver($audit, $export) ? 0 : 1;
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        }

        $export = ReportExport::withoutGlobalScopes()->find((int) $this->argument('export'));
        if ($export === null || $export->status !== ExportAuditService::STATUS_DONE) {
            $this->error('Refused: no finished export with that id.');

            return self::FAILURE;
        }
        if ($export->expires_at !== null && $export->expires_at->isPast()) {
            $this->error("Refused: export {$export->id}'s link expired at {$export->expires_at}.");

            return self::FAILURE;
        }
        if ($export->notified_at !== null) {
            $this->error("Refused: export {$export->id} was already notified at {$export->notified_at}.");

            return self::FAILURE;
        }

        return $this->deliver($audit, $export) ? self::SUCCESS : self::FAILURE;
    }

    private function deliver(ExportAuditService $audit, ReportExport $export): bool
    {
        $ok = TenantContext::runFor((int) $export->shop_id, fn () => $audit->deliverReadyNotification($export));
        $this->line($ok ? "export {$export->id}: notified" : "export {$export->id}: NOT delivered — {$export->fresh()->notification_error}");

        return $ok;
    }
}
