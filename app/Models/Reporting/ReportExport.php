<?php

namespace App\Models\Reporting;

use App\Models\Concerns\BelongsToShop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only export audit (REPORT_EXPORT_IMPLEMENTATION_PLAN.md §0.2, frozen §16).
 *
 * Immutability is enforced here ("AuditLog discipline") rather than via a DB
 * trigger, so the queued lifecycle can transition while provenance stays
 * write-once. Provenance columns may never change; rows are never deleted by
 * the application (DB-level FK cascade on shop deletion is unaffected).
 */
class ReportExport extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id', 'user_id',
        'report_key', 'report_version', 'profile', 'profile_version',
        'format', 'filters', 'sensitive_included',
        'mode', 'status', 'row_count',
        'file_disk', 'file_path', 'expires_at', 'finished_at', 'error',
        'generated_at', 'notified_at', 'notification_error',
    ];

    protected $casts = [
        'filters'            => 'array',
        'sensitive_included' => 'boolean',
        'row_count'          => 'integer',
        'expires_at'         => 'datetime',
        'finished_at'        => 'datetime',
        'generated_at'       => 'datetime',
        'notified_at'        => 'datetime',
    ];

    /** Columns that are write-once after creation. */
    private const IMMUTABLE = [
        'shop_id', 'user_id', 'report_key', 'report_version',
        'profile', 'profile_version', 'format', 'filters',
        'sensitive_included', 'mode', 'generated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (ReportExport $export): void {
            foreach (self::IMMUTABLE as $column) {
                if ($export->isDirty($column)) {
                    throw new LogicException(
                        "report_exports.{$column} is immutable; only the queued lifecycle "
                        . '(status, row_count, file_*, finished_at, error, notified_at, notification_error) may change.'
                    );
                }
            }
        });

        static::deleting(function (): void {
            throw new LogicException('report_exports is append-only and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * S3-18. The one directory this export's file may live in. Queued files
     * are written here (GenerateQueuedExportJob) and served only from here
     * (ExportDownloadController), so a file's location proves which export —
     * and which shop — it belongs to.
     */
    public function storageDirectory(): string
    {
        return 'reporting-exports/'.(int) $this->shop_id.'/'.(int) $this->id;
    }

    /**
     * Is the recorded file directly inside storageDirectory()? False for every
     * file stored before that layout (flat reporting-exports/{report}-{time}):
     * two exports may name one such file, and even a file only one row names
     * may have been overwritten by a job that failed before recording its own
     * path, so nothing recorded proves whose bytes it holds.
     */
    public function fileIsInOwnDirectory(): bool
    {
        $path = (string) $this->file_path;
        $prefix = $this->storageDirectory().'/';

        return str_starts_with($path, $prefix)
            && ! str_contains(substr($path, strlen($prefix)), '/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && strlen($path) > strlen($prefix);
    }
}
