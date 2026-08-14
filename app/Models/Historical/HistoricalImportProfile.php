<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A per-shop description of one source system's file layout.
 *
 * Batch 1 stores the configuration only — no parsing, mapping or inference
 * behaviour is implemented. `date_format` is mandatory and explicit on purpose:
 * 03/04/2023 is ambiguous, and guessing it silently moves a year of revenue
 * between months.
 */
class HistoricalImportProfile extends Model
{
    use BelongsToShop;

    public const LAYOUT_SINGLE_ROW_PER_LINE = 'single_row_per_line';
    public const LAYOUT_HEADER_DETAIL       = 'header_detail';
    public const LAYOUT_HEADER_ONLY         = 'header_only';

    protected $fillable = [
        'shop_id',
        'name',
        'source_system',
        'layout_type',
        'mapping',
        'date_format',
        'tax_defaults',
        'making_defaults',
        'is_active',
    ];

    protected $casts = [
        'mapping'         => 'array',
        'tax_defaults'    => 'array',
        'making_defaults' => 'array',
        'is_active'       => 'boolean',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(HistoricalImportBatch::class, 'historical_import_profile_id');
    }
}
