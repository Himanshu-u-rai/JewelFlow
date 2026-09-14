<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycDocument extends Model
{
    use BelongsToShop;

    public const TYPE_PAN_CARD = 'pan_card';
    public const TYPE_AADHAAR  = 'aadhaar';
    public const TYPE_PASSPORT = 'passport';
    public const TYPE_OTHER    = 'other';

    public const ALLOWED_TYPES = [
        self::TYPE_PAN_CARD => 'PAN Card',
        self::TYPE_AADHAAR  => 'Aadhaar',
        self::TYPE_PASSPORT => 'Passport',
        self::TYPE_OTHER    => 'Other',
    ];

    protected $fillable = [
        'shop_id',
        'customer_id',
        'uploaded_by',
        'document_type',
        'file_path',
        'file_disk',
        'original_filename',
        'mime_type',
        'file_size_bytes',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'file_size_bytes' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Identity documents (PAN/Aadhaar/passport) resolve ONLY to the authenticated,
     * shop-scoped stream route — whatever disk the row records.
     *
     * The previous 'public' branch honoured file_disk's schema default
     * (2026_05_10_200003_create_kyc_documents.php:19 is NOT NULL DEFAULT 'public'),
     * so any row not written by KycDocumentService minted a /storage/ URL that
     * nginx serves off the public/storage symlink with no auth, no tenant scope
     * and no shop_id check. show() still reads the recorded disk, so rows already
     * sitting on the public or s3 disk stay retrievable by authorised users.
     */
    public function url(): string
    {
        return route('kyc-documents.show', $this);
    }

    public function typeLabelAttribute(): string
    {
        return self::ALLOWED_TYPES[$this->document_type] ?? ucfirst($this->document_type);
    }

    public function deactivate(): void
    {
        // DB::raw for the boolean — native pgsql prepared statements reject a
        // bound PHP bool against a boolean column.
        \Illuminate\Support\Facades\DB::table('kyc_documents')
            ->where('id', $this->id)
            ->update(['is_active' => \Illuminate\Support\Facades\DB::raw('false'), 'updated_at' => now()]);
    }
}
