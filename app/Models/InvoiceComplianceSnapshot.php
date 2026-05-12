<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class InvoiceComplianceSnapshot extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'invoice_id',
        'customer_id',
        'snapshot_customer_name',
        'snapshot_pan',
        'snapshot_id_number',
        'snapshot_mobile',
        'snapshot_address',
        'invoice_total',
        'threshold_at_sale',
        'compliance_required',
        'compliance_met',
        'consent_given',
        'completed_by_user_id',
        'completed_at',
        'same_day_invoice_count',
        'same_day_total',
        'split_alert_raised',
        'notes',
    ];

    protected $casts = [
        'invoice_total'         => 'decimal:2',
        'threshold_at_sale'     => 'decimal:2',
        'same_day_total'        => 'decimal:2',
        'compliance_required'   => 'boolean',
        'compliance_met'        => 'boolean',
        'consent_given'         => 'boolean',
        'split_alert_raised'    => 'boolean',
        'completed_at'          => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn() => throw new LogicException('Compliance snapshots are immutable.'));
        static::deleting(fn() => throw new LogicException('Compliance snapshots cannot be deleted.'));
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
