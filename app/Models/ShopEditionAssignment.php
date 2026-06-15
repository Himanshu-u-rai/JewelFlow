<?php

namespace App\Models;

use App\Models\Platform\ShopSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot row linking a Shop to one active (or historically-granted) edition.
 *
 * Named "Assignment" to avoid colliding with App\Support\ShopEdition, which
 * stays as the central helper/constants class. This model is only the
 * persistence layer for the shop_editions table.
 *
 * Active editions are rows where deactivated_at IS NULL. Revoked editions
 * keep their row with deactivated_at/deactivated_by/deactivation_reason
 * populated — useful for audit and for re-granting without losing history.
 *
 * `source` records WHY the edition is active:
 *   - subscription : backed by a paid product subscription (product_subscription_id set)
 *   - admin_grant  : granted by a platform admin (demo / pilot / internal / trial / support)
 *   - seed         : seeded by migration / shop_type auto-seed
 *
 * An admin_grant (or seed) edition is NEVER auto-revoked by a subscription lapse.
 */
class ShopEditionAssignment extends Model
{
    protected $table = 'shop_editions';

    /** Where an edition grant came from. */
    public const SOURCE_SUBSCRIPTION = 'subscription';
    public const SOURCE_ADMIN_GRANT  = 'admin_grant';
    public const SOURCE_SEED         = 'seed';

    protected $fillable = [
        'shop_id',
        'edition',
        'source',
        'product_subscription_id',
        'activated_at',
        'activated_by',
        'deactivated_at',
        'deactivated_by',
        'deactivation_reason',
    ];

    protected $casts = [
        'activated_at'   => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function productSubscription(): BelongsTo
    {
        return $this->belongsTo(ShopSubscription::class, 'product_subscription_id');
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function deactivatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deactivated_by');
    }

    public function scopeActive($query)
    {
        return $query->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }
}
