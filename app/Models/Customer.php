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
        // Compliance fields
        'pan',
        'id_number',
        'compliance_verified_at',
        'compliance_verified_by',
        'consent_given_at',
        'consent_given_by',
    ];

    protected $casts = [
        'date_of_birth'          => 'date',
        'anniversary_date'       => 'date',
        'wedding_date'           => 'date',
        'loyalty_points'         => 'integer',
        'compliance_verified_at' => 'datetime',
        'consent_given_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $customer): void {
            if (empty($customer->customer_code) && !empty($customer->shop_id)) {
                $customer->customer_code = BusinessIdentifierService::nextCounter((int) $customer->shop_id, BusinessIdentifierService::KEY_CUSTOMER);
            }
        });

        // Any new customer (form, POS quick-add, or quick-bill auto-add) must
        // invalidate the POS customer-search cache so it shows up immediately.
        static::created(function (self $customer): void {
            if (! empty($customer->shop_id)) {
                \Illuminate\Support\Facades\Cache::forget(
                    \App\Services\PosSearchCacheService::customersCacheKey((int) $customer->shop_id, null)
                );
            }
        });
    }
    public function goldBalance()
    {
        return \App\Models\CustomerGoldTransaction::where('shop_id', $this->shop_id)
            ->where('customer_id', $this->id)
            ->sum('fine_gold');
    }

    /**
     * Find an existing customer by mobile within the current shop, or create one
     * from a typed walk-in name. Returns null when no mobile is supplied (we do
     * not create directory records for nameless/numberless one-off walk-ins).
     *
     * Used by the Quick Bill flow so billed walk-ins with a phone number build
     * customer history, matching how POS already requires a customer record.
     * Mobile is the match key, so repeat buyers are reused, not duplicated.
     */
    public static function findOrCreateByMobile(?string $name, ?string $mobile, ?string $address = null): ?self
    {
        $mobile = trim((string) $mobile);
        if ($mobile === '') {
            return null;
        }

        $existing = static::query()->where('mobile', $mobile)->first();
        if ($existing) {
            return $existing;
        }

        $name = trim((string) $name);
        $parts = $name !== '' ? preg_split('/\s+/', $name, 2) : [];
        $first = $parts[0] ?? null;
        $last = $parts[1] ?? null;

        // Name is optional; fall back to a neutral label so the record is valid.
        if (($first ?? '') === '') {
            $first = 'Walk-in';
        }

        $address = trim((string) $address);

        return static::create([
            'first_name' => $first,
            'last_name'  => $last,
            'mobile'     => $mobile,
            'address'    => $address !== '' ? $address : null,
        ]);
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

    public function kycDocuments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(KycDocument::class);
    }

    public function complianceSnapshots(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(InvoiceComplianceSnapshot::class);
    }

    public function complianceAlerts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ComplianceAlert::class);
    }

    public function hasPan(): bool
    {
        return !empty($this->pan);
    }

    public function isComplianceVerified(): bool
    {
        return !empty($this->compliance_verified_at);
    }

    public function complianceStatus(): string
    {
        return !empty($this->pan) ? 'compliant' : 'missing_pan';
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
