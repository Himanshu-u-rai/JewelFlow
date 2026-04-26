<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Karigar extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'name',
        'contact_person',
        'mobile',
        'email',
        'address',
        'city',
        'state',
        'pincode',
        'gst_number',
        'pan_number',
        'default_wastage_percent',
        'default_making_per_gram',
        'opening_balance',
        'opening_balance_at',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'default_wastage_percent' => 'decimal:2',
        'default_making_per_gram' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'opening_balance_at' => 'date',
    ];

    public function jobOrders()
    {
        return $this->hasMany(JobOrder::class);
    }

    public function invoices()
    {
        return $this->hasMany(KarigarInvoice::class);
    }

    public function payments()
    {
        return $this->hasMany(KarigarPayment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }

    public function getOutstandingBalanceAttribute(): float
    {
        $invoiced = (float) $this->invoices()->sum('total_after_tax');
        $paid = (float) $this->payments()->sum('amount');

        return (float) $this->opening_balance + $invoiced - $paid;
    }
}
