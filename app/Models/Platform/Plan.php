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
     * What this plan charges for a billing cycle, or null when it does not sell
     * that cycle at all.
     *
     * A plan may price one cycle and not the other — `retailer_monthly`,
     * `manufacturer_monthly` and `dhiran_monthly` all ship with a null
     * `price_yearly`. "Not sold" reaches the column in more than one shape
     * (null, and 0 from an incomplete edit), so every caller that read the two
     * columns directly had to repeat the same two-part test. Most did. The two
     * that did not — the self-service plan chooser and the admin subscription
     * editor — are exactly how a cycle a plan does not sell became selectable.
     * Ask this instead of the columns.
     */
    public function priceFor(string $cycle): ?float
    {
        // Matches SubscriptionTerm: anything that is not 'yearly' is a monthly term.
        $price = $cycle === 'yearly' ? $this->price_yearly : $this->price_monthly;

        return ($price !== null && (float) $price > 0) ? (float) $price : null;
    }

    public function supportsCycle(string $cycle): bool
    {
        return $this->priceFor($cycle) !== null;
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
