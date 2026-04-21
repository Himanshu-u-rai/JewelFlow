<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Eloquent\Model;

class MetalLot extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'source',
        'purity',
        'fine_weight_total',
        'fine_weight_remaining',
        'cost_per_fine_gram',
    ];

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
        return $this->hasMany(MetalMovement::class, 'from_lot_id')
            ->orWhere('to_lot_id', $this->id);
    }
}
