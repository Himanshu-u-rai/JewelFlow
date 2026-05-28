<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 2A — Per-shop registry of stone types.
 *
 * Mirrors the gst_categories / shop_metal_purity_profiles pattern.
 * Mutable by operator via Settings → Stones (Phase 2B+ UI). The model
 * intentionally lacks `ImmutableLedger` because stone type lists evolve —
 * a shop can rename "Sapphire" to "Blue Sapphire" without history loss.
 */
class StoneType extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'code',
        'label',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];
}
