<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopPaymentMethod extends Model
{
    use BelongsToShop;

    public const TYPE_UPI    = 'upi';
    public const TYPE_BANK   = 'bank';
    public const TYPE_WALLET = 'wallet';

    // Configurable types — require account details, must be set up before appearing in POS
    public const TYPES = [
        self::TYPE_UPI,
        self::TYPE_BANK,
        self::TYPE_WALLET,
    ];

    public const TYPE_LABELS = [
        self::TYPE_UPI    => 'UPI',
        self::TYPE_BANK   => 'Bank',
        self::TYPE_WALLET => 'Wallet',
    ];

    protected $fillable = [
        'shop_id',
        'type',
        'name',
        'upi_id',
        'bank_name',
        'account_holder',
        'account_number',
        'ifsc_code',
        'account_type',
        'branch',
        'wallet_id',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['account_label'];

    public function scopeActive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /** Short label shown in POS payment row and dropdowns. */
    public function getAccountLabelAttribute(): string
    {
        return match ($this->type) {
            self::TYPE_UPI    => $this->upi_id ? "{$this->name} ({$this->upi_id})" : $this->name,
            self::TYPE_BANK   => $this->account_number
                ? "{$this->name} (****" . substr($this->account_number, -4) . ')'
                : $this->name,
            self::TYPE_WALLET => $this->wallet_id ? "{$this->name} ({$this->wallet_id})" : $this->name,
            default           => $this->name,
        };
    }
}
