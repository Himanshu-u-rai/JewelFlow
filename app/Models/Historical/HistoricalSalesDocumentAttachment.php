<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Evidence (scanned bill/proof) attached to a historical document (Batch 5).
 *
 * Storage/disk posture is identical to `KycDocument`: PRIVATE 'local' disk
 * only, streamed through an authenticated shop-scoped route, never a public
 * URL. `KycDocumentService::store()`'s mechanics (ULID filename derived from
 * the validated MIME type, not the client filename) are reused as-is by
 * `HistoricalSalesDocumentAttachmentService` — this model only adds the
 * historical-specific parent link and the soft-removal bookkeeping.
 *
 * Removal is SOFT, unlike `HistoricalSalesPayment` (hard-delete, draft-only).
 * Evidence must survive even after the parent document is published — an
 * operator can retract a wrongly-attached scan post-publish without losing
 * the audit trail of what was removed, by whom, and why. This is why
 * attachments are NOT wrapped in `HistoricalLifecycle::run()` / gated by
 * `ImmutableWhenPublished`: that mechanism protects the PARENT document's
 * own protected columns, and removing an attachment never touches those.
 */
class HistoricalSalesDocumentAttachment extends Model
{
    use BelongsToShop;

    protected $guarded = ['*'];

    protected $casts = [
        'is_active' => 'boolean',
        'file_size_bytes' => 'integer',
        'removed_at' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(HistoricalSalesDocument::class, 'historical_sales_document_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /**
     * The only writer of the three removal columns — mirrors
     * `HistoricalDocumentLifecycleService::void()`'s trim-and-require-reason
     * pattern exactly, so the DB's all-or-nothing CHECK constraint
     * (`historical_attachments_removal_metadata_check`) is never at risk of
     * seeing a half-filled row from this model.
     */
    public function remove(int $actorId, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new LogicException('Removing a historical attachment requires a reason.');
        }

        if (! $this->is_active) {
            throw new LogicException('This attachment has already been removed.');
        }

        // ponytail: the binary is deleted (matches KycDocumentService::delete()'s
        // posture); the audit trail this class exists for is the DB row itself
        // (who/when/why), which is never deleted, not a recoverable-file trash.
        $disk = $this->file_disk ?? 'local';

        if ($this->file_path && Storage::disk($disk)->exists($this->file_path)) {
            Storage::disk($disk)->delete($this->file_path);
        }

        $this->forceFill([
            'is_active' => false,
            'removed_at' => now(),
            'removed_by' => $actorId,
            'removed_reason' => $reason,
        ])->save();
    }
}
