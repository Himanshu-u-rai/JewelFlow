<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function ($line) {
            throw new LogicException('Invoice items are immutable.');
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
