<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editable staging for one source row.
 *
 * Rows are freely correctable and deletable while the batch is unpublished. They
 * are retained after publication as the audit trail that ties a published
 * document back to the exact source row it came from — no automatic deletion.
 *
 * Batch 1 stores rows; it does not parse, map or validate them. `severity` and
 * `validation_status` exist so the importer has somewhere to put its findings.
 */
class HistoricalImportRow extends Model
{
    use BelongsToShop;

    public const SEVERITY_OK      = 'ok';
    public const SEVERITY_INFO    = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ERROR   = 'error';

    public const VALIDATION_PENDING = 'pending';
    public const VALIDATION_VALID   = 'valid';
    public const VALIDATION_INVALID = 'invalid';
    public const VALIDATION_SKIPPED = 'skipped';

    protected $fillable = [
        'shop_id',
        'historical_import_batch_id',
        'source_sheet',
        'source_row_number',
        'grouping_key',
        'original_payload',
        'normalized_payload',
        'severity',
        'validation_status',
        'messages',
        'historical_sales_document_id',
    ];

    protected $casts = [
        'original_payload'   => 'array',
        'normalized_payload' => 'array',
        'messages'           => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HistoricalImportBatch::class, 'historical_import_batch_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(HistoricalSalesDocument::class, 'historical_sales_document_id');
    }
}
