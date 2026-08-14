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

    protected $guarded = ['*'];

    protected $casts = [
        'item_snapshot'  => 'array',
        'stone_snapshot' => 'array',
        'raw_payload'    => 'array',
        'quantity'       => 'decimal:3',
        'gross_weight'   => 'decimal:3',
        'net_weight'     => 'decimal:3',
        'stone_weight'   => 'decimal:3',
        'making_amount'  => 'decimal:2',
        'rate_snapshot'  => 'decimal:2',
        'line_total'     => 'decimal:2',
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
