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
     * Whether the shop's own core ERP edition (its shop_type: retailer or
     * manufacturer) genuinely entitles access TODAY, using the same
     * calendar-date boundary logic as the CheckSubscriptionExpiry scheduler
     * (not the middleware's status-only trust). A row can carry
     * status='active' with a stale, already-past ends_at until the next
     * midnight scheduler run catches it — this check closes that same-day
     * window so callers never grant/promise access the scheduler is about
     * to revoke anyway.
     *
     * Edition-scoped: only subscriptions whose plan grants the SAME edition
     * as the shop's own shop_type are considered. A Dhiran subscription can
     * never justify Retail/ERP access and vice versa — a shop that holds
     * both products is judged on the ERP-edition row alone here, regardless
     * of the other product's state.
     *
     * Fails closed: no matching-edition row, an unrecognised shop_type, or a
     * row with a null/malformed starts_at, ends_at, or grace_ends_at (for
     * the branch that needs it) never entitles access.
     */
    public static function entitlesAccessToday(Shop $shop): bool
    {
        $edition = $shop->shop_type;
        if (! in_array($edition, ['retailer', 'manufacturer'], true)) {
            return false;
        }

        $subscription = static::query()
            ->where('shop_id', $shop->id)
            ->with('plan.platformProduct')
            ->latest('id')
            ->get()
            ->first(fn (self $sub) => $sub->plan?->grantsEdition() === $edition);

        if (! $subscription || ! $subscription->starts_at) {
            return false;
        }

        $today = now()->toDateString();
        if ($subscription->starts_at->toDateString() > $today) {
            return false; // future-dated term hasn't started yet
        }

        return match ($subscription->status) {
            'active', 'trial' => (bool) $subscription->ends_at && $subscription->ends_at->toDateString() >= $today,
            'grace' => (bool) $subscription->grace_ends_at && $subscription->grace_ends_at->toDateString() >= $today,
            default => false,
        };
    }
}
