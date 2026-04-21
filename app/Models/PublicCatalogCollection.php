<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicCatalogCollection extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'created_by_user_id',
        'token',
        'title',
        'item_ids',
        'expires_at',
    ];

    protected $casts = [
        'item_ids'   => 'array',   // kept for backward compat during transition
        'expires_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function collectionItems(): HasMany
    {
        return $this->hasMany(CatalogCollectionItem::class, 'collection_id');
    }

    /** Eager-loadable items through the pivot */
    public function items(): HasMany
    {
        return $this->collectionItems()->with('item');
    }

    /** Scope: collections that have not yet expired */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }
}

