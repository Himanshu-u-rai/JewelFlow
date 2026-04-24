<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Scheme extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'name',
        'type',
        'description',
        'start_date',
        'end_date',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'min_purchase_amount',
        'total_installments',
        'bonus_month_value',
        'is_active',
        'auto_apply',
        'priority',
        'stackable',
        'applies_to',
        'applies_to_value',
        'max_uses_per_customer',
        'terms',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'discount_value' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'min_purchase_amount' => 'decimal:2',
        'bonus_month_value' => 'decimal:2',
        'total_installments' => 'integer',
        'auto_apply' => 'boolean',
        'priority' => 'integer',
        'stackable' => 'boolean',
        'max_uses_per_customer' => 'integer',
        'is_active' => 'boolean',
    ];

    public function enrollments()
    {
        return $this->hasMany(SchemeEnrollment::class);
    }

    public function offerApplications()
    {
        return $this->hasMany(InvoiceOfferApplication::class);
    }

    public function scopeActive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }

    public function scopeAutoApply($query)
    {
        return $query->whereRaw($query->qualifyColumn('auto_apply') . ' IS TRUE');
    }

    public function scopeGoldSavings($query)
    {
        return $query->where('type', 'gold_savings');
    }

    public function scopeOffers($query)
    {
        return $query->whereIn('type', ['festival_sale', 'discount_offer']);
    }

    public function isGoldSavings(): bool
    {
        return $this->type === 'gold_savings';
    }

    public function isOfferType(): bool
    {
        return in_array($this->type, ['festival_sale', 'discount_offer'], true);
    }

    public function isRunning(): bool
    {
        $today = now()->startOfDay();
        $startDate = $this->start_date?->copy()->startOfDay();
        $endDate = $this->end_date?->copy()->endOfDay();

        return $this->is_active
            && (!$startDate || $startDate->lte($today))
            && (!$endDate || $endDate->gte($today));
    }
}
