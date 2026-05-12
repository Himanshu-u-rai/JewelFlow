<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

    public function url(): string
    {
        $disk = $this->file_disk ?? 'public';

        if ($disk === 's3') {
            return Storage::disk('s3')->temporaryUrl($this->file_path, now()->addMinutes(15));
        }

        return Storage::disk($disk)->url($this->file_path);
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
