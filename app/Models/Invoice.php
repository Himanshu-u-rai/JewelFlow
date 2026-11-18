<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Reporting\Concerns\HasSalesScopes;
use App\Services\AccountingAuditService;
use App\Services\BusinessIdentifierService;
use LogicException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Invoice extends Model
{
    use BelongsToShop;
    use HasSalesScopes;

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
        'round_off' => 'decimal:4',
        'total' => 'decimal:2',
        'finalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Seller attribution (#16): capture who created the invoice, at creation
        // only. This is the draft write — never the finalized/locked path — so it
        // touches no accounting figure, guard, or trigger.
        static::creating(function ($invoice) {
            if (empty($invoice->user_id) && auth()->check()) {
                $invoice->user_id = auth()->id();
            }
        });

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

    /** Owning shop. shop_id is always set; lets callers eager-load shop.preferences. */
    public function shop()
    {
        return $this->belongsTo(\App\Models\Shop::class);
    }

    /** The operator who created (sold) this invoice (#16). Nullable for legacy rows. */
    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
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

    public function complianceSnapshot()
    {
        return $this->hasOne(\App\Models\InvoiceComplianceSnapshot::class);
    }

    public static function issue(array $attributes): self
    {
        // The number, its financial year and series come from the database trigger (invoices_numbering_guard →
        // next_invoice_identity): one implementation, judged on the document's own date.
        if (empty($attributes['invoice_sequence']) || empty($attributes['invoice_number'])) {
            unset($attributes['invoice_sequence'], $attributes['invoice_number']);
        }
        unset($attributes['fiscal_year'], $attributes['invoice_series']);

        $invoice = new self();
        $invoice->forceFill($attributes);
        $invoice->save();

        // Callers read the number straight away (receipts, messages, links): load what the trigger assigned.
        $assigned = DB::table('invoices')->where('id', $invoice->id)
            ->first(['invoice_number', 'invoice_sequence', 'fiscal_year', 'invoice_series']);
        $invoice->forceFill((array) $assigned)->syncOriginal();

        return $invoice;
    }

    /**
     * What a customer has spent: finished (finalized) bills only — drafts are not
     * sales and a fully returned bill is cancelled. Same figure on the web
     * customer page and the app.
     */
    public static function spentByCustomer(int $shopId, int $customerId): float
    {
        return (float) self::where('shop_id', $shopId)
            ->where('customer_id', $customerId)
            ->where('status', self::STATUS_FINALIZED)
            // A reversal bill takes a cancelled bill back; the cancelled original is already left out above, so
            // counting the (negative) reversal as well would take the same bill off twice.
            ->whereNull('reversal_of_invoice_id')
            ->sum('total');
    }
}
