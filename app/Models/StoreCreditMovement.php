<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per store-credit movement on a customer's wallet. Append-only —
 * the DB enforces this via the store_credit_append_only_guard trigger; the
 * model layer also blocks updating/deleting to give clearer errors before
 * the DB layer fires.
 *
 * Balance for a (shop, customer) = SUM(amount) of all movements for that
 * pair. Never stored as a column. See StoreCreditService::balance().
 */
class StoreCreditMovement extends Model
{
    use BelongsToShop;

    public const SOURCE_CREDIT_NOTE_ISSUED = 'credit_note_issued';
    public const SOURCE_SALE_APPLIED       = 'sale_applied';
    public const SOURCE_MANUAL_ADJUSTMENT  = 'manual_adjustment';
    public const SOURCE_EXPIRY             = 'expiry';
    public const SOURCE_REVERSAL           = 'reversal';

    protected $fillable = [
        'shop_id', 'customer_id',
        'amount', 'source_type', 'source_id',
        'expires_at', 'notes',
        'user_id', 'approved_by_user_id',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Store credit movements are append-only — cannot update.');
        });

        static::deleting(function () {
            throw new LogicException('Store credit movements are append-only — cannot delete.');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
