<?php

namespace App\Models\Platform;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopSubscription extends Model
{
    protected $fillable = [
        'shop_id',
        'user_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'grace_ends_at',
        'cancelled_at',
        'billing_cycle',
        'price_paid',
        'razorpay_payment_id',
        'razorpay_order_id',
        'updated_by_admin_id',
        'actor_type',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'date',
            'ends_at' => 'date',
            'grace_ends_at' => 'date',
            'cancelled_at' => 'datetime',
            'price_paid' => 'decimal:2',
        ];
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'updated_by_admin_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class);
    }

    /**
     * Whole calendar days from today until ends_at (signed: negative = overdue,
     * 0 = ends today). Compared start-of-day to start-of-day so the result is an
     * integer day count, not a fractional value from the current time-of-day
     * (Carbon 3's diffInDays returns a float). Returns null if there's no end date.
     */
    public function daysRemaining(): ?int
    {
        if (! $this->ends_at) {
            return null;
        }

        return (int) \Carbon\CarbonImmutable::now()->startOfDay()
            ->diffInDays($this->ends_at->copy()->startOfDay(), false);
    }

    /**
     * Whether the shop's latest subscription row genuinely entitles access
     * TODAY, using the same calendar-date boundary logic as the
     * CheckSubscriptionExpiry scheduler (not the middleware's status-only
     * trust). A row can carry status='active' with a stale, already-past
     * ends_at until the next midnight scheduler run catches it — this check
     * closes that same-day window so callers never grant/promise access the
     * scheduler is about to revoke anyway.
     */
    public static function entitlesAccessToday(int $shopId): bool
    {
        $subscription = static::query()->where('shop_id', $shopId)->latest('id')->first();
        if (! $subscription) {
            return false;
        }

        $today = now()->toDateString();

        return match ($subscription->status) {
            'active', 'trial' => ! $subscription->ends_at || $subscription->ends_at->toDateString() >= $today,
            'grace' => ! $subscription->grace_ends_at || $subscription->grace_ends_at->toDateString() >= $today,
            default => false,
        };
    }
}
