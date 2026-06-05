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
        'generated_at',
    ];

    protected $casts = [
        'filters'            => 'array',
        'sensitive_included' => 'boolean',
        'row_count'          => 'integer',
        'expires_at'         => 'datetime',
        'finished_at'        => 'datetime',
        'generated_at'       => 'datetime',
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
                        . '(status, row_count, file_*, finished_at, error) may change.'
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
}
