<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = ['*'];

    /**
     * MC-1: cast only the additive making-charge mode value. Existing columns
     * keep their raw representation (this model deliberately carried no casts).
     */
    protected $casts = [
        'making_charge_value' => 'decimal:2',
    ];

    /**
     * Columns that may legitimately mutate after the invoice is finalized.
     * This list MIRRORS the DB trigger invoice_items_finalized_guard_trigger
     * (migration 2026_05_17_120000), which already permits exactly these
     * post-finalize lifecycle/allocation columns. The Eloquent guard below
     * was previously a blanket throw that contradicted the DB layer and made
     * the return/exchange "mark as returned" save crash. Aligning the two does
     * NOT weaken immutability: the DB trigger remains the final backstop, and
     * every financial/identity column (weight, rate, line_total, gst_*, making
     * charges, stone_amount, quantity, invoice_id, item_id, …) still throws.
     */
    protected array $allowedUpdateColumns = [
        'allocated_discount',
        'allocated_round_off',
        'allocated_loyalty_pts',
        'returned_at',
        'return_line_item_id',
        'updated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function ($line) {
            $blocked = array_diff(array_keys($line->getDirty()), $line->allowedUpdateColumns);
            if (! empty($blocked)) {
                throw new LogicException(
                    'Invoice items are immutable except for return/allocation columns; blocked: '
                    . implode(', ', $blocked)
                );
            }
        });

        static::deleting(function ($line) {
            throw new LogicException('Invoice items are immutable.');
        });

        static::creating(function ($line) {
            $invoice = \App\Models\Invoice::find($line->invoice_id);
            if ($invoice && in_array($invoice->status, [Invoice::STATUS_FINALIZED, Invoice::STATUS_CANCELLED], true)) {
                throw new LogicException('Cannot attach line items to finalized/cancelled invoice.');
            }
        });
    }

    public static function record(array $attributes): self
    {
        $line = new self();
        $line->forceFill($attributes);
        $line->save();

        return $line;
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }

    public function item()
    {
        return $this->belongsTo(\App\Models\Item::class);
    }
}
