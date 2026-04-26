<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class JobOrderReceipt extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'job_order_id',
        'receipt_number',
        'receipt_date',
        'total_pieces',
        'total_gross_weight',
        'total_stone_weight',
        'total_net_weight',
        'total_fine_weight',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'receipt_date' => 'date',
        'total_pieces' => 'integer',
        'total_gross_weight' => 'decimal:6',
        'total_stone_weight' => 'decimal:6',
        'total_net_weight' => 'decimal:6',
        'total_fine_weight' => 'decimal:6',
    ];

    public function jobOrder()
    {
        return $this->belongsTo(JobOrder::class);
    }

    public function items()
    {
        return $this->hasMany(JobOrderReceiptItem::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
