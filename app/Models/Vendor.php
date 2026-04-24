<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'name',
        'contact_person',
        'mobile',
        'email',
        'address',
        'city',
        'state',
        'gst_number',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function reorderRules()
    {
        return $this->hasMany(ReorderRule::class);
    }

    public function scopeActive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }

    public function scopeInactive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS FALSE');
    }
}
