<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PendingUpload — short-lived upload-intent row backing the M6 presigned
 * upload flow at /api/mobile/v1/uploads/.
 *
 * Lifecycle:
 *   pending  → uploaded → ready → (consumed)
 *                                ↘  expired (TTL elapsed, never consumed)
 *                       ↘ failed (corrupt bytes / finalize crash)
 *
 * Not a ledger row. Reaped by `mobile:prune-uploads`.
 */
class PendingUpload extends Model
{
    protected $fillable = [
        'shop_id',
        'user_id',
        'kind',
        'content_type',
        'declared_size_bytes',
        'actual_size_bytes',
        'upload_token',
        'original_path',
        'thumbnail_path',
        'status',
        'expires_at',
        'consumed_at',
        'consumed_by_type',
        'consumed_by_id',
    ];

    protected $casts = [
        'expires_at'          => 'datetime',
        'consumed_at'         => 'datetime',
        'declared_size_bytes' => 'integer',
        'actual_size_bytes'   => 'integer',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Uploads that an Item/Repair create flow may legitimately consume.
     */
    public function scopeConsumable(Builder $query): Builder
    {
        return $query
            ->where('status', 'ready')
            ->whereNull('consumed_at')
            ->where('expires_at', '>=', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
