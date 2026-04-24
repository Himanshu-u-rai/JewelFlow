<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory, BelongsToShop;

    protected $fillable = [
        'name',
        'design_code',
        'category_id',
        'sub_category_id',
        'metal_type',
        'default_purity',
        'approx_weight',
        'default_making',
        'default_stone',
        'notes',
        'image',
    ];

    protected $casts = [
        'default_purity' => 'decimal:3',
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function category()
    {
        return $this->belongsTo(\App\Models\Category::class, 'category_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(\App\Models\SubCategory::class, 'sub_category_id');
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function getDefaultPurityLabelAttribute(): ?string
    {
        if ($this->default_purity === null) {
            return null;
        }

        $value = rtrim(rtrim(number_format((float) $this->default_purity, 3, '.', ''), '0'), '.');

        if ($this->metal_type === 'silver') {
            return $value;
        }

        return $value . 'K';
    }
}
