<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot row linking a Shop to one active (or historically-granted) edition.
 *
 * Named "Assignment" to avoid colliding with App\Support\ShopEdition, which
 * stays as the central helper/constants class. This model is only the
 * persistence layer for the shop_editions table.
 *
 * Active editions are rows where deactivated_at IS NULL. Revoked editions
 * keep their row with deactivated_at/deactivated_by/deactivation_reason
 * populated — useful for audit and for re-granting without losing history.
 */
class ShopEditionAssignment extends Model
{
    protected $table = 'shop_editions';

    protected $fillable = [
        'shop_id',
        'edition',
        'activated_at',
        'activated_by',
        'deactivated_at',
        'deactivated_by',
        'deactivation_reason',
    ];

    protected $casts = [
        'activated_at'   => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }
}
