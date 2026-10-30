<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class InstallmentPlan extends Model
{
    use BelongsToShop;

    /**
     * A bill on an active plan is paid only through the plan: money taken on
     * the invoice itself lowers the invoice due but not remaining_amount, so
     * the EMI page would ask for it again.
     */
    public const COLLECT_THROUGH_PLAN = 'This bill is on EMI. Collect the due through its EMI plan.';

    protected $fillable = [
        'invoice_id',
        'customer_id',
        'total_amount',
        'principal_amount',
        'down_payment',
        'interest_rate_annual',
        'interest_amount',
        'total_payable',
        'remaining_amount',
        'emi_amount',
        'total_emis',
        'emis_paid',
        'next_due_date',
        'status',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'principal_amount' => 'decimal:2',
        'down_payment' => 'decimal:2',
        'interest_rate_annual' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'total_payable' => 'decimal:2',
        'remaining_amount' => 'decimal:2',
        'emi_amount' => 'decimal:2',
        'total_emis' => 'integer',
        'emis_paid' => 'integer',
        'next_due_date' => 'date',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments()
    {
        return $this->hasMany(InstallmentPayment::class, 'plan_id');
    }

    /** The invoice's active plan, if its due is being paid by EMI. */
    public static function activeFor(Invoice $invoice): ?self
    {
        return static::withoutTenant()
            ->where('shop_id', $invoice->shop_id)
            ->where('invoice_id', $invoice->id)
            ->where('status', 'active')
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'active')
                     ->where('next_due_date', '<', now()->toDateString());
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function remainingAmount(): float
    {
        return max(0, (float) $this->remaining_amount);
    }

    public function remainingEmis(): int
    {
        return max(0, $this->total_emis - $this->emis_paid);
    }
}
