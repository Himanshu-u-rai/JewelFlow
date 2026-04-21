<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;

class MetalMovement extends Model
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

    public function fromLot()
    {
        return $this->belongsTo(\App\Models\MetalLot::class, 'from_lot_id');
    }

    public function toLot()
    {
        return $this->belongsTo(\App\Models\MetalLot::class, 'to_lot_id');
    }

    public function item()
    {
        return $this->belongsTo(\App\Models\Item::class, 'reference_id');
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice::class, 'reference_id');
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
