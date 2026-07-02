<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supplier's (vendor's) opening payable/receivable, posted at batch lock.
 * Read by a minimal supplier-outstanding view. Ongoing supplier ledger is out
 * of scope.
 */
class SupplierOpeningBalance extends Model
{
    use BelongsToShop;

    public const DIRECTION_PAYABLE = 'payable';       // shop owes supplier
    public const DIRECTION_RECEIVABLE = 'receivable'; // supplier owes shop

    protected $fillable = [
        'shop_id',
        'vendor_id',
        'onboarding_batch_id',
        'direction',
        'amount',
        'as_of_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'as_of_date' => 'date',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
