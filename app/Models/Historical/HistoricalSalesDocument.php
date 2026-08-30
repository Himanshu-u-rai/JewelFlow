<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableWhenPublished;
use App\Models\Customer;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sale the shop made BEFORE JewelFlow. A record, not a transaction.
 *
 * This model deliberately does NOT extend, mimic or share anything with
 * `Invoice`. It is not registered with InvoiceObserver or
 * InvoiceNotificationObserver, dispatches no sale events, and touches no
 * accounting, stock, metal, loyalty or payment service. Persisting one writes
 * exactly two tables: this one and `historical_sales_lines`.
 *
 * `$guarded = ['*']` on purpose — every field is evidence copied from a paper
 * bill, so it is force-filled by the importer rather than mass-assigned from a
 * request. Same posture as InvoiceItem.
 */
class HistoricalSalesDocument extends Model
{
    use BelongsToShop;
    use ImmutableWhenPublished;

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_VOID       = 'void';

    /** R1 records completed sales only. Expandable via the DB check constraint. */
    public const TYPE_SALE_INVOICE = 'sale_invoice';

    public const TAX_MODE_INCLUSIVE      = 'inclusive';
    public const TAX_MODE_EXCLUSIVE      = 'exclusive';
    public const TAX_MODE_UNKNOWN        = 'unknown';
    public const TAX_MODE_NOT_APPLICABLE = 'not_applicable';

    public const TAX_COMPLETE       = 'complete';
    public const TAX_SUMMARY_ONLY   = 'summary_only';
    public const TAX_UNKNOWN        = 'unknown';
    public const TAX_NOT_APPLICABLE = 'not_applicable';

    /**
     * The only two opening-balance overlap resolutions. Deliberately no third
     * "not applicable" option — a HIGH overlap must be looked at, not waved off.
     */
    public const OPENING_BALANCE_RESOLUTION_INCLUDED = 'included_in_opening_balance';
    public const OPENING_BALANCE_RESOLUTION_SEPARATE = 'separate_from_opening_balance';

    /** V2 §11/§6 — bill-level discount, mirrors the line-level enum below. */
    public const DISCOUNT_TYPE_FIXED   = 'fixed';
    public const DISCOUNT_TYPE_PERCENT = 'percent';
    public const DISCOUNT_TYPES = [self::DISCOUNT_TYPE_FIXED, self::DISCOUNT_TYPE_PERCENT];

    /**
     * No real interstate CGST/SGST/IGST logic exists anywhere live (Agent B/E) —
     * this is a mechanical operator choice, not a place-of-supply computation.
     */
    public const TAX_SPLIT_CGST_SGST = 'cgst_sgst';
    public const TAX_SPLIT_IGST      = 'igst';
    public const TAX_SPLIT_TYPES = [self::TAX_SPLIT_CGST_SGST, self::TAX_SPLIT_IGST];

    /**
     * UI contract, kept beside the data so no screen can invent its own wording.
     * A historical record is never presented as a JewelFlow-issued invoice.
     */
    public const BADGE                     = 'HISTORICAL';
    public const NUMBER_LABEL              = 'Original Invoice Number';
    public const NUMBER_UNAVAILABLE_LABEL  = 'Original invoice number unavailable';
    public const RECORD_DISCLAIMER         = 'Historical record — not an invoice issued by JewelFlow';

    protected $guarded = ['*'];

    protected $casts = [
        'document_date'                => 'date',
        'customer_snapshot'            => 'array',
        'tax_snapshot'                 => 'array',
        'raw_payload'                  => 'array',
        'taxable_amount'               => 'decimal:2',
        'discount_snapshot'            => 'decimal:2',
        'rounding_snapshot'            => 'decimal:2',
        'grand_total'                  => 'decimal:2',
        'paid_amount_snapshot'         => 'decimal:2',
        'outstanding_amount_snapshot'  => 'decimal:2',
        'metal_value'                  => 'decimal:2',
        'stone_value'                  => 'decimal:2',
        'making_amount'                => 'decimal:2',
        'opening_balance_overlap'      => 'boolean',
        'opening_balance_resolved_at'  => 'datetime',
        'cutover_warning_acknowledged' => 'boolean',
        'voided_at'                    => 'datetime',
        'imported_at'                  => 'datetime',
        'published_at'                 => 'datetime',
        // Batch 3 calculation-contract columns (V2 §11).
        'bill_discount_value'          => 'decimal:2',
        'cgst_amount'                  => 'decimal:2',
        'sgst_amount'                  => 'decimal:2',
        'igst_amount'                  => 'decimal:2',
        'cess_amount'                  => 'decimal:2',
        'offer_discount_snapshot'      => 'decimal:2',
        'advance_credit_amount'        => 'decimal:2',
        'calculation_state'            => 'array',
    ];

    // ---------------------------------------------------------------- lifecycle

    /**
     * Reads the PERSISTED status, not the pending one. Publishing a draft is a
     * draft-state action — if this looked at the in-memory value, the very act of
     * setting `status = published` would trip the immutability rule it is meant
     * to switch on.
     */
    public function isPublishedRecord(): bool
    {
        $status = $this->exists ? $this->getOriginal('status') : $this->status;

        return $status !== null && $status !== self::STATUS_DRAFT;
    }

    /**
     * The only columns that may move after publication. Note the absence of every
     * money, tax, weight, snapshot and identity column — mirrors the allow-list
     * in the `historical_sales_documents_guard()` trigger.
     */
    public static function publishedUpdatableColumns(): array
    {
        return [
            'status',
            'customer_id',
            'superseded_by_document_id',
            'void_reason',
            'voided_by',
            'voided_at',
            'published_by',
            'published_at',
            'updated_at',
        ];
    }

    // ------------------------------------------------------------- presentation

    /**
     * What the operator sees. Rule: the client's printed number, verbatim — never
     * the internal reference, never a generated JewelFlow number.
     */
    public function displayNumber(): string
    {
        return $this->original_document_number ?? self::NUMBER_UNAVAILABLE_LABEL;
    }

    public function hasOriginalNumber(): bool
    {
        return $this->original_document_number !== null;
    }

    /** True when the shop's tax data is too thin to be shown as a figure. */
    public function taxIsUnknown(): bool
    {
        return $this->tax_completeness === self::TAX_UNKNOWN;
    }

    // ---------------------------------------------------------------- relations

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HistoricalImportBatch::class, 'historical_import_batch_id');
    }

    /** Optional and advisory: the snapshot, not this link, is the evidence. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(HistoricalSalesLine::class, 'historical_sales_document_id');
    }

    /**
     * Per-tender payment rows (V2 §7). Never confuse with `InvoicePayment`:
     * these rows never touch the cashbook, bank ledger or store credit.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(HistoricalSalesPayment::class, 'historical_sales_document_id');
    }

    public function revises(): BelongsTo
    {
        return $this->belongsTo(self::class, 'revises_document_id');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_document_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function openingBalanceResolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opening_balance_resolved_by');
    }
}
