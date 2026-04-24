<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class InvoiceOfferApplication extends Model
{
    use BelongsToShop;

    protected $guarded = ['*'];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'auto_applied' => 'boolean',
        'rule_snapshot' => 'array',
        'applied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Invoice offer snapshots are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Invoice offer snapshots cannot be deleted.');
        });
    }

    public static function record(array $attributes): self
    {
        $model = new self();
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function scheme()
    {
        return $this->belongsTo(Scheme::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
