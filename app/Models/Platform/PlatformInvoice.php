<?php

namespace App\Models\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shop;

class PlatformInvoice extends Model
{
    protected $fillable = [
        'shop_id',
        'shop_subscription_id',
        'plan_id',
        'invoice_number',
        'invoice_sequence',
        'billing_cycle',
        'billing_period_start',
        'billing_period_end',
        'amount_before_tax',
        'gst_rate',
        'gst_amount',
        'total_amount',
        'razorpay_payment_id',
        'razorpay_order_id',
        'payment_method',
        'status',
        'cancelled_at',
        'cancellation_reason',
        'issued_at',
        'created_by_admin_id',
        'notes',
    ];

    protected $casts = [
        'billing_period_start' => 'date',
        'billing_period_end'   => 'date',
        'issued_at'            => 'datetime',
        'cancelled_at'         => 'datetime',
        'amount_before_tax'    => 'decimal:2',
        'gst_amount'           => 'decimal:2',
        'total_amount'         => 'decimal:2',
        'gst_rate'             => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $originalStatus = $invoice->getOriginal('status');

            // Cancelled invoices: fully immutable
            if ($originalStatus === 'cancelled') {
                throw new \LogicException('Cancelled platform invoices cannot be modified.');
            }

            // Issued invoices: only allow transitioning to cancelled
            if ($originalStatus === 'issued') {
                $dirty   = array_keys($invoice->getDirty());
                $allowed = ['status', 'cancelled_at', 'cancellation_reason'];
                if (! empty(array_diff($dirty, $allowed))) {
                    throw new \LogicException('Issued platform invoices are immutable. Cancel and re-issue instead.');
                }
            }
        });

        static::deleting(function (): void {
            throw new \LogicException('Platform invoices cannot be deleted.');
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(ShopSubscription::class, 'shop_subscription_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'created_by_admin_id');
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }
}
