<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;

class SchemePayment extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'installment_number' => 'integer',
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
        return $this->belongsTo(SchemeEnrollment::class, 'enrollment_id');
    }

    public function cashTransaction()
    {
        return $this->belongsTo(CashTransaction::class, 'cash_transaction_id');
    }
}
