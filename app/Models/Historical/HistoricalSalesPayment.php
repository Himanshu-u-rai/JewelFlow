<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use App\Models\ShopPaymentMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One tender against a historical document (V2 §7/§8).
 *
 * NEVER an `InvoicePayment`. This row never appends to the live cashbook, bank
 * ledger, wallet or store-credit balance — it exists purely to reconstruct
 * what a backdated bill's payment breakdown was.
 *
 * `shop_payment_method_id` is an OPTIONAL, read-only reference. The FK is a
 * plain single-column `ON DELETE SET NULL` (see the creating migration's
 * docblock for why it cannot be a composite shop-scoped FK here); this model
 * makes up the cross-shop integrity the FK cannot by rejecting, at write time,
 * any reference to a payment method belonging to a different shop.
 */
class HistoricalSalesPayment extends Model
{
    use BelongsToShop;

    /** Reuses `InvoicePayment::VALID_MODES`' vocabulary only — never the model. */
    public const MODE_CASH       = 'cash';
    public const MODE_UPI        = 'upi';
    public const MODE_BANK       = 'bank';
    public const MODE_WALLET     = 'wallet';
    public const MODE_OLD_GOLD   = 'old_gold';
    public const MODE_OLD_SILVER = 'old_silver';
    public const MODE_OTHER      = 'other';
    public const MODE_EMI        = 'emi';
    public const MODE_SCHEME     = 'scheme';

    public const VALID_MODES = [
        self::MODE_CASH,
        self::MODE_UPI,
        self::MODE_BANK,
        self::MODE_WALLET,
        self::MODE_OLD_GOLD,
        self::MODE_OLD_SILVER,
        self::MODE_OTHER,
        self::MODE_EMI,
        self::MODE_SCHEME,
    ];

    protected $guarded = ['*'];

    protected $casts = [
        'amount'       => 'decimal:2',
        'payment_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $payment): void {
            $payment->assertPaymentMethodBelongsToOwnShop();
        });
    }

    /**
     * The composite `(child_id, shop_id) -> (id, shop_id)` FK pattern used
     * elsewhere in this module cannot be used here (a composite `ON DELETE
     * SET NULL` would null this row's own `shop_id`, see the migration
     * docblock) so this check is the only thing standing between a stray
     * write and a historical payment quietly pointing at another shop's bank
     * account.
     */
    private function assertPaymentMethodBelongsToOwnShop(): void
    {
        if ($this->shop_payment_method_id === null) {
            return;
        }

        $method = ShopPaymentMethod::withoutTenant()->find($this->shop_payment_method_id);

        if ($method === null || (int) $method->shop_id !== (int) $this->shop_id) {
            throw new InvalidArgumentException(
                'A historical payment cannot reference a payment method from another shop.'
            );
        }
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HistoricalSalesDocument::class, 'historical_sales_document_id');
    }

    /** Optional, read-only convenience link — see class docblock. */
    public function shopPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(ShopPaymentMethod::class);
    }

    /** True only when the reference still exists AND is currently active. */
    public function paymentMethodStillActive(): bool
    {
        if ($this->shop_payment_method_id === null) {
            return false;
        }

        $method = $this->relationLoaded('shopPaymentMethod')
            ? $this->shopPaymentMethod
            : ShopPaymentMethod::withoutTenant()->find($this->shop_payment_method_id);

        return (bool) ($method?->is_active ?? false);
    }

    /**
     * What every read path should render — the snapshot is authoritative
     * regardless of what happened to the live method afterwards (locked
     * decision: deleted/disabled methods stay visible, labelled
     * "No longer active").
     */
    public function displayAccountLabel(): string
    {
        $label = $this->account_label_snapshot ?? ucfirst(str_replace('_', ' ', (string) $this->mode));

        return $this->paymentMethodStillActive() ? $label : "{$label} (No longer active)";
    }
}
