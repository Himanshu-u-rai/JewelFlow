<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopStorageStat extends Model
{
    protected $fillable = [
        'shop_id',
        'file_count',
        'total_bytes',
        'breakdown',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'breakdown'   => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Human-readable file size (KB / MB / GB).
     */
    public function humanSize(): string
    {
        $bytes = (int) $this->total_bytes;

        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 2) . ' GB';
        }

        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 2) . ' MB';
        }

        if ($bytes >= 1_024) {
            return number_format($bytes / 1_024, 2) . ' KB';
        }

        return $bytes . ' B';
    }
}
