<?php

namespace App\Jobs\Reporting;

use App\Models\Reporting\ReportExport;
use App\Services\Reporting\Dataset\ReportRequest as DatasetRequest;
use App\Services\Reporting\Definition\ExportFormat;
use App\Services\Reporting\Definition\ReportProfile;
use App\Services\Reporting\Definition\ReportRegistry;
use App\Services\Reporting\ExportAuditService;
use App\Services\Reporting\ExportPipeline;
use App\Services\Reporting\Filters\ResolvedPeriod;
use App\Services\Reporting\ProvenanceStamp;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Generates a large export off the web thread to transient storage with a 7-day
 * signed link (frozen §20). Writes the lifecycle onto the already-created
 * report_exports row (recorded `queued` by the controller): markFinished on
 * success, markFailed on error. The file is transient (swept after expiry); the
 * audit row persists (frozen §16).
 */
class GenerateQueuedExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(private readonly array $payload)
    {
    }

    public function handle(
        ReportRegistry $registry,
        ProvenanceStamp $provenance,
        ExportPipeline $pipeline,
        ExportAuditService $audit,
    ): void {
        $p = $this->payload;

        TenantContext::runFor((int) $p['shop_id'], function () use ($p, $registry, $provenance, $pipeline, $audit): void {
            $export = ReportExport::find($p['export_id']);
            if ($export === null) {
                return;
            }
            // A re-run of a finished export (the queue retrying a job whose
            // worker died before delivery) only delivers what is still owed.
            if ($export->status === ExportAuditService::STATUS_DONE) {
                $audit->deliverReadyNotification($export);

                return;
            }

            try {
                $definition = $registry->definition($p['report_key']);

                $period = new ResolvedPeriod(
                    CarbonImmutable::parse($p['date_from']),
                    CarbonImmutable::parse($p['date_to']),
                    $p['filters_applied']['Period'] ?? '',
                    $p['fy_name'] ?? null,
                );

                $request = new DatasetRequest(
                    definition: $definition,
                    shopId: (int) $p['shop_id'],
                    userId: $p['user_id'] !== null ? (int) $p['user_id'] : null,
                    userName: (string) $p['user_name'],
                    profile: ReportProfile::from($p['profile']),
                    format: ExportFormat::from($p['format']),
                    filters: $p['filters'],
                    columnKeys: $p['column_keys'],
                    includeSensitive: (bool) $p['include_sensitive'],
                    revealMasked: (bool) $p['reveal_masked'],
                );

                $meta = $provenance->stamp($definition, $request, $p['shop'], $period, $p['filters_applied'], $p['watermark']);

                $result = $pipeline->run($request, $meta);

                $disk = (string) config('reporting.queue_disk', 'local');
                // S3-18: one directory per export. The renderers name files
                // {report}-{Ymd-His}; stored flat, two shops' exports in the
                // same second shared a path, and the second overwrote the
                // first — whose download then served the other shop's data.
                $path = $result->output->storeOn($disk, $export->storageDirectory());
                $expiresAt = CarbonImmutable::now()->addDays((int) config('reporting.download_expiry_days', 7));

                $audit->markFinished($export, $result->rowCount, $disk, $path, $expiresAt);
            } catch (Throwable $e) {
                $audit->markFailed($export, $e->getMessage());
                throw $e;
            }

            // S3-17: outside the generation try. A notification failure used
            // to mark an already-generated export failed; now the export stays
            // done and the delivery outcome is recorded on its own
            // (notified_at / notification_error), retryable with
            // `reporting:notify-export` without regenerating anything.
            $audit->deliverReadyNotification($export->fresh());
        });
    }
}
