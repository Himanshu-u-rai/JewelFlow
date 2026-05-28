<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only ledger of Tier 2 orchestration decisions.
 *
 * Every guided suggestion (pre-selected default) and the owner's acceptance
 * or override is recorded here. Records are immutable — no updates, no deletes.
 */
class OrchestrationEvent extends Model
{
    use BelongsToShop;

    /**
     * No updated_at — this is an append-only ledger table.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'shop_id',
        'user_id',
        'entity_type',
        'entity_id',
        'prompt_type',
        'suggested_action',
        'suggestion_reason',
        'user_decision',
        'was_overridden',
        'context_data',
    ];

    protected $casts = [
        'context_data'  => 'array',
        'was_overridden' => 'boolean',
        'created_at'    => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Immutability guard — append-only; matches EntityEvent pattern.
    // -------------------------------------------------------------------------

    /**
     * Block any ORM update on OrchestrationEvent rows.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return bool
     */
    protected function performUpdate($query): bool
    {
        throw new LogicException('OrchestrationEvent records are append-only and cannot be updated.');
    }

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('OrchestrationEvent records are append-only and cannot be deleted.');
        });
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
