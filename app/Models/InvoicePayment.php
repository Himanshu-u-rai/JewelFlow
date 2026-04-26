<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvoicePayment extends Model
{
    use BelongsToShop;

    protected $guarded = ['*'];

    protected $casts = [
        'amount'               => 'decimal:2',
        'metal_gross_weight'   => 'decimal:3',
        'metal_purity'         => 'decimal:2',
        'metal_test_loss'      => 'decimal:2',
        'metal_fine_weight'    => 'decimal:3',
        'metal_rate_per_gram'  => 'decimal:2',
    ];

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

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Invoice payments are immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Invoice payments cannot be deleted.');
        });
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentMethod()
    {
        return $this->belongsTo(ShopPaymentMethod::class, 'payment_method_id');
    }

    /**
     * Create a payment record via forceFill (all fields are guarded).
     */
    public static function record(array $attributes): self
    {
        $model = new self();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    /**
     * Whether this payment involves physical metal exchange.
     */
    public function isMetalPayment(): bool
    {
        return in_array($this->mode, [self::MODE_OLD_GOLD, self::MODE_OLD_SILVER]);
    }
}
