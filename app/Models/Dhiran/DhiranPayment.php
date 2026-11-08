<?php

namespace App\Models\Dhiran;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class DhiranPayment extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    protected $casts = [
        'payment_date'               => 'date',
        'amount'                     => 'decimal:2',
        'interest_component'         => 'decimal:2',
        'penalty_component'          => 'decimal:2',
        'principal_component'        => 'decimal:2',
        'processing_fee_component'   => 'decimal:2',
        'outstanding_principal_after' => 'decimal:2',
        'outstanding_interest_after' => 'decimal:2',
        'outstanding_penalty_after'  => 'decimal:2',
    ];

    /** How a payment method reads on screen and on paper ("UPI", "Bank Transfer"). */
    public static function methodLabel(?string $method): string
    {
        return match ($method) {
            null, '' => '—',
            'upi' => 'UPI',
            default => ucwords(str_replace('_', ' ', $method)),
        };
    }

    public static function record(array $attributes): self
    {
        $model = new self();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    /* ── Relationships ─────────────────────────────────── */

    public function loan()
    {
        return $this->belongsTo(DhiranLoan::class, 'dhiran_loan_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashEntry()
    {
        return $this->hasOne(DhiranCashEntry::class);
    }
}
