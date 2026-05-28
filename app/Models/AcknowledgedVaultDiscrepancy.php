<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class AcknowledgedVaultDiscrepancy extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'reconciliation_run_id',
        'acknowledged_by_user_id',
        'acknowledged_at',
        'acknowledgement_reason',
        'discrepancy_amount_at_ack',
    ];

    protected $casts = [
        'acknowledged_at'            => 'datetime',
        'discrepancy_amount_at_ack'  => 'decimal:4',
    ];

    // No updates allowed — append only
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('AcknowledgedVaultDiscrepancy records are append-only.');
        });
    }

    public function reconciliationRun(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(VaultReconciliationRun::class);
    }

    public function acknowledgedBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by_user_id');
    }
}
