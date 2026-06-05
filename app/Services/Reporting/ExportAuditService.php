<?php

namespace App\Services\Reporting;

use App\Models\Reporting\ReportExport;
use App\Services\Reporting\Dataset\ReportRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Writes one append-only `report_exports` row per export event (frozen §16).
 * Covers sync, queued, and FAILED exports. Records whether sensitive columns
 * were included (data-governance) and the exact resolved filters, so a CA's
 * copy can be reproduced from report_version + profile + filters. The file is
 * never stored here — only metadata + a transient path/expiry for the queued
 * artifact (frozen §16/§20). Pairs with the column gate (ColumnPolicy): the
 * gate prevents unauthorized inclusion, this records every authorized one.
 */
class ExportAuditService
{
    public const STATUS_DONE = 'done';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_FAILED = 'failed';

    public const MODE_SYNC = 'sync';
    public const MODE_QUEUED = 'queued';

    /** A completed synchronous export. */
    public function recordSync(ReportRequest $request, bool $sensitiveIncluded, ?int $rowCount = null): ReportExport
    {
        return $this->write($request, $sensitiveIncluded, self::MODE_SYNC, self::STATUS_DONE, $rowCount);
    }

    /** A queued export at enqueue time (status transitions later via markFinished/markFailed). */
    public function recordQueued(ReportRequest $request, bool $sensitiveIncluded): ReportExport
    {
        return $this->write($request, $sensitiveIncluded, self::MODE_QUEUED, self::STATUS_QUEUED);
    }

    /** A failed export (sync or queued). */
    public function recordFailure(ReportRequest $request, bool $sensitiveIncluded, string $mode, string $error): ReportExport
    {
        return $this->write($request, $sensitiveIncluded, $mode, self::STATUS_FAILED, null, $error);
    }

    /**
     * Transition a queued row to done/failed. Only the lifecycle columns change;
     * the model blocks any change to provenance (write-once).
     */
    public function markFinished(
        ReportExport $export,
        int $rowCount,
        ?string $fileDisk = null,
        ?string $filePath = null,
        ?CarbonInterface $expiresAt = null,
    ): ReportExport {
        $export->update([
            'status' => self::STATUS_DONE,
            'row_count' => $rowCount,
            'file_disk' => $fileDisk,
            'file_path' => $filePath,
            'expires_at' => $expiresAt,
            'finished_at' => Carbon::now(),
        ]);

        return $export;
    }

    public function markFailed(ReportExport $export, string $error): ReportExport
    {
        $export->update([
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'finished_at' => Carbon::now(),
        ]);

        return $export;
    }

    private function write(
        ReportRequest $request,
        bool $sensitiveIncluded,
        string $mode,
        string $status,
        ?int $rowCount = null,
        ?string $error = null,
    ): ReportExport {
        return ReportExport::create([
            'shop_id' => $request->shopId,
            'user_id' => $request->userId,
            'report_key' => $request->definition->key,
            'report_version' => $request->definition->version,
            'profile' => $request->profile->value,
            'profile_version' => null, // profiles version centrally; surfaced when present (§15)
            'format' => $request->format->value,
            'filters' => $this->normalizeFilters($request->filters),
            'sensitive_included' => $sensitiveIncluded,
            'mode' => $mode,
            'status' => $status,
            'row_count' => $rowCount,
            'error' => $error,
            'generated_at' => Carbon::now(),
        ]);
    }

    /**
     * Make the resolved filter set JSON-safe: dates → ISO 8601 strings so the
     * stored filters are diffable and reproducible (frozen §15/§16).
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $normalize = function ($value) use (&$normalize) {
            if ($value instanceof CarbonInterface) {
                return $value->toIso8601String();
            }
            if (is_array($value)) {
                return array_map($normalize, $value);
            }
            return $value;
        };

        return array_map($normalize, $filters);
    }
}
