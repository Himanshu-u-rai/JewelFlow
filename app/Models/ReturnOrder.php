<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use App\Services\BusinessIdentifierService;
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

    // Refund settlement methods (mirror App\Services\Returns\ReturnService).
    public const SETTLEMENT_CASH          = 'cash';
    public const SETTLEMENT_STORE_CREDIT  = 'store_credit';

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
        // Written once during the settlement transition (alongside settled_at).
        'refund_settlement',
        'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason',
        'override_approved_by_user_id', 'override_approved_at',
        'reason',
    ];

    protected $fillable = [
        'shop_id', 'invoice_id', 'customer_id',
        'return_type', 'status', 'reason',
        'refund_settlement',
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
        // jsonb column: createPendingApproval() writes an associative array
        // ({selections, reason, line_overrides}) and approveReturn() reads it
        // back via $data['selections']. Without this cast Eloquent inserts the
        // raw array (Array-to-string error) and reads an undecoded JSON string.
        'pending_data' => 'array',
    ];

    /**
     * return_number is the per-shop document number shown to the customer.
     * Deliberately absent from $fillable — it is assigned here, never from
     * request input, so nobody can pick their own. A matching Postgres
     * BEFORE INSERT trigger covers raw DB::table() writes that skip Eloquent.
     */
    protected static function booted(): void
    {
        static::creating(function (self $returnOrder): void {
            if (empty($returnOrder->return_number) && !empty($returnOrder->shop_id)) {
                $returnOrder->return_number = BusinessIdentifierService::nextCounter(
                    (int) $returnOrder->shop_id,
                    BusinessIdentifierService::KEY_RETURN
                );
            }
        });
    }

    public function getDisplayNumberAttribute(): string
    {
        return 'RET-' . str_pad((string) $this->return_number, 3, '0', STR_PAD_LEFT);
    }

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

    /**
     * Authoritative settlement method for display.
     *
     * Prefer the persisted `refund_settlement`. For legacy rows settled before
     * that column was written, derive it from the append-only store-credit
     * ledger: a `credit_note_issued` movement against this return's credit note
     * proves it was settled to store credit. No data rewrite — read-only derive.
     */
    public function settlementMethod(): string
    {
        if (in_array($this->refund_settlement, [self::SETTLEMENT_CASH, self::SETTLEMENT_STORE_CREDIT], true)) {
            return $this->refund_settlement;
        }

        // Scope explicitly to this return's own shop (not ambient tenant context)
        // so the derivation is deterministic wherever it's called from.
        $creditNoteId = $this->creditNote?->id;
        if ($creditNoteId && StoreCreditMovement::withoutTenant()
            ->where('shop_id', $this->shop_id)
            ->where('source_type', StoreCreditMovement::SOURCE_CREDIT_NOTE_ISSUED)
            ->where('source_id', $creditNoteId)
            ->exists()) {
            return self::SETTLEMENT_STORE_CREDIT;
        }

        return self::SETTLEMENT_CASH;
    }

    public function settlementMethodLabel(): string
    {
        return $this->settlementMethod() === self::SETTLEMENT_STORE_CREDIT
            ? 'Store credit'
            : 'Cash refund';
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
