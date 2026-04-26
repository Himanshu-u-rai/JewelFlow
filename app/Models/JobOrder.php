<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class JobOrder extends Model
{
    use BelongsToShop;

    public const STATUS_ISSUED = 'issued';
    public const STATUS_PARTIAL_RETURN = 'partial_return';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const FLAG_EXCESS_WASTAGE = 'EXCESS_WASTAGE';
    public const FLAG_SHORT_RETURN = 'SHORT_RETURN';
    public const FLAG_EXCESS_RETURN = 'EXCESS_RETURN';
    public const FLAG_INVOICE_MISMATCH = 'INVOICE_MISMATCH';

    protected $fillable = [
        'shop_id',
        'karigar_id',
        'job_order_number',
        'challan_number',
        'metal_type',
        'purity',
        'issued_gross_weight',
        'issued_fine_weight',
        'expected_return_fine_weight',
        'allowed_wastage_percent',
        'status',
        'issue_date',
        'expected_return_date',
        'completed_at',
        'returned_gross_weight',
        'returned_fine_weight',
        'leftover_returned_fine_weight',
        'actual_wastage_fine',
        'discrepancy_flags',
        'discrepancy_acknowledged',
        'notes',
        'advance_amount',
        'advance_mode',
        'advance_payment_method_id',
        'created_by_user_id',
    ];

    protected $casts = [
        'purity' => 'decimal:2',
        'issued_gross_weight' => 'decimal:6',
        'issued_fine_weight' => 'decimal:6',
        'expected_return_fine_weight' => 'decimal:6',
        'allowed_wastage_percent' => 'decimal:2',
        'returned_gross_weight' => 'decimal:6',
        'returned_fine_weight' => 'decimal:6',
        'leftover_returned_fine_weight' => 'decimal:6',
        'actual_wastage_fine' => 'decimal:6',
        'issue_date' => 'date',
        'expected_return_date' => 'date',
        'completed_at' => 'datetime',
        'discrepancy_flags' => 'array',
        'discrepancy_acknowledged' => 'boolean',
        'advance_amount' => 'decimal:2',
    ];

    public function karigar()
    {
        return $this->belongsTo(Karigar::class);
    }

    public function issuances()
    {
        return $this->hasMany(JobOrderIssuance::class);
    }

    public function receipts()
    {
        return $this->hasMany(JobOrderReceipt::class);
    }

    public function receiptItems()
    {
        return $this->hasManyThrough(JobOrderReceiptItem::class, JobOrderReceipt::class);
    }

    public function invoices()
    {
        return $this->hasMany(KarigarInvoice::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [
            self::STATUS_ISSUED,
            self::STATUS_PARTIAL_RETURN,
        ], true);
    }

    // FIX #4: Laravel accessor requires the get…Attribute naming convention
    public function getIsOverdueAttribute(): bool
    {
        return $this->expected_return_date
            && $this->isOpen()
            && $this->expected_return_date->isPast();
    }

    public function getAllowedWastageFineAttribute(): float
    {
        return (float) $this->issued_fine_weight * (float) $this->allowed_wastage_percent / 100;
    }

    public function getOutstandingFineAttribute(): float
    {
        return max(
            0.0,
            (float) $this->issued_fine_weight
                - (float) $this->returned_fine_weight
                - (float) $this->leftover_returned_fine_weight
                - (float) $this->actual_wastage_fine
        );
    }
}
