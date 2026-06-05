<?php

namespace App\Jobs\Reporting;

use App\Models\Reporting\ReportExport;
use App\Models\User;
use App\Notifications\Reporting\ExportReadyNotification;
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
                $path = $result->output->storeOn($disk, 'reporting-exports');
                $expiresAt = CarbonImmutable::now()->addDays((int) config('reporting.download_expiry_days', 7));

                $audit->markFinished($export, $result->rowCount, $disk, $path, $expiresAt);

                if ($request->userId !== null) {
                    User::find($request->userId)?->notify(new ExportReadyNotification($export->fresh()));
                }
            } catch (Throwable $e) {
                $audit->markFailed($export, $e->getMessage());
                throw $e;
            }
        });
    }
}
