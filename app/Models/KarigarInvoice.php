<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class KarigarInvoice extends Model
{
    use BelongsToShop;

    public const MODE_PURCHASE = 'purchase';
    public const MODE_JOB_WORK = 'job_work';

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PARTIAL = 'partial';
    public const PAYMENT_PAID = 'paid';

    protected $fillable = [
        'shop_id',
        'karigar_id',
        'job_order_id',
        'mode',
        'karigar_invoice_number',
        'karigar_invoice_date',
        'state_code',
        'is_interstate',
        'total_pieces',
        'total_gross_weight',
        'total_stone_weight',
        'total_net_weight',
        'total_metal_amount',
        'total_extra_amount',
        'total_making_amount',
        'total_wastage_amount',
        'total_before_tax',
        'cgst_rate',
        'cgst_amount',
        'sgst_rate',
        'sgst_amount',
        'igst_rate',
        'igst_amount',
        'total_tax',
        'total_after_tax',
        'amount_in_words',
        'tax_amount_in_words',
        'jurisdiction',
        'payment_terms',
        'payment_status',
        'amount_paid',
        'invoice_file_path',
        'discrepancy_flags',
        'created_by_user_id',
    ];

    protected $casts = [
        'karigar_invoice_date' => 'date',
        'is_interstate' => 'boolean',
        'total_pieces' => 'integer',
        'total_gross_weight' => 'decimal:6',
        'total_stone_weight' => 'decimal:6',
        'total_net_weight' => 'decimal:6',
        'total_metal_amount' => 'decimal:2',
        'total_extra_amount' => 'decimal:2',
        'total_making_amount' => 'decimal:2',
        'total_wastage_amount' => 'decimal:2',
        'total_before_tax' => 'decimal:2',
        'cgst_rate' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_rate' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_rate' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'total_tax' => 'decimal:2',
        'total_after_tax' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'discrepancy_flags' => 'array',
    ];

    public function karigar()
    {
        return $this->belongsTo(Karigar::class);
    }

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function lines()
    {
        return $this->hasMany(KarigarInvoiceLine::class);
    }

    public function payments()
    {
        return $this->hasMany(KarigarPayment::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function getAmountDueAttribute(): float
    {
        return max(0.0, (float) $this->total_after_tax - (float) $this->amount_paid);
    }

    public function isJobWorkMode(): bool
    {
        return $this->mode === self::MODE_JOB_WORK;
    }
}
