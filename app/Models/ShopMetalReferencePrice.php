<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class B — manual reference-price memo (platinum, copper).
 *
 * This is NOT an accounting rate. It is an operator memo displayed as a hint.
 * Append-only: an outdated reference is recorded as a NEW row, never edited.
 *
 * @see docs/runbooks/material-pricing-classes.md
 */
class ShopMetalReferencePrice extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'metal_type',
        'reference_price',
        'noted_at',
        'noted_by_user_id',
        'note',
    ];

    protected $casts = [
        'reference_price' => 'decimal:2',
        'noted_at'        => 'datetime',
    ];

    // Append-only. To "update" a reference, record a NEW row.
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'ShopMetalReferencePrice is append-only — record a new reference instead of updating an existing one.'
            );
        });

        static::deleting(function () {
            throw new \LogicException('ShopMetalReferencePrice is append-only.');
        });
    }

    public function notedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'noted_by_user_id');
    }
}
