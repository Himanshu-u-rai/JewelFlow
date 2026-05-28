<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 2B — Append-only audit ledger for stone revaluations.
 *
 * Every operator-initiated revaluation of a stone in inventory
 * produces one row here. The DB trigger
 * (stone_revaluation_events_append_only_trigger, Constitutional #23)
 * is the authoritative protection; the ImmutableLedger trait below
 * is an Eloquent-layer mirror that fails fast with a clearer message
 * before the SQL round-trip.
 *
 * Created exclusively by StoneRevaluationService — no other code path
 * should INSERT here. Constitutional Article XIV enforcement.
 */
class StoneRevaluationEvent extends Model
{
    use BelongsToShop;
    use ImmutableLedger;

    protected $fillable = [
        'shop_id',
        'stone_component_id',
        'old_unit_value',
        'old_count',
        'old_total_value',
        'new_unit_value',
        'new_count',
        'new_total_value',
        'delta_total_value',
        'reason',
        'reevaluated_by_user_id',
    ];

    protected $casts = [
        'old_unit_value'    => 'decimal:2',
        'old_count'         => 'integer',
        'old_total_value'   => 'decimal:2',
        'new_unit_value'    => 'decimal:2',
        'new_count'         => 'integer',
        'new_total_value'   => 'decimal:2',
        'delta_total_value' => 'decimal:2',
    ];

    /**
     * Append-only: zero columns mutable after insert.
     */
    protected $allowedUpdateColumns = [];

    public static function record(array $attributes): self
    {
        $row = new self();
        $row->forceFill($attributes);
        $row->save();
        return $row;
    }

    public function stoneComponent(): BelongsTo
    {
        return $this->belongsTo(StoneComponent::class);
    }

    public function reevaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reevaluated_by_user_id');
    }
}
