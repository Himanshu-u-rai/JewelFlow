<?php

namespace App\Services\Dhiran;

use App\Models\Dhiran\DhiranAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;

/**
 * Stores Dhiran evidence files on the PRIVATE disk and records a shop-scoped
 * dhiran_attachments row (Phase E2/E3).
 *
 * Safety:
 *  - Private disk only ('local' = storage/app/private); never 'public'/web-served.
 *  - Allow-list of extensions + MIME; size cap; dangerous types rejected.
 *  - Images are re-encoded via GD so EXIF/geolocation metadata is stripped.
 *  - Path is non-guessable (random filename) under a shop-scoped directory.
 *  - shop_id comes from the tenant context (BelongsToShop), never the request.
 */
class DhiranAttachmentService
{
    /** Private disk (storage/app/private). */
    public const DISK = 'local';

    public const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    /** ext => mime allow-list. */
    private const ALLOWED = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'pdf'  => 'application/pdf',
    ];

    /**
     * Validate + store an uploaded file as a Dhiran attachment.
     *
     * @param  int     $shopId       authenticated shop (never from request)
     * @param  string  $ownerType    DhiranAttachment::OWNER_*
     * @param  int     $ownerId
     * @param  string  $documentType one of DhiranAttachment::DOCUMENT_TYPES
     */
    public function store(
        UploadedFile $file,
        int $shopId,
        string $ownerType,
        int $ownerId,
        string $documentType,
        ?int $uploadedBy = null,
    ): DhiranAttachment {
        if (! $file->isValid()) {
            throw new LogicException('The uploaded file is not valid.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new LogicException('File is too large (max 8 MB).');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (! array_key_exists($ext, self::ALLOWED)) {
            throw new LogicException('Unsupported file type. Allowed: JPG, PNG, PDF.');
        }
        // Verify the real MIME matches the claimed extension (block disguised files).
        $realMime = $file->getMimeType();
        if ($realMime !== self::ALLOWED[$ext]) {
            throw new LogicException('File contents do not match its type.');
        }
        if (! in_array($documentType, DhiranAttachment::DOCUMENT_TYPES, true)) {
            throw new LogicException('Unknown document type.');
        }

        $dir      = "dhiran/{$shopId}/" . Str::random(2);
        $filename = Str::random(40) . '.' . $ext;
        $path     = "{$dir}/{$filename}";
        $disk     = Storage::disk(self::DISK);

        if ($realMime === 'image/jpeg' || $realMime === 'image/png') {
            // Re-encode through GD to strip EXIF/geolocation metadata.
            $disk->put($path, $this->stripImageMetadata($file, $realMime));
        } else {
            $disk->putFileAs($dir, $file, $filename);
        }

        return DhiranAttachment::create([
            'shop_id'       => $shopId,
            'owner_type'    => $ownerType,
            'owner_id'      => $ownerId,
            'document_type' => $documentType,
            'file_disk'     => self::DISK,
            'file_path'     => $path,
            'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
            'mime_type'     => $realMime,
            'size_bytes'    => $file->getSize(),
            'uploaded_by'   => $uploadedBy,
        ]);
    }

    /** Re-encode an image with GD to drop all metadata; returns the raw bytes. */
    private function stripImageMetadata(UploadedFile $file, string $mime): string
    {
        $img = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if ($img === false) {
            throw new LogicException('The image could not be processed.');
        }

        ob_start();
        if ($mime === 'image/png') {
            imagealphablending($img, false);
            imagesavealpha($img, true);
            imagepng($img);
        } else {
            imagejpeg($img, null, 88);
        }
        $bytes = ob_get_clean();
        imagedestroy($img);

        return (string) $bytes;
    }
}
