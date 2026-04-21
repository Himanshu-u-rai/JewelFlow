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
        'default_purity',
        'approx_weight',
        'default_making',
        'default_stone',
        'notes',
        'image',
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
}
