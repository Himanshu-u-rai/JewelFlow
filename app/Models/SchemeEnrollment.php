<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class SchemeEnrollment extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'scheme_id',
        'customer_id',
        'start_date',
        'terms_accepted_at',
        'terms_version',
        'monthly_amount',
        'total_paid',
        'redeemed_amount',
        'redemption_count',
        'installments_paid',
        'total_installments',
        'maturity_date',
        'redeemed_at',
        'last_payment_at',
        'status',
        'bonus_amount',
        'is_bonus_accrued',
        'maturity_bonus_accrued_at',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'maturity_date' => 'date',
        'redeemed_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'last_payment_at' => 'date',
        'monthly_amount' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'redeemed_amount' => 'decimal:2',
        'bonus_amount' => 'decimal:2',
        'redemption_count' => 'integer',
        'installments_paid' => 'integer',
        'total_installments' => 'integer',
        'is_bonus_accrued' => 'boolean',
        'maturity_bonus_accrued_at' => 'datetime',
    ];

    public function scheme()
    {
        return $this->belongsTo(Scheme::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments()
    {
        return $this->hasMany(SchemePayment::class, 'enrollment_id');
    }

    public function redemptions()
    {
        return $this->hasMany(SchemeRedemption::class, 'scheme_enrollment_id');
    }

    public function ledgerEntries()
    {
        return $this->hasMany(SchemeLedgerEntry::class, 'scheme_enrollment_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeMatured($query)
    {
        return $query->where('status', 'matured');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isMatured(): bool
    {
        return $this->status === 'matured';
    }

    public function remainingInstallments(): int
    {
        return max(0, $this->total_installments - $this->installments_paid);
    }

    public function accruedBonusAmount(): float
    {
        if ($this->status === 'matured' || $this->status === 'redeemed' || $this->is_bonus_accrued) {
            return (float) $this->bonus_amount;
        }

        return 0.0;
    }

    public function redeemableAmount(): float
    {
        $gross = (float) $this->total_paid + $this->accruedBonusAmount();
        return max(0, round($gross - (float) $this->redeemed_amount, 2));
    }
}
