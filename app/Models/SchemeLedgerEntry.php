<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;

class SchemeLedgerEntry extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'meta' => 'array',
    ];

    public static function record(array $attributes): self
    {
        $model = new self();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    public function enrollment()
    {
        return $this->belongsTo(SchemeEnrollment::class, 'scheme_enrollment_id');
    }

    public function payment()
    {
        return $this->belongsTo(SchemePayment::class, 'scheme_payment_id');
    }

    public function redemption()
    {
        return $this->belongsTo(SchemeRedemption::class, 'scheme_redemption_id');
    }
}
