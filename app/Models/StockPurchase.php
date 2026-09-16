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
        'invoice_image_disk',
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
        'stocked_at',
        'stocked_by_user_id',
    ];

    protected $casts = [
        'invoice_date'      => 'date',
        'purchase_date'     => 'date',
        'confirmed_at'      => 'datetime',
        'stocked_at'        => 'datetime',
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

    /**
     * Disk for NEW invoice attachments (audit finding S3-03).
     *
     * Private. Reads must dispatch on the per-row invoice_image_disk, never on
     * this constant, because rows written before the fix are physically on the
     * 'public' disk and stay there until the separately-approved relocation runs.
     */
    public const ATTACHMENT_DISK = 'local';

    public function hasInvoiceImage(): bool
    {
        return filled($this->invoice_image) && filled($this->invoice_image_disk);
    }

    /**
     * The authenticated download URL, or null when there is no attachment.
     *
     * Deliberately NOT Storage::url(). That helper resolves against the default
     * disk and, for a local disk with no 'url' key, returns an unsigned
     * /storage/{path} which nginx serves off the public symlink with no auth.
     * Emitting a route instead is what keeps the bytes behind PHP.
     */
    public function invoiceImageUrl(): ?string
    {
        return $this->hasInvoiceImage()
            ? route('inventory.purchases.invoice-image', $this)
            : null;
    }

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

    public function stockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'stocked_by_user_id');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeStocked($query)
    {
        return $query->where('status', 'stocked');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isStocked(): bool
    {
        return $this->status === 'stocked';
    }

    public function getSupplierLabelAttribute(): string
    {
        return $this->supplier_name ?: ($this->vendor?->name ?? '—');
    }
}
