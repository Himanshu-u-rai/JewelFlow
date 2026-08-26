<?php

namespace App\Models;

use App\Models\Concerns\ArchivableParty;
use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\CanonicalisesMobileNumbers;
use App\Services\BusinessIdentifierService;
use App\Support\Mobile;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use BelongsToShop, ArchivableParty, CanonicalisesMobileNumbers;

    /** @var array<int, string> */
    protected static array $mobileColumns = ['mobile'];

    /**
     * MASTERS PART 3: `is_active` is deliberately NOT fillable. Lifecycle is
     * changed only by the explicit archive/reactivate endpoints (which use
     * forceFill), so no edit form or crafted request can flip it. New customers
     * are active via the column's DB default.
     */
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
        'is_active'              => 'boolean',
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
     * The one canonical spelling of a customer mobile: the ten-digit national
     * number, per App\Support\Mobile — which is where the rules and the reasons
     * for them live. Kept here as the name every call site already uses.
     *
     * Every surface that reads or writes `customers.mobile` must agree on this,
     * or the (shop_id, mobile) unique index enforces nothing useful — it only
     * makes the *stored string* unique, not the human.
     */
    public static function normalizeMobile(?string $mobile): ?string
    {
        return Mobile::normalize($mobile);
    }

    /**
     * What to actually store in `customers.mobile`.
     *
     * Canonical form, or EMPTY for anything that is not a mobile number. It used
     * to fall back to the raw string on the argument that dropping a walk-in's
     * only contact detail was worse than storing a number matching would not
     * recognise. That argument does not survive contact with the column: a value
     * stored there is indistinguishable from a real one, so "at least we kept it"
     * means a permanent unmatched row, a duplicate customer, and an SMS that
     * silently goes nowhere.
     *
     * Every form path now validates with IndianMobileRule, so this returns ''
     * only for the file-driven paths (CSV import), where the caller is expected
     * to skip the row rather than insert junk. Use it for the dedupe LOOKUP as
     * well as the insert — normalising only one of the two turns a silent
     * duplicate into a unique-index 500.
     */
    public static function storableMobile(?string $mobile): string
    {
        return static::normalizeMobile($mobile) ?? '';
    }

    /**
     * The one way to look a customer up by mobile. Canonical first, legacy second.
     *
     * Canonicalising the write side alone would have been half a fix: rows
     * written before it still hold '+91 98123 00099', and an exact lookup for
     * '9812300099' cannot see them — so the same buyer gets a second record,
     * which is the very bug canonicalisation was meant to end.
     *
     * The obvious fix is a backfill. We do not rewrite customer data, so this
     * closes the gap on the READ side only: the stored spelling is left exactly
     * as the shop typed it, forever, and the lookup is taught to see through it.
     * Nothing here writes.
     *
     * ponytail: the fallback is a per-shop scan of non-canonical rows only, and
     * it is permanent rather than draining, because nothing repairs the rows it
     * finds. That is the deliberate trade for not touching stored data. If it
     * ever shows up in a profile, the upgrade is a STORED generated column on
     * last-10-digits plus an index — which is derived data, not a rewrite of
     * anything the shop typed.
     */
    public static function resolveByMobile(?string $mobile): ?self
    {
        $canonical = static::normalizeMobile($mobile);

        // Deliberately NOT storableMobile(): storing is strict now (a value that
        // is not a mobile number stores as nothing), but LOOKING UP has to stay
        // lenient, or a legacy row holding a nine-digit scrap becomes permanently
        // unreachable — searchable only by a value the search refuses to accept.
        // Reads do not create bad data; refusing to read it only hides it.
        $needle = $canonical ?? trim((string) $mobile);

        if ($needle === '') {
            return null;
        }

        // Index hit. Every row written since canonicalisation lands here, as
        // does every legacy row that was already stored clean.
        $exact = static::query()->where('mobile', $needle)->first();
        if ($exact || $canonical === null) {
            // No canonical form means there is nothing to match loosely against
            // — an unparseable mobile is only ever equal to itself.
            return $exact;
        }

        // Only rows that cannot already be canonical are worth comparing. A
        // stored value of exactly ten characters either IS the canonical form
        // (found above) or has too few digits to normalise to anything, so
        // skipping it is correctness, not just an optimisation.
        return static::query()
            ->whereRaw('LENGTH(mobile) <> 10')
            ->get()
            ->first(fn (self $c): bool => static::normalizeMobile($c->mobile) === $canonical);
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
        // Normalise before both the lookup AND the insert. The (shop_id, mobile)
        // unique index only guarantees "one string, one customer" — it cannot
        // know that '+91 98123 00099' and '9812300099' are the same human. Quick
        // Bill validates this field as `max:20`, so without this the same buyer
        // gets a second record and neither the customer form (digits:10) nor
        // historical matching can ever find the Quick Bill copy.
        $mobile = static::storableMobile($mobile);
        if ($mobile === '') {
            return null;
        }

        $existing = static::resolveByMobile($mobile);
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
