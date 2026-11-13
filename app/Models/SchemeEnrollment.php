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

    /**
     * The bonus counts only once it has been accrued (audit H3): the flag is set, with its ledger entry, exactly when
     * every instalment is paid. A status says nothing about it: a plan matured by date while under-paid, or one whose
     * payments were spent before maturity, earned none.
     */
    public function accruedBonusAmount(): float
    {
        return $this->is_bonus_accrued ? (float) $this->bonus_amount : 0.0;
    }

    /** All instalments together. */
    public function contributionTarget(): float
    {
        return round((float) $this->monthly_amount * (int) $this->total_installments, 2);
    }

    public function remainingContribution(): float
    {
        return max(0.0, round($this->contributionTarget() - (float) $this->total_paid, 2));
    }

    /** Every instalment paid, by money (not by number of payments). */
    public function isFullyPaid(): bool
    {
        return $this->remainingContribution() <= 0.01;
    }

    /** Instalments fully covered by this much money. */
    public function installmentsCoveredBy(float $paid): int
    {
        $monthly = (float) $this->monthly_amount;
        if ($monthly <= 0) {
            return 0;
        }

        return max(0, min((int) $this->total_installments, (int) floor(($paid + 0.005) / $monthly)));
    }

    public function redeemableAmount(): float
    {
        $gross = (float) $this->total_paid + $this->accruedBonusAmount();
        return max(0, round($gross - (float) $this->redeemed_amount, 2));
    }
}
