<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReturnOrder extends Model
{
    use BelongsToShop;
    use ImmutableLedger;

    // Lifecycle states — see architecture §7.
    public const STATUS_DRAFT             = 'draft';
    public const STATUS_PENDING_APPROVAL  = 'pending_approval';
    public const STATUS_SUBMITTED         = 'submitted';
    public const STATUS_SETTLED           = 'settled';
    public const STATUS_CANCELLED         = 'cancelled';

    // Return-type allow-list — Phase 1 supports only customer_return.
    public const TYPE_CUSTOMER_RETURN     = 'customer_return';

    /**
     * Once a return is settled, ALL fields are immutable. Drafts/pending
     * are edited via dedicated service methods, not generic save(), so the
     * trait's hard block is the right behaviour here — settled = frozen.
     *
     * We keep a small allow-list for state-machine transitions: status,
     * approved_*, settled_*, cancelled_*. Those are written by the service
     * when transitioning between states.
     */
    protected $allowedUpdateColumns = [
        'status',
        'approved_by_user_id', 'approved_at',
        'settled_by_user_id', 'settled_at',
        'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason',
        'override_approved_by_user_id', 'override_approved_at',
        'reason',
    ];

    protected $fillable = [
        'shop_id', 'invoice_id', 'customer_id',
        'return_type', 'status', 'reason',
        'return_window_violation_override', 'override_approved_by_user_id', 'override_approved_at',
        'created_by_user_id',
        'approved_by_user_id', 'approved_at',
        'settled_by_user_id', 'settled_at',
        'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason',
    ];

    protected $casts = [
        'return_window_violation_override' => 'boolean',
        'approved_at'  => 'datetime',
        'settled_at'   => 'datetime',
        'cancelled_at' => 'datetime',
        'override_approved_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function settledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by_user_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(ReturnLineItem::class);
    }

    public function creditNote(): HasOne
    {
        return $this->hasOne(CreditNote::class);
    }

    public function exchangeOrder(): HasOne
    {
        return $this->hasOne(ExchangeOrder::class);
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_SETTLED;
    }

    public function isEditable(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
