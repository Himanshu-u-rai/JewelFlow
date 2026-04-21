<?php

namespace App\Models\Dhiran;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DhiranCashEntry extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    protected $casts = [
        'amount'     => 'decimal:2',
        'entry_date' => 'date',
    ];

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

    public function payment()
    {
        return $this->belongsTo(DhiranPayment::class, 'dhiran_payment_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /* ── Scopes ────────────────────────────────────────── */

    public function scopeInflows(Builder $query): Builder
    {
        return $query->where('type', 'in');
    }

    public function scopeOutflows(Builder $query): Builder
    {
        return $query->where('type', 'out');
    }
}
