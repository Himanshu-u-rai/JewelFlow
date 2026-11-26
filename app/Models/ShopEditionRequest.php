<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Platform\PlatformAdmin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A shop owner's request to add or remove an edition.
 *
 * Lifecycle: pending → approved | denied | cancelled.
 *
 * `remove` requests are rare because shop owners can self-serve most removes
 * via the /settings/services controller.
 */
class ShopEditionRequest extends Model
{
    use BelongsToShop;

    public const ACTION_ADD    = 'add';
    public const ACTION_REMOVE = 'remove';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_DENIED    = 'denied';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'shop_id',
        'user_id',
        'action',
        'edition',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Decides a request under its row lock, only while it is still pending: approve, deny and the owner's cancel
     * all go through here, so the first decision stands and the others see it (audit N4). False when it was
     * decided meanwhile.
     *
     * @param  callable(self): void  $decide
     */
    public static function decidePending(int $id, callable $decide): bool
    {
        return DB::transaction(function () use ($id, $decide): bool {
            $request = static::withoutGlobalScope('shop')->with('shop')->lockForUpdate()->find($id);
            if (! $request?->isPending()) {
                return false;
            }
            $decide($request);

            return true;
        });
    }
}
