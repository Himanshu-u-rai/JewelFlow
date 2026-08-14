<?php

namespace App\Models\Historical;

use App\Models\Concerns\BelongsToShop;
use App\Models\Shop;
use App\Models\User;
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
    ];

    protected $casts = [
        'cutover_date'    => 'date',
        'totals_snapshot' => 'array',
        'published_at'    => 'datetime',
        'cancelled_at'    => 'datetime',
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
