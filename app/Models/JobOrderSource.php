<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * One leg of a job order's metal-source SET — the authoritative record of where
 * a job's metal came from. A job has 0 legs (labor-only / metal_source='none')
 * or N legs (vault / karigar_held / customer_advance, possibly mixed).
 *
 * The job's `metal_source` label is DERIVED from this set (see
 * JobOrder::getMetalSourceAttribute) and is never stored.
 */
class JobOrderSource extends Model
{
    use BelongsToShop;

    public const TYPE_VAULT            = 'vault';
    public const TYPE_KARIGAR_HELD     = 'karigar_held';
    public const TYPE_CUSTOMER_ADVANCE = 'customer_advance';

    protected $fillable = [
        'shop_id',
        'job_order_id',
        'source_type',
        'metal_lot_id',
        'customer_id',
        'gross_weight',
        'fine_weight',
        'purity',
        'metal_movement_id',
    ];

    protected $casts = [
        'gross_weight' => 'decimal:6',
        'fine_weight'  => 'decimal:6',
        'purity'       => 'decimal:2',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function metalLot()
    {
        return $this->belongsTo(MetalLot::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function metalMovement()
    {
        return $this->belongsTo(MetalMovement::class);
    }
}
