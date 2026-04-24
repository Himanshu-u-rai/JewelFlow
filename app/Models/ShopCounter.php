<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopCounter extends Model
{
    protected $fillable = [
        'shop_id',
        'counter_key',
        'current_value',
    ];
}
