<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VaultReconciliationRun extends Model
{
    use BelongsToShop;
    use ImmutableLedger;

    // Only workflow status may advance after creation.
    // All audit fields (discrepancy_lots, run_at, run_by) are permanent record.
    protected $allowedUpdateColumns = ['status'];

    public const STATUS_CLEAN             = 'clean';
    public const STATUS_DISCREPANCY_FOUND = 'discrepancy_found';
    public const STATUS_CORRECTED         = 'corrected';

    protected $fillable = [
        'shop_id',
        'run_at',
        'run_by',
        'status',
        'discrepancy_lots',
        'discrepancies_found',
        'largest_delta_g',
        'notes',
    ];

    protected $casts = [
        'run_at'              => 'datetime',
        'discrepancy_lots'    => 'array',
        'discrepancies_found' => 'integer',
        'largest_delta_g'     => 'decimal:3',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function runByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }
}
