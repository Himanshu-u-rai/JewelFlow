<?php

namespace App\Models\Platform;

use App\Support\ShopEdition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'trial_days' => 'integer',
            'grace_days' => 'integer',
            'downgrade_to_read_only_on_due' => 'boolean',
            'is_active' => 'boolean',
            'features' => 'array',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(ShopSubscription::class);
    }

    public function platformProduct(): BelongsTo
    {
        return $this->belongsTo(PlatformProduct::class, 'platform_product_id');
    }

    /**
     * The edition string this plan grants when its subscription is active.
     *
     * Prefers the linked platform product (the authoritative mapping). Falls
     * back to inferring from the plan code prefix for plans that predate the
     * platform_product_id backfill or ad-hoc test fixtures. Returns null when
     * nothing can be inferred.
     */
    public function grantsEdition(): ?string
    {
        if ($this->platform_product_id && $this->platformProduct) {
            return $this->platformProduct->editionString();
        }

        $code = (string) $this->code;
        if (str_starts_with($code, 'retailer_') || $code === 'retailer') {
            return ShopEdition::RETAILER;
        }
        if (str_starts_with($code, 'manufacturer_') || $code === 'manufacturer') {
            return ShopEdition::MANUFACTURER;
        }
        if (str_starts_with($code, 'dhiran_') || $code === 'dhiran') {
            return ShopEdition::DHIRAN;
        }

        return null;
    }
}
