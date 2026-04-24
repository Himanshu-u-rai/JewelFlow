<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\AccountingAuditService;
use App\Services\BusinessIdentifierService;
use LogicException;
use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    use BelongsToShop;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_FINALIZED = 'finalized';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'status',
        'cancellation_reason',
    ];

    protected $casts = [
        'invoice_sequence' => 'integer',
        'gold_rate' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'gst' => 'decimal:2',
        'gst_rate' => 'decimal:2',
        'wastage_charge' => 'decimal:2',
        'discount' => 'decimal:2',
        'round_off' => 'decimal:2',
        'total' => 'decimal:2',
        'finalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function ($invoice) {
            if (auth()->check()) {
                AccountingAuditService::log([
                    'shop_id' => $invoice->shop_id,
                    'action' => 'financial_mutation_delete_blocked',
                    'model_type' => 'invoice',
                    'model_id' => $invoice->id,
                    'description' => 'Invoice delete blocked.',
                    'before' => $invoice->getOriginal(),
                    'after' => $invoice->getAttributes(),
                    'target' => ['type' => 'invoice', 'id' => $invoice->id],
                ]);
            }
            throw new LogicException('Invoices are immutable and cannot be deleted.');
        });

        static::updating(function ($invoice) {
            $originalStatus = $invoice->getOriginal('status');
            if (in_array($originalStatus, [self::STATUS_FINALIZED, self::STATUS_CANCELLED], true)) {
                if (auth()->check()) {
                    AccountingAuditService::log([
                        'shop_id' => $invoice->shop_id,
                        'action' => 'financial_mutation_update_blocked',
                        'model_type' => 'invoice',
                        'model_id' => $invoice->id,
                        'description' => 'Finalized/cancelled invoice mutation blocked.',
                        'before' => $invoice->getOriginal(),
                        'after' => $invoice->getAttributes(),
                        'target' => ['type' => 'invoice', 'id' => $invoice->id],
                    ]);
                }
                throw new LogicException('Finalized/cancelled invoices cannot be edited. Use reversal flow.');
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function items()
    {
        return $this->hasMany(\App\Models\InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(\App\Models\InvoicePayment::class);
    }

    public function offerApplication()
    {
        return $this->hasOne(\App\Models\InvoiceOfferApplication::class);
    }

    public function schemeRedemptions()
    {
        return $this->hasMany(\App\Models\SchemeRedemption::class);
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_invoice_id');
    }

    public function reversedBy()
    {
        return $this->belongsTo(self::class, 'reversed_by_invoice_id');
    }

    public static function issue(array $attributes): self
    {
        if (!empty($attributes['shop_id']) && (empty($attributes['invoice_sequence']) || empty($attributes['invoice_number']))) {
            $identity = BusinessIdentifierService::nextInvoiceIdentifier((int) $attributes['shop_id']);
            $attributes['invoice_sequence'] = $attributes['invoice_sequence'] ?? $identity['sequence'];
            $attributes['invoice_number'] = $attributes['invoice_number'] ?? $identity['number'];
        }

        $invoice = new self();
        $invoice->forceFill($attributes);
        $invoice->save();

        return $invoice;
    }
}
