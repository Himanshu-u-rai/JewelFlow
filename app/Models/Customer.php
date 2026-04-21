<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'first_name',
        'last_name',
        'mobile',
        'email',
        'address',
        'date_of_birth',
        'anniversary_date',
        'wedding_date',
        'notes',
        'loyalty_points',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'anniversary_date' => 'date',
        'wedding_date' => 'date',
        'loyalty_points' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $customer): void {
            if (empty($customer->customer_code) && !empty($customer->shop_id)) {
                $customer->customer_code = BusinessIdentifierService::nextCounter((int) $customer->shop_id, BusinessIdentifierService::KEY_CUSTOMER);
            }
        });
    }
    public function goldBalance()
    {
        return \App\Models\CustomerGoldTransaction::where('shop_id', $this->shop_id)
            ->where('customer_id', $this->id)
            ->sum('fine_gold');
    }

    // Add this accessor so you can use $customer->name
    public function getNameAttribute()
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function goldTransactions()
    {
        return $this->hasMany(CustomerGoldTransaction::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function repairs()
    {
        return $this->hasMany(Repair::class);
    }

    public function loyaltyTransactions()
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }

    public function schemeEnrollments()
    {
        return $this->hasMany(SchemeEnrollment::class);
    }

    public function installmentPlans()
    {
        return $this->hasMany(InstallmentPlan::class);
    }

    public function dhiranLoans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Dhiran\DhiranLoan::class);
    }

    public function addLoyaltyPoints(int $points, ?int $invoiceId = null, string $description = 'Points earned', $expiresAt = null): LoyaltyTransaction
    {
        $this->increment('loyalty_points', $points);

        return $this->loyaltyTransactions()->create([
            'invoice_id' => $invoiceId,
            'type' => 'earn',
            'points' => $points,
            'description' => $description,
            'balance_after' => $this->loyalty_points,
            'expires_at' => $expiresAt,
        ]);
    }

    public function redeemLoyaltyPoints(int $points, ?int $invoiceId = null, string $description = 'Points redeemed'): LoyaltyTransaction
    {
        if ($points > $this->loyalty_points) {
            throw new \LogicException('Insufficient loyalty points');
        }

        $this->decrement('loyalty_points', $points);

        return $this->loyaltyTransactions()->create([
            'invoice_id' => $invoiceId,
            'type' => 'redeem',
            'points' => $points,
            'description' => $description,
            'balance_after' => $this->loyalty_points,
        ]);
    }

    public function upcomingOccasions(int $daysAhead = 30): array
    {
        $occasions = [];
        $now = now();
        $fields = ['date_of_birth' => 'Birthday', 'anniversary_date' => 'Anniversary', 'wedding_date' => 'Wedding Anniversary'];

        foreach ($fields as $field => $label) {
            if ($this->$field) {
                $next = $this->$field->copy()->year($now->year);
                if ($next->lt($now->copy()->startOfDay())) {
                    $next->addYear();
                }
                $daysUntil = $now->diffInDays($next, false);
                if ($daysUntil >= 0 && $daysUntil <= $daysAhead) {
                    $occasions[] = [
                        'type' => $label,
                        'date' => $next->toDateString(),
                        'days_until' => (int)$daysUntil,
                    ];
                }
            }
        }

        return $occasions;
    }
}
