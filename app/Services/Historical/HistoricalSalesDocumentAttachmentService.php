<?php

namespace App\Services\Historical;

use App\Models\Historical\HistoricalSalesDocument;
use App\Models\Historical\HistoricalSalesDocumentAttachment;
use App\Services\AccountingAuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

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
        $shopId = (int) $document->shop_id;
        $ext = self::MIME_TO_EXT[$file->getMimeType()] ?? 'bin';

        // Evidence is PII/financial proof — private 'local' disk only, never public.
        $path = $file->storeAs("historical-attachments/{$shopId}", Str::ulid().'.'.$ext, 'local');

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
    }

    /** Soft-remove: delete the file from disk, keep the audited DB row. */
    public function remove(HistoricalSalesDocumentAttachment $attachment, int $actorId, string $reason): void
    {
        $attachment->remove($actorId, $reason);

        AccountingAuditService::log([
            'shop_id' => $attachment->shop_id,
            'action' => 'historical_attachment_removed',
            'model_type' => 'historical_sales_document',
            'model_id' => $attachment->historical_sales_document_id,
            'description' => "Evidence attachment #{$attachment->id} removed from historical document #{$attachment->historical_sales_document_id}",
            'data' => ['attachment_id' => $attachment->id, 'reason' => $attachment->removed_reason],
        ]);
    }
}
