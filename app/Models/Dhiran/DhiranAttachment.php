<?php

namespace App\Models\Dhiran;

use App\Models\Concerns\BelongsToShop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private, shop-scoped Dhiran evidence file (Phase E2).
 *
 * Owner is polymorphic (a loan, a pledged item, or a customer). Files are stored
 * on the private disk; BelongsToShop guarantees every query is tenant-filtered and
 * the shop_id is set on create from the tenant context. Nothing here exposes a
 * public URL — access is only ever through the permission-gated stream route.
 */
class DhiranAttachment extends Model
{
    use BelongsToShop;

    protected $table = 'dhiran_attachments';

    public const OWNER_LOAN     = 'dhiran_loan';
    public const OWNER_ITEM     = 'dhiran_loan_item';
    public const OWNER_CUSTOMER = 'customer';

    /** Allowed document types (validation allow-list). */
    public const DOCUMENT_TYPES = [
        'item_photo',
        'id_proof_front', 'id_proof_back', 'address_proof', 'borrower_photo',
        'pledge_agreement', 'signed_terms', 'valuation_proof', 'loan_document',
    ];

    protected $fillable = [
        'shop_id',
        'owner_type',
        'owner_id',
        'document_type',
        'file_disk',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by',
    ];

    protected $casts = [
        'owner_id'   => 'integer',
        'size_bytes' => 'integer',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Whether this attachment is an image (drives inline preview vs download). */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }
}
