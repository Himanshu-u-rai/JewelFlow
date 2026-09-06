<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesDocumentAttachment;
use App\Services\AccountingAuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Stores/removes evidence (scanned bills/proof) against a historical document
 * on the PRIVATE 'local' disk. Mirrors `KycDocumentService` exactly — same
 * disk choice, same MIME-derived extension (never the client filename), same
 * audit-log shape — so the two private-document surfaces stay identical
 * rather than inventing a second convention for Batch 5.
 */
class HistoricalSalesDocumentAttachmentService
{
    /** Extension derived from the validated MIME type, not the client filename. */
    private const MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function store(HistoricalSalesDocument $document, UploadedFile $file, int $uploadedBy): HistoricalSalesDocumentAttachment
    {
        $this->assertMutable($document);

        $shopId = (int) $document->shop_id;
        $ext = self::MIME_TO_EXT[$file->getMimeType()] ?? 'bin';

        // Evidence is PII/financial proof — private 'local' disk only, never public.
        $path = $file->storeAs("historical-attachments/{$shopId}", Str::ulid().'.'.$ext, 'local');

        try {
            return DB::transaction(function () use ($document, $file, $uploadedBy, $shopId, $path): HistoricalSalesDocumentAttachment {
                // $guarded = ['*'] on this model (same posture as every other Historical
                // row — evidence is force-filled by the service, never mass-assigned from
                // a request), so this needs forceFill(), not create().
                $attachment = new HistoricalSalesDocumentAttachment;
                $attachment->forceFill([
                    'shop_id' => $shopId,
                    'historical_sales_document_id' => $document->id,
                    'uploaded_by' => $uploadedBy,
                    'file_path' => $path,
                    'file_disk' => 'local',
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'file_size_bytes' => $file->getSize(),
                ])->save();

                AccountingAuditService::log([
                    'shop_id' => $shopId,
                    'action' => 'historical_attachment_uploaded',
                    'model_type' => 'historical_sales_document',
                    'model_id' => $document->id,
                    'description' => "Evidence attachment uploaded for historical document #{$document->id}",
                    'data' => ['attachment_id' => $attachment->id, 'original_filename' => $attachment->original_filename],
                ]);

                return $attachment;
            });
        } catch (Throwable $e) {
            // The DB row (and its audit entry) rolled back together on any
            // failure — an audit-log write failure can never leave a "phantom"
            // attachment row with no audit trail. The physical file has no
            // transaction to roll back into, so it's deleted explicitly instead
            // of leaking storage for a row that no longer exists.
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    /**
     * Soft-remove: deactivate the row, keep both the audit trail AND the
     * physical file on disk. Deliberately NOT the `KycDocumentService::delete()`
     * pattern (PII purge) — evidence is financial proof, so it must stay
     * recoverable after removal. `is_active = false` is what blocks the
     * streaming route (`HistoricalDocumentAttachmentController::show()`);
     * see the model's class docblock for the full rationale.
     */
    public function remove(HistoricalSalesDocumentAttachment $attachment, int $actorId, string $reason): void
    {
        $this->assertMutable($attachment->document);

        DB::transaction(function () use ($attachment, $actorId, $reason): void {
            $attachment->remove($actorId, $reason);

            AccountingAuditService::log([
                'shop_id' => $attachment->shop_id,
                'action' => 'historical_attachment_removed',
                'model_type' => 'historical_sales_document',
                'model_id' => $attachment->historical_sales_document_id,
                'description' => "Evidence attachment #{$attachment->id} removed from historical document #{$attachment->historical_sales_document_id}",
                'data' => ['attachment_id' => $attachment->id, 'reason' => $attachment->removed_reason],
            ]);
        });
    }

    /**
     * Attachments are writable on DRAFT and PUBLISHED documents (evidence can
     * still be corrected post-publish — see the model's docblock). VOID and
     * SUPERSEDED are terminal: the document itself is dead, so its evidence
     * trail is frozen too (still viewable/streamable, never added to or
     * removed from).
     */
    private function assertMutable(HistoricalSalesDocument $document): void
    {
        if (in_array($document->status, [
            HistoricalSalesDocument::STATUS_VOID,
            HistoricalSalesDocument::STATUS_SUPERSEDED,
        ], true)) {
            throw new LogicException(sprintf(
                'Historical document #%d is %s; its evidence attachments are read-only.',
                $document->id,
                $document->status
            ));
        }
    }
}
