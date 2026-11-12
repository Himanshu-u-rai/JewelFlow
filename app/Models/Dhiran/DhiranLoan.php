<?php

namespace App\Models\Dhiran;

use App\Models\Concerns\BelongsToShop;
use App\Models\Customer;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DhiranLoan extends Model
{
    use BelongsToShop;

    /** Loans that still owe money (the rest are finished: closed, renewed into a new loan, or forfeited). */
    public const OPEN = ['pending_evidence', 'active'];

    protected $fillable = [
        'shop_id',
        'customer_id',
        'loan_number',
        'loan_date',
        'gold_rate_on_date',
        'silver_rate_on_date',
        'principal_amount',
        'processing_fee',
        'processing_fee_type',
        'interest_rate_monthly',
        'interest_type',
        'penalty_rate_monthly',
        'ltv_ratio_applied',
        'total_collateral_value',
        'total_fine_weight',
        'outstanding_principal',
        'outstanding_interest',
        'outstanding_penalty',
        'interest_accrued_through',
        'total_interest_collected',
        'total_penalty_collected',
        'total_principal_collected',
        'tenure_months',
        'maturity_date',
        'min_lock_months',
        'grace_period_days',
        'min_interest_months',
        'status',
        'renewed_count',
        'renewed_from_id',
        'kyc_aadhaar',
        'kyc_pan',
        'kyc_photo_path',
        'terms_text',
        'notes',
        'closed_at',
        'closure_notes',
        'forfeited_at',
        'forfeited_by',
        'forfeiture_notice_sent_at',
        'forfeiture_notice_text',
        'forfeiture_deadline',
        'brought_forward',
        'interest_capitalised',
        'created_by',
    ];

    protected $casts = [
        'loan_date'                 => 'date',
        'maturity_date'             => 'date',
        'interest_accrued_through'  => 'date',
        'closed_at'                 => 'datetime',
        'forfeited_at'              => 'datetime',
        'forfeiture_notice_sent_at' => 'datetime',
        'forfeiture_deadline'       => 'date',
        'brought_forward'           => 'boolean',
        'gold_rate_on_date'         => 'decimal:4',
        'silver_rate_on_date'       => 'decimal:4',
        'principal_amount'          => 'decimal:2',
        'processing_fee'            => 'decimal:2',
        'interest_rate_monthly'     => 'decimal:2',
        'penalty_rate_monthly'      => 'decimal:2',
        'ltv_ratio_applied'         => 'decimal:2',
        'total_collateral_value'    => 'decimal:2',
        'total_fine_weight'         => 'decimal:6',
        'outstanding_principal'     => 'decimal:2',
        'outstanding_interest'      => 'decimal:2',
        'outstanding_penalty'       => 'decimal:2',
        'total_interest_collected'  => 'decimal:2',
        'total_penalty_collected'   => 'decimal:2',
        'total_principal_collected' => 'decimal:2',
        'tenure_months'             => 'integer',
        'min_lock_months'           => 'integer',
        'grace_period_days'         => 'integer',
        'min_interest_months'       => 'integer',
        'renewed_count'             => 'integer',
    ];

    /* ── Relationships ─────────────────────────────────── */

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function items()
    {
        return $this->hasMany(DhiranLoanItem::class);
    }

    public function payments()
    {
        return $this->hasMany(DhiranPayment::class);
    }

    public function cashEntries()
    {
        return $this->hasMany(DhiranCashEntry::class);
    }

    public function ledgerEntries()
    {
        return $this->hasMany(DhiranLedgerEntry::class);
    }

    public function renewedFrom()
    {
        return $this->belongsTo(self::class, 'renewed_from_id');
    }

    public function renewals()
    {
        return $this->hasMany(self::class, 'renewed_from_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Computed helpers ───────────────────────────────── */

    public function totalOutstanding(): float
    {
        // A finished loan owes nothing (a loan renewed before 2026-11-12 still carries the principal it handed on).
        if (! in_array($this->status, self::OPEN, true)) {
            return 0.0;
        }

        return round(
            (float) $this->outstanding_principal
            + (float) $this->outstanding_interest
            + (float) $this->outstanding_penalty,
            2
        );
    }

    // The Dhiran views read these two names; without them every total showed ₹0.00 and LTV 0.0%.
    public function getTotalOutstandingAttribute(): float
    {
        return $this->totalOutstanding();
    }

    public function getLtvPercentAttribute(): ?float
    {
        return $this->ltv_ratio_applied === null ? null : (float) $this->ltv_ratio_applied;
    }

    public function isOverdue(): bool
    {
        return $this->status === 'active'
            && $this->maturity_date
            && $this->maturity_date->lt(now()->startOfDay());
    }

    public function daysOverdue(): int
    {
        if (! $this->isOverdue()) {
            return 0;
        }

        return (int) floor(
            $this->maturity_date->copy()->startOfDay()
                ->diffInDays(now()->startOfDay(), false)
        );
    }

    public function daysTillMaturity(): int
    {
        if (! $this->maturity_date || $this->maturity_date->isPast()) {
            return 0;
        }

        return (int) max(0, now()->diffInDays($this->maturity_date, false));
    }

    public function isInLockPeriod(): bool
    {
        if (! $this->loan_date || ! $this->min_lock_months) {
            return false;
        }

        return now()->lt($this->loan_date->copy()->addMonths($this->min_lock_months));
    }

    public function minimumInterestAmount(): float
    {
        if (! $this->min_interest_months || ! $this->interest_rate_monthly) {
            return 0;
        }

        return round(
            (float) $this->principal_amount * ((float) $this->interest_rate_monthly / 100) * $this->min_interest_months,
            2
        );
    }

    public function canPreClose(): bool
    {
        return $this->status === 'active' && ! $this->isInLockPeriod();
    }

    /** A forfeiture notice has been sent for this loan. */
    public function forfeitureNoticeSent(): bool
    {
        return $this->forfeiture_notice_sent_at !== null;
    }

    /**
     * Where an active loan stands on the road to forfeiture: the one rule the loan page, the service guards and the
     * daily check all use (owner 2026-11-12, state-machine audit). Null for a loan that is not active.
     *   current     not yet past maturity
     *   grace       past maturity, within the loan's own grace days
     *   notice_due  grace over, no notice yet
     *   notice_sent notice out, its printed deadline not reached
     *   ready       the deadline on the notice has come: forfeiture may be executed
     */
    public function forfeitureStage(): ?string
    {
        if ($this->status !== 'active') {
            return null;
        }
        if ($this->forfeiture_notice_sent_at) {
            return today()->lt($this->forfeitureDeadline()) ? 'notice_sent' : 'ready';
        }
        if (! $this->maturity_date || ! today()->gt($this->maturity_date)) {
            return 'current';
        }

        return today()->gt(\Illuminate\Support\Carbon::parse($this->maturity_date)->addDays((int) $this->grace_period_days))
            ? 'notice_due' : 'grace';
    }

    /** A forfeiture notice may go out now (see forfeitureStage). */
    public function forfeitureNoticeDue(): bool
    {
        return $this->forfeitureStage() === 'notice_due';
    }

    /** Forfeiture may be executed now (see forfeitureStage); the service re-checks under the row lock. */
    public function canExecuteForfeit(): bool
    {
        return $this->forfeitureStage() === 'ready';
    }

    /**
     * The date printed on the forfeiture notice (owner 2026-11-12): forfeiture waits for it even if the shop's notice
     * period setting changes after the notice went out. Notices sent before the column existed fall back to the setting.
     */
    public function forfeitureDeadline(): ?\Illuminate\Support\Carbon
    {
        if ($this->forfeiture_deadline) {
            return \Illuminate\Support\Carbon::parse($this->forfeiture_deadline)->startOfDay();
        }
        if (! $this->forfeiture_notice_sent_at) {
            return null;
        }
        $noticeDays = (int) (\App\Models\Dhiran\DhiranSettings::getForShop($this->shop_id)->forfeiture_notice_days ?? 0);

        return \Illuminate\Support\Carbon::parse($this->forfeiture_notice_sent_at)->startOfDay()->addDays($noticeDays);
    }

    protected static function booted(): void
    {
        // Compound monthly rest: the capitalised (month-end unpaid) interest can never exceed the interest still owed.
        // One rule here covers every path that reduces interest (payments, releases, pre-close, renewal, forfeiture).
        static::saving(function (self $loan) {
            if ((float) $loan->interest_capitalised > (float) $loan->outstanding_interest) {
                $loan->interest_capitalised = max(0, (float) $loan->outstanding_interest);
            }
        });
    }

    /* ── Route Model Binding (tenant-scoped) ──────────── */

    public function resolveRouteBinding($value, $field = null)
    {
        // BelongsToShop global scope already filters by tenant. No auth() dep here.
        return $this->where($field ?? $this->getRouteKeyName(), $value)->firstOrFail();
    }

    /* ── Scopes ────────────────────────────────────────── */

    /** Shops whose loans the nightly jobs work on (cross-shop: console only). */
    public static function shopsWithActiveLoans(): \Illuminate\Support\Collection
    {
        return static::withoutGlobalScope('shop')->where('status', 'active')->distinct()->orderBy('shop_id')->pluck('shop_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('maturity_date')
            ->where('maturity_date', '<', now());
    }

    public function scopeDefaultRisk(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->whereNotNull('maturity_date')
            ->whereRaw('maturity_date + COALESCE(grace_period_days, 0) * INTERVAL \'1 day\' < CURRENT_DATE');
    }
}
