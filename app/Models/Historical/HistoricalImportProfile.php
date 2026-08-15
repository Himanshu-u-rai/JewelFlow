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

    /** Layout A: one row per invoice. */
    public const LAYOUT_HEADER_ONLY         = 'header_only';
    /** Layout B: one row per line item, header fields repeated. */
    public const LAYOUT_SINGLE_ROW_PER_LINE = 'single_row_per_line';
    /** Layout C: one sheet of headers joined to one sheet of details. */
    public const LAYOUT_HEADER_DETAIL       = 'header_detail';

    public const LAYOUTS = [
        self::LAYOUT_HEADER_ONLY,
        self::LAYOUT_SINGLE_ROW_PER_LINE,
        self::LAYOUT_HEADER_DETAIL,
    ];

    /**
     * A source column the operator saw and consciously did not map. Both values
     * keep the column visible in preview; neither is "we never noticed it".
     * `informational` additionally preserves the raw cell into raw_payload.
     */
    public const DECISION_IGNORED       = 'ignored';
    public const DECISION_INFORMATIONAL = 'informational';

    /** Explicit only. See the migration for why a sniffed separator is unsafe. */
    public const SEPARATORS = ['.', ',', ' ', "'", ''];

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
        'header_row',
        'decimal_separator',
        'thousands_separator',
        'tax_mode',
        'sheets',
        'column_decisions',
    ];

    protected $casts = [
        'mapping'          => 'array',
        'tax_defaults'     => 'array',
        'making_defaults'  => 'array',
        'is_active'        => 'boolean',
        'header_row'       => 'integer',
        'sheets'           => 'array',
        'column_decisions' => 'array',
    ];

    /** The source column mapped to a canonical field, or null. */
    public function sourceColumnFor(string $field): ?string
    {
        $value = ($this->mapping ?? [])[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function sheetFor(string $role): ?string
    {
        $value = ($this->sheets ?? [])[$role] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(HistoricalImportBatch::class, 'historical_import_profile_id');
    }
}
