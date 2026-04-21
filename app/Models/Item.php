<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Item extends Model
{
    use BelongsToShop;

    protected static function booted(): void
    {
        static::creating(function (Item $item) {
            if (blank($item->share_token)) {
                $item->share_token = (string) Str::ulid();
            }
        });
    }

    protected $fillable = [
        'barcode',
        'design',
        'image',
        'category',
        'sub_category',
        'gross_weight',
        'stone_weight',
        'net_metal_weight',
        'purity',
        'metal_lot_id',
        'status',
        'wastage',
        'making_charges',
        'stone_charges',
        'cost_price',
        'selling_price',
        'source',
        'product_id',
        'vendor_id',
        'huid',
        'hallmark_date',
        'share_token',
    ];

    protected $casts = [
        'gross_weight' => 'decimal:6',
        'stone_weight' => 'decimal:6',
        'net_metal_weight' => 'decimal:6',
        'purity' => 'decimal:2',
        'wastage' => 'decimal:6',
        'making_charges' => 'decimal:2',
        'stone_charges' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'hallmark_date' => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function metalLot()
    {
        return $this->belongsTo(MetalLot::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function scopeInStock($query)
    {
        return $query->where('status', 'in_stock');
    }

    public function scopeWithHuid($query)
    {
        return $query->whereNotNull('huid');
    }

    public function scopeHasShareToken($query)
    {
        return $query->whereNotNull('share_token');
    }
}
