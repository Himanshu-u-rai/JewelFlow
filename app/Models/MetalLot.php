<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Eloquent\Model;

class MetalLot extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'vendor_id',
        'metal_type',
        'source',
        'purity',
        'fine_weight_total',
        'fine_weight_remaining',
        'cost_per_fine_gram',
        'notes',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    protected $casts = [
        'lot_number' => 'integer',
        'purity' => 'decimal:2',
        'fine_weight_total' => 'decimal:6',
        'fine_weight_remaining' => 'decimal:6',
        'cost_per_fine_gram' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $lot): void {
            if (empty($lot->lot_number) && !empty($lot->shop_id)) {
                $lot->lot_number = BusinessIdentifierService::nextCounter((int) $lot->shop_id, BusinessIdentifierService::KEY_LOT);
            }
        });
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'metal_lot_id');
    }

    public function movements()
    {
        // orWhere on a hasMany leaks across tenants when eager-loaded.
        // Use a whereIn on both FK columns inside a grouped OR instead.
        return $this->hasMany(MetalMovement::class, 'from_lot_id')
            ->orWhere(fn ($q) => $q->where('to_lot_id', $this->id));
    }
}
