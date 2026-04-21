<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
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
}
