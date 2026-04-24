<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockPurchase extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'vendor_id',
        'supplier_name',
        'supplier_gstin',
        'purchase_number',
        'invoice_number',
        'invoice_date',
        'purchase_date',
        'status',
        'invoice_image',
        'notes',
        'labour_discount',
        'subtotal_amount',
        'cgst_rate',
        'cgst_amount',
        'sgst_rate',
        'sgst_amount',
        'igst_rate',
        'igst_amount',
        'tcs_amount',
        'total_amount',
        'irn_number',
        'ack_number',
        'entered_by_user_id',
        'confirmed_at',
        'confirmed_by_user_id',
    ];

    protected $casts = [
        'invoice_date'      => 'date',
        'purchase_date'     => 'date',
        'confirmed_at'      => 'datetime',
        'labour_discount'   => 'decimal:2',
        'subtotal_amount'   => 'decimal:2',
        'cgst_rate'         => 'decimal:2',
        'cgst_amount'       => 'decimal:2',
        'sgst_rate'         => 'decimal:2',
        'sgst_amount'       => 'decimal:2',
        'igst_rate'         => 'decimal:2',
        'igst_amount'       => 'decimal:2',
        'tcs_amount'        => 'decimal:2',
        'total_amount'      => 'decimal:2',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockPurchaseItem::class)->orderBy('sort_order');
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by_user_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function getSupplierLabelAttribute(): string
    {
        return $this->supplier_name ?: ($this->vendor?->name ?? '—');
    }
}
