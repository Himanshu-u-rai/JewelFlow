<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One shop's opening-balance migration: orchestration, state machine, and lock.
 *
 * State machine: draft → review → posting → locked  (+ → cancelled from draft/review).
 * Only draft/review are editable. `posting` is the atomic-claim transition used
 * to make the lock idempotent (no double-post). locked/cancelled are terminal.
 */
class OnboardingBatch extends Model
{
    use BelongsToShop;

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_REVIEW    = 'review';
    public const STATUS_POSTING   = 'posting';
    public const STATUS_LOCKED    = 'locked';
    public const STATUS_CANCELLED = 'cancelled';

    /** Editable (staged rows can change) states. */
    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_REVIEW];

    /** Terminal (read-only, frees the "one active batch" slot) states. */
    public const TERMINAL = [self::STATUS_LOCKED, self::STATUS_CANCELLED];

    protected $fillable = [
        'shop_id',
        'as_of_date',
        'start_date',
        'status',
        'totals_snapshot',
        'created_by',
        'locked_by',
        'locked_at',
    ];

    protected $casts = [
        'as_of_date'      => 'date',
        'start_date'      => 'date',
        'totals_snapshot' => 'array',
        'locked_at'       => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(OnboardingEntry::class, 'onboarding_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }
}
