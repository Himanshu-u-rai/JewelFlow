<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class JobOrderIssuance extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'job_order_id',
        'metal_lot_id',
        'metal_movement_id',
        'gross_weight',
        'fine_weight',
        'purity',
    ];

    protected $casts = [
        'gross_weight' => 'decimal:6',
        'fine_weight' => 'decimal:6',
        'purity' => 'decimal:2',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function metalLot()
    {
        return $this->belongsTo(MetalLot::class);
    }

    public function metalMovement()
    {
        return $this->belongsTo(MetalMovement::class);
    }
}
