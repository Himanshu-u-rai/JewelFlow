<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;

class SchemeRedemption extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    protected $casts = [
        'amount' => 'decimal:2',
        'principal_component' => 'decimal:2',
        'bonus_component' => 'decimal:2',
        'redeemed_at' => 'datetime',
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

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoicePayment()
    {
        return $this->belongsTo(InvoicePayment::class, 'invoice_payment_id');
    }
}
