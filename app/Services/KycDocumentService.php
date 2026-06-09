<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\KycDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Stores customer identity documents (PAN / Aadhaar / passport / other) on the
 * PRIVATE 'local' disk and records them. Shared by the web KYC controller and the
 * mobile API so storage, disk choice and audit stay identical across surfaces.
 */
class KycDocumentService
{
    /** Extension derived from the validated MIME type, not the client filename. */
    private const MIME_TO_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'application/pdf' => 'pdf',
    ];

    public function store(Customer $customer, UploadedFile $file, string $documentType, ?string $notes, int $uploadedBy): KycDocument
    {
        $shopId = (int) $customer->shop_id;
        $ext = self::MIME_TO_EXT[$file->getMimeType()] ?? 'bin';

        // Identity documents are PII — private 'local' disk only, never public.
        $path = $file->storeAs("kyc/{$shopId}", Str::ulid() . '.' . $ext, 'local');

        $doc = KycDocument::create([
            'shop_id' => $shopId,
            'customer_id' => $customer->id,
            'uploaded_by' => $uploadedBy,
            'document_type' => $documentType,
            'file_path' => $path,
            'file_disk' => 'local',
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size_bytes' => $file->getSize(),
            'notes' => $notes,
        ]);

        AccountingAuditService::log([
            'shop_id' => $shopId,
            'action' => 'kyc_document_uploaded',
            'model_type' => 'customer',
            'model_id' => $customer->id,
            'description' => "KYC document ({$doc->document_type}) uploaded for customer #{$customer->id}",
            'data' => ['document_id' => $doc->id, 'document_type' => $doc->document_type],
        ]);

        return $doc;
    }

    /**
     * Remove a KYC document: delete the PII file from disk and deactivate the
     * record. Shared by the web and mobile delete paths.
     */
    public function delete(KycDocument $document): void
    {
        $disk = $document->file_disk ?? 'public';

        if ($document->file_path && \Illuminate\Support\Facades\Storage::disk($disk)->exists($document->file_path)) {
            \Illuminate\Support\Facades\Storage::disk($disk)->delete($document->file_path);
        }

        $document->deactivate();
    }
}
