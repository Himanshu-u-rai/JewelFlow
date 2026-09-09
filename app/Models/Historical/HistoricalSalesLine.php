<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableWhenPublished;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a historical document. Optional — header-only bills (a total with
 * no breakdown) are a normal historical shape.
 *
 * Unlike the operational `InvoiceItem`, this model carries `shop_id` and the
 * fail-closed tenant scope. `InvoiceItem` has neither, which is precisely why
 * historical data could not safely share the operational tables.
 */
class HistoricalSalesLine extends Model
{
    use BelongsToShop;
    use ImmutableWhenPublished;

    /** V2 §4 — no default; the operator must decide before a metal value can be suggested. */
    public const BILLABLE_WEIGHT_GROSS = 'gross';

    public const BILLABLE_WEIGHT_NET = 'net';

    public const BILLABLE_WEIGHT_MANUAL = 'manual';

    public const BILLABLE_WEIGHT_BASES = [
        self::BILLABLE_WEIGHT_GROSS,
        self::BILLABLE_WEIGHT_NET,
        self::BILLABLE_WEIGHT_MANUAL,
    ];

    /**
     * What the rate printed on the paper bill MEANS. The V2 §4 formula
     * (`billable_weight × rate × fine_multiplier`) silently assumed every rate
     * was a 24K/999 reference — the live QuickBill convention, labelled
     * "Rate (pure 24K/999)". Real paper bills overwhelmingly print the rate for
     * the jewellery's own purity instead: a 22K bill at ₹6,200/g means
     * 15g × 6,200 = ₹93,000, NOT 15g × 6,200 × 22/24 = ₹85,250.
     *
     * Both readings are legitimate; nothing in a rate figure distinguishes
     * them, so the operator must say which. AS_PRINTED is the form default
     * because copying a bill verbatim is the common case; PURE_REFERENCE
     * preserves the original formula byte-for-byte when chosen.
     *
     * @see HistoricalCalculationSuggester::suggestMetalValue()
     */
    public const RATE_BASIS_AS_PRINTED = 'as_printed';

    public const RATE_BASIS_PURE_REFERENCE = 'pure_reference';

    public const RATE_BASES = [self::RATE_BASIS_AS_PRINTED, self::RATE_BASIS_PURE_REFERENCE];

    /** V2 §6 — the operator's explicit choice; never auto-applied from a shop setting. */
    public const WASTAGE_BASIS_PERCENT = 'percent';

    public const WASTAGE_BASIS_FLAT = 'flat';

    public const WASTAGE_BASES = [self::WASTAGE_BASIS_PERCENT, self::WASTAGE_BASIS_FLAT];

    public const DISCOUNT_TYPE_FIXED = 'fixed';

    public const DISCOUNT_TYPE_PERCENT = 'percent';

    public const DISCOUNT_TYPES = [self::DISCOUNT_TYPE_FIXED, self::DISCOUNT_TYPE_PERCENT];

    protected $guarded = ['*'];

    protected $casts = [
        'item_snapshot' => 'array',
        'stone_snapshot' => 'array',
        'raw_payload' => 'array',
        'quantity' => 'decimal:3',
        'gross_weight' => 'decimal:3',
        'net_weight' => 'decimal:3',
        'stone_weight' => 'decimal:3',
        'making_amount' => 'decimal:2',
        'rate_snapshot' => 'decimal:2',
        'line_total' => 'decimal:2',
        // Batch 3 calculation-contract columns (V2 §11).
        'billable_weight' => 'decimal:3',
        'hallmark_charge' => 'decimal:2',
        'rhodium_charge' => 'decimal:2',
        'other_charge' => 'decimal:2',
        'wastage_value' => 'decimal:2',
        'line_discount_value' => 'decimal:2',
        'calculation_state' => 'array',
    ];

    /**
     * A line inherits its parent document's lifecycle.
     *
     * The parent status is read WITHOUT the tenant scope: this is a safety
     * decision, not a data read, and a console context resolving the parent to
     * "not found" would otherwise downgrade a published line to editable. The
     * value read is a lifecycle enum, never financial data. The database trigger
     * enforces the same rule independently.
     */
    public function isPublishedRecord(): bool
    {
        $status = HistoricalSalesDocument::withoutTenant()
            ->whereKey($this->historical_sales_document_id)
            ->value('status');

        return $status !== null && $status !== HistoricalSalesDocument::STATUS_DRAFT;
    }

    /** Only the advisory inventory link may move after publication. */
    public static function publishedUpdatableColumns(): array
    {
        return ['item_id', 'updated_at'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HistoricalSalesDocument::class, 'historical_sales_document_id');
    }

    /**
     * Optional. Linking never creates an inventory item and never mutates
     * current stock; unlinking never disturbs `item_snapshot`.
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
