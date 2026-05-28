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

        // Auto-derive metal_type from the associated lot when the caller did
        // not set it explicitly. The destination lot (to_lot) wins; fall back
        // to the source lot (from_lot). This keeps the per-metal ledger
        // accurate without forcing every caller to pass metal_type.
        if (empty($model->metal_type)) {
            $derived = null;

            if (! empty($attributes['to_lot_id'])) {
                $derived = MetalLot::withoutTenant()->whereKey($attributes['to_lot_id'])->value('metal_type');
            }

            if ($derived === null && ! empty($attributes['from_lot_id'])) {
                $derived = MetalLot::withoutTenant()->whereKey($attributes['from_lot_id'])->value('metal_type');
            }

            if ($derived !== null) {
                $model->metal_type = $derived;
            }
        }

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
