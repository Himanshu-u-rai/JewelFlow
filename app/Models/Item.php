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
        'metal_type',
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
        'hallmark_charges',
        'rhodium_charges',
        'other_charges',
        'cost_price',
        'selling_price',
        'source',
        'product_id',
        'vendor_id',
        'huid',
        'hallmark_date',
        'share_token',
        'pricing_review_required',
        'pricing_review_notes',
        'stock_purchase_id',
        'job_order_id',
    ];

    protected $casts = [
        'gross_weight' => 'decimal:6',
        'stone_weight' => 'decimal:6',
        'net_metal_weight' => 'decimal:6',
        'purity' => 'decimal:2',
        'wastage' => 'decimal:6',
        'making_charges' => 'decimal:2',
        'stone_charges' => 'decimal:2',
        'hallmark_charges' => 'decimal:2',
        'rhodium_charges' => 'decimal:2',
        'other_charges' => 'decimal:2',
        'cost_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'hallmark_date' => 'date',
        'pricing_review_required' => 'boolean',
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

    public function stockPurchase()
    {
        return $this->belongsTo(StockPurchase::class, 'stock_purchase_id');
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class, 'job_order_id');
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

    public function getPurityLabelAttribute(): ?string
    {
        if ($this->purity === null) {
            return null;
        }

        $value = rtrim(rtrim(number_format((float) $this->purity, 3, '.', ''), '0'), '.');

        if ($this->metal_type === 'silver') {
            return $value;
        }

        return $value . 'K';
    }
}
