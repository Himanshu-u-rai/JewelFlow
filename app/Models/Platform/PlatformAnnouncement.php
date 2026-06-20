<?php

namespace App\Models\Platform;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformAnnouncement extends Model
{
    /** System notice types (render as the small inline app-layout banner). */
    public const SYSTEM_TYPES = ['info', 'warning', 'critical'];
    /** Big admin offers/deals banner type. */
    public const TYPE_BANNER = 'banner';
    /** Overrides the product cross-promo toast text/CTA. */
    public const TYPE_CROSS_PROMO = 'cross_promo';

    protected $fillable = [
        'title',
        'body',
        'cta_label',
        'cta_url',
        'type',
        'target',
        'target_value',
        'realm',
        'publish_at',
        'expires_at',
        'send_email',
        'created_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'publish_at'  => 'datetime',
            'expires_at'  => 'datetime',
            'send_email'  => 'boolean',
        ];
    }

    /**
     * Scope: active announcements (published and not yet expired).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where(function ($q) {
                $q->whereNull('publish_at')
                  ->orWhere('publish_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Determine whether this announcement targets the given shop.
     */
    public function isForShop(Shop $shop): bool
    {
        if ($this->target === 'all') {
            return true;
        }

        if ($this->target === 'edition') {
            return $this->target_value === $shop->shop_type;
        }

        // plan — complex matching skipped for now
        if ($this->target === 'plan') {
            return true;
        }

        return false;
    }

    /** Whether this announcement targets the given realm ('erp'|'dhiran'). Null realm = both. */
    public function isForRealm(?string $realm): bool
    {
        return $this->realm === null || $this->realm === $realm;
    }

    /** Scope by announcement type. */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /** The first active cross-promo override for a realm, or null (caller falls back to config). */
    public static function crossPromoFor(?string $realm): ?self
    {
        return static::query()->active()->ofType(self::TYPE_CROSS_PROMO)
            ->orderByDesc('created_at')->get()
            ->first(fn (self $a) => $a->isForRealm($realm));
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_admin_id');
    }
}
