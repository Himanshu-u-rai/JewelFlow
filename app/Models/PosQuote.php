<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Server-issued, signed POS price quote.
 *
 * A quote is the authoritative breakdown the cashier sees. The frontend may
 * never recalculate it — the breakdown stored here is the ONLY money math
 * that gets converted into an invoice on Complete Sale.
 *
 * Quotes are append-only: once persisted they cannot be mutated except for
 * the consume-time stamping (consumed_at, consumed_invoice_id). Re-using a
 * consumed quote returns the same invoice (idempotency).
 */
class PosQuote extends Model
{
    use BelongsToShop;

    public const MODE_RETAILER     = 'retailer';
    public const MODE_MANUFACTURER = 'manufacturer';
    public const MODE_EXCHANGE     = 'exchange';
    public const MODE_REPAIR       = 'repair';

    public const CLIENT_WEB    = 'web';
    public const CLIENT_MOBILE = 'mobile';

    protected $fillable = [
        'quote_id',
        'shop_id',
        'customer_id',
        'created_by_user_id',
        'mode',
        'client',
        'input_payload',
        'breakdown_json',
        'breakdown_hash',
        'signature',
        'expires_at',
        'consumed_at',
        'consumed_invoice_id',
        'idempotency_key',
    ];

    protected $casts = [
        'input_payload' => 'array',
        'expires_at'    => 'datetime',
        'consumed_at'   => 'datetime',
    ];

    protected static function booted(): void
    {
        // Quotes are append-only except for the consume stamp.
        static::updating(function (self $quote) {
            $dirty = array_keys($quote->getDirty());
            $allowed = ['consumed_at', 'consumed_invoice_id', 'updated_at'];
            foreach ($dirty as $field) {
                if (! in_array($field, $allowed, true)) {
                    throw new LogicException("PosQuote field '{$field}' is immutable; only consume-time fields may change.");
                }
            }
        });

        static::deleting(function () {
            throw new LogicException('PosQuote rows are append-only and cannot be deleted.');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function consumedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'consumed_invoice_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->isExpired();
    }

    /**
     * Decode the canonical breakdown JSON that was signed.
     * The raw `breakdown_json` is the source of truth (re-serialising would
     * break signature verification on whitespace/key-order differences).
     */
    public function breakdown(): array
    {
        return json_decode((string) $this->breakdown_json, true, 512, JSON_THROW_ON_ERROR);
    }
}
