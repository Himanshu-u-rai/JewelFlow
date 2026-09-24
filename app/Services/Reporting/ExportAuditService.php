<?php

namespace App\Services\Reporting;

use App\Models\Reporting\ReportExport;
use App\Models\User;
use App\Notifications\Reporting\ExportReadyNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;
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

    /**
     * S3-17. Tell the requester a finished export is ready, and record whether
     * that worked — separately from the export's own status, which a delivery
     * failure must never change. A delivery failure is recorded, not thrown:
     * the export already succeeded. Safe to call again, and concurrently; it
     * regenerates nothing and never delivers one export twice.
     */
    public function deliverReadyNotification(ReportExport $export): bool
    {
        try {
            $user = $export->user_id !== null ? User::withoutGlobalScopes()->find($export->user_id) : null;
            if ($user === null) {
                throw new \RuntimeException('The export has no requesting user to notify.');
            }

            // One unit under the export's row lock: the stored notification and
            // its record commit together, and a retry that waited on the lock
            // finds it delivered and adds nothing. Inside a caller's transaction
            // this is a savepoint — a failed insert rolls back to it instead of
            // aborting the caller's transaction.
            DB::transaction(function () use ($user, $export) {
                $locked = ReportExport::withoutGlobalScopes()->lockForUpdate()->findOrFail($export->id);
                if ($locked->notified_at === null) {
                    $user->notify(new ExportReadyNotification($locked));
                    $locked->update(['notified_at' => Carbon::now(), 'notification_error' => null]);
                }
            });

            return true;
        } catch (Throwable $e) {
            // The driver's message, not QueryException's: Laravel appends the SQL
            // with its bindings, and the bindings carry the signed download link.
            $reason = $e instanceof QueryException && $e->getPrevious() !== null ? $e->getPrevious()->getMessage() : $e->getMessage();
            Log::warning('Export finished but its ready-notification was not delivered', [
                'export_id' => $export->id, 'shop_id' => $export->shop_id, 'error' => $reason,
            ]);
            $export->update(['notification_error' => mb_substr($reason, 0, 500)]);

            return false;
        }
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
