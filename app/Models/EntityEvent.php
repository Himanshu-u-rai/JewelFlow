<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EntityEvent extends Model
{
    use BelongsToShop;

    /**
     * No updated_at — this is an append-only ledger table.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'shop_id',
        'entity_type',
        'entity_id',
        'event_type',
        'level',
        'summary',
        'detail',
        'actor_user_id',
        'occurred_at',
    ];

    protected $casts = [
        'detail'      => 'array',
        'occurred_at' => 'datetime',
        'created_at'  => 'datetime',
        'level'       => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Immutability guard — defence-in-depth; BelongsToShop / no updated_at
    // means the ORM won't normally try to UPDATE, but we make it explicit.
    // -------------------------------------------------------------------------

    /**
     * Block any ORM update on EntityEvent rows.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return bool
     */
    protected function performUpdate($query): bool
    {
        throw new LogicException('EntityEvent records are append-only and cannot be updated.');
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('EntityEvent records are append-only and cannot be deleted.');
        });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
