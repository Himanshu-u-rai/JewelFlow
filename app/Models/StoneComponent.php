<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Phase 2A — Structured stone component primitive.
 *
 * Replaces the opaque `invoice_items.stone_amount` rupee column with
 * a per-stone structured record.
 *
 * Immutability rules (mirrored from the DB
 * `stone_components_snapshot_guard_trigger`):
 *   - Row tied to an inventory `item_id` only → mutable (item in stock)
 *   - Row tied to a finalized invoice_item → snapshot frozen; only
 *     `notes` may be edited
 *   - Row tied to a settled return_line_item → snapshot frozen; only
 *     `notes` may be edited
 *   - DELETE always blocked once snapshotted
 *
 * Constitutional doctrine:
 *   - Article XIV (Commodity vs Manual Valuation Boundary): stone
 *     values are manual valuations. NO automated process may modify
 *     this table — only operator-initiated writes via the application
 *     UI / service layer.
 *   - Article I (Accounting Primitives Inviolable): once linked to a
 *     finalized parent, the snapshot is immutable.
 */
class StoneComponent extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'item_id',
        'invoice_item_id',
        'return_line_item_id',
        'stone_type',
        'carat_weight',
        'count',
        'unit_value',
        'total_value',
        'notes',
        'migrated_from_legacy',
        // Phase 2B metadata (Article XIV: descriptive only, never affect totals)
        'certificate_id',
        'certificate_authority',
        'grade',
        'supplier_name',
        'photo_path',
    ];

    protected $casts = [
        'carat_weight'         => 'decimal:3',
        'count'                => 'integer',
        'unit_value'           => 'decimal:2',
        'total_value'          => 'decimal:2',
        'migrated_from_legacy' => 'boolean',
    ];

    /**
     * Columns permitted to mutate post-snapshot. Mirrors the allow-list
     * in `stone_components_snapshot_guard_trigger`.
     */
    protected $allowedUpdateColumnsWhenSnapshot = [
        'notes',
        'updated_at',
    ];

    protected static function booted(): void
    {
        // Eloquent-layer mirror of the DB trigger. The trigger is the
        // authoritative backstop; this layer fails fast with a clearer
        // exception message before the SQL round-trip.
        static::updating(function (StoneComponent $row) {
            if (! $row->isSnapshotFrozen()) {
                return;
            }

            $blockedDirty = array_diff(
                array_keys($row->getDirty()),
                $row->allowedUpdateColumnsWhenSnapshot
            );

            if (! empty($blockedDirty)) {
                throw new LogicException(
                    'Stone component snapshot is immutable; only [notes] may be edited. '
                    . 'Blocked fields: ' . implode(', ', $blockedDirty)
                );
            }
        });

        static::deleting(function (StoneComponent $row) {
            if ($row->isSnapshotFrozen()) {
                throw new LogicException(
                    'Stone component snapshot is immutable; rows linked to a finalized '
                    . 'invoice_item or settled return_line_item cannot be deleted.'
                );
            }
        });
    }

    /**
     * Insert factory mirroring MetalMovement::record / InvoiceItem::record.
     * Recommended entry point for service code so the snapshot doctrine is
     * never bypassed by a stray Model::create call.
     */
    public static function record(array $attributes): self
    {
        $row = new self();
        $row->forceFill($attributes);
        $row->save();
        return $row;
    }

    /**
     * Has this row been snapshotted by a parent reaching its final state?
     */
    public function isSnapshotFrozen(): bool
    {
        if ($this->invoice_item_id !== null) {
            $parentStatus = $this->invoiceItem?->invoice?->status;
            if (in_array($parentStatus, ['finalized', 'cancelled'], true)) {
                return true;
            }
        }
        if ($this->return_line_item_id !== null) {
            $parentStatus = $this->returnLineItem?->returnOrder?->status;
            if (in_array($parentStatus, ['settled', 'cancelled'], true)) {
                return true;
            }
        }
        return false;
    }

    // ─── Relationships ────────────────────────────────────────────────

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function returnLineItem(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ReturnLineItem::class);
    }

    /**
     * Phase 2B — revaluation history for this stone. Append-only ledger
     * tied to stone_component_id. Newest first by default.
     */
    public function revaluationEvents()
    {
        return $this->hasMany(\App\Models\StoneRevaluationEvent::class, 'stone_component_id')
            ->orderByDesc('created_at');
    }
}
