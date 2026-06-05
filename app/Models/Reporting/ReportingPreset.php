<?php

namespace App\Models\Reporting;

use App\Models\Concerns\BelongsToShop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Shop-wide named export preset (REPORT_EXPORT_IMPLEMENTATION_PLAN.md §0.2, frozen §8/§21).
 *
 * Only `scope = shop` is written/exposed today; `scope`/`owner_id` exist so
 * user-scoped presets can be enabled later without a migration (frozen §21).
 * Saving a preset never elevates privileges — the sensitive gate is re-checked
 * at export time for the running user.
 */
class ReportingPreset extends Model
{
    use BelongsToShop;

    public const SCOPE_SHOP = 'shop';
    public const SCOPE_USER = 'user';

    protected $fillable = [
        'shop_id', 'name', 'report_key',
        'profile', 'columns', 'filters', 'format',
        'scope', 'owner_id', 'created_by',
    ];

    protected $casts = [
        'columns' => 'array',
        'filters' => 'array',
    ];

    protected $attributes = [
        'scope' => self::SCOPE_SHOP,
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
