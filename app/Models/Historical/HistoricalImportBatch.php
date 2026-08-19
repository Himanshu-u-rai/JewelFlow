<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use App\Models\Shop;
use App\Models\User;
use App\Services\Historical\HistoricalOpeningBalanceEvaluator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One historical import run, and the publish/rollback boundary.
 *
 * draft ⇄ review → publishing → published   (+ → cancelled from draft/review)
 *
 * `publishing` is the atomic claim: the transition is made with a conditional
 * UPDATE so two concurrent publish requests cannot both proceed. It can fall
 * back to `review` if the run fails, so a crash does not strand the batch.
 *
 * Unlike OnboardingBatch there is no one-active-batch-per-shop rule: a shop
 * legitimately migrates several years from several files, and those drafts
 * coexist. Only the publish claim needs to be exclusive.
 */
class HistoricalImportBatch extends Model
{
    use BelongsToShop;

    public const STATUS_DRAFT      = 'draft';
    public const STATUS_REVIEW     = 'review';
    public const STATUS_PUBLISHING = 'publishing';
    public const STATUS_PUBLISHED  = 'published';
    public const STATUS_CANCELLED  = 'cancelled';

    /** States in which staged rows and draft documents may still be changed. */
    public const EDITABLE = [self::STATUS_DRAFT, self::STATUS_REVIEW];

    /** Terminal states. `published` additionally cannot be deleted. */
    public const TERMINAL = [self::STATUS_PUBLISHED, self::STATUS_CANCELLED];

    protected $fillable = [
        'shop_id',
        'historical_import_profile_id',
        'label',
        'source_system',
        'source_file_name',
        'cutover_date',
        'totals_snapshot',
        'source_file_disk',
        'source_file_path',
        'layout_type',
        'date_format',
    ];

    protected $casts = [
        'cutover_date'             => 'date',
        'totals_snapshot'          => 'array',
        'published_at'             => 'datetime',
        'cancelled_at'             => 'datetime',
        'preview_summary'          => 'array',
        'duplicate_resolutions'    => 'array',
        'preview_generated_at'     => 'datetime',
        'warnings_acknowledged_at' => 'datetime',
        'blocking_count'           => 'integer',
        'warning_count'            => 'integer',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $batch): void {
            if ($batch->status === self::STATUS_PUBLISHED) {
                throw new LogicException(
                    "Historical import batch #{$batch->id} is published and cannot be deleted."
                );
            }
        });
    }

    public function isEditable(): bool
    {
        return in_array($this->status, self::EDITABLE, true);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function hasBlockingErrors(): bool
    {
        return (int) $this->blocking_count > 0;
    }

    public function warningsAcknowledged(): bool
    {
        return $this->warnings_acknowledged_at !== null;
    }

    /**
     * The publish gate, in one place so the controller, the button and the test
     * cannot disagree. Preview must have been generated: publishing a batch whose
     * counters were never computed is publishing an unreviewed batch.
     */
    public function blockedFromPublishing(HistoricalOpeningBalanceEvaluator $evaluator = new HistoricalOpeningBalanceEvaluator()): ?string
    {
        return match (true) {
            $this->isPublished()                            => 'This batch is already published.',
            ! $this->isEditable()                           => 'This batch is not in a publishable state.',
            $this->preview_generated_at === null            => 'Generate the reconciliation preview before publishing.',
            $this->hasBlockingErrors()                      => 'Resolve all blocking errors before publishing.',
            $this->warning_count > 0
                && ! $this->warningsAcknowledged()          => 'Acknowledge the outstanding warnings before publishing.',
            $this->hasUnresolvedHighOpeningBalanceOverlap($evaluator)
                                                             => 'A linked customer has a HIGH-risk opening-balance overlap. Resolve it on the document before publishing.',
            default                                         => null,
        };
    }

    /** Per-document: only a HIGH overlap (not MEDIUM) blocks, and only while unresolved. */
    public function hasUnresolvedHighOpeningBalanceOverlap(HistoricalOpeningBalanceEvaluator $evaluator): bool
    {
        return $this->documents()
            ->where('status', HistoricalSalesDocument::STATUS_DRAFT)
            // Native (non-emulated) pgsql prepares reject `boolean = integer` — Laravel's
            // Connection::prepareBindings() casts PHP bool to int before binding. Same
            // whereRaw workaround already used for `is_active` elsewhere in this codebase
            // (BullionVaultController, QuickBillController).
            ->whereRaw('opening_balance_overlap = true')
            ->whereNull('opening_balance_resolution')
            ->get()
            ->contains(fn (HistoricalSalesDocument $document): bool => $evaluator->evaluate($document) === HistoricalOpeningBalanceEvaluator::HIGH);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(HistoricalImportProfile::class, 'historical_import_profile_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(HistoricalImportRow::class, 'historical_import_batch_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(HistoricalSalesDocument::class, 'historical_import_batch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
