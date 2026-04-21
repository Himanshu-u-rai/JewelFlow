<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    use BelongsToShop, ImmutableLedger;

    protected $guarded = ['*'];

    public static function record(array $attributes): self
    {
        $model = new self();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }
}
