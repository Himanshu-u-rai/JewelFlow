<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\Concerns\ImmutableLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnedItemDisposition extends Model
{
    use BelongsToShop;
    use ImmutableLedger;

    public const DISPOSITION_RESTOCKED       = 'restocked';
    public const DISPOSITION_SENT_TO_MELT    = 'sent_to_melt';
    public const DISPOSITION_SENT_TO_REWORK  = 'sent_to_rework';
    public const DISPOSITION_WRITTEN_OFF     = 'written_off';

    // Disposition rows are append-only — each decision is its own row. Multiple
    // dispositions per item over time form the audit trail. Back-linking the
    // target job order and lot after they are created are the only permitted mutations.
    protected $allowedUpdateColumns = ['target_job_order_id', 'target_lot_id'];

    protected $fillable = [
        'shop_id', 'return_line_item_id', 'item_id', 'disposition',
        'target_lot_id', 'target_job_order_id', 'notes',
        'approved_by_user_id', 'approved_at',
        'dispositioned_by_user_id', 'dispositioned_at',
        'evidence_media',
    ];

    protected $casts = [
        'approved_at'      => 'datetime',
        'dispositioned_at' => 'datetime',
        'evidence_media'   => 'array',
    ];

    public function returnLineItem(): BelongsTo
    {
        return $this->belongsTo(ReturnLineItem::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function targetLot(): BelongsTo
    {
        return $this->belongsTo(MetalLot::class, 'target_lot_id');
    }

    public function targetJobOrder(): BelongsTo
    {
        return $this->belongsTo(JobOrder::class, 'target_job_order_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function dispositionedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispositioned_by_user_id');
    }
}
