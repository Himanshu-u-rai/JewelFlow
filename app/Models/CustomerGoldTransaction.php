<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class CustomerGoldTransaction extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    protected $casts = [
        'fine_gold' => 'float',
        'gross_weight' => 'float',
        'purity' => 'float',
    ];

    public static function record(array $attributes): self
    {
        $model = new self();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
