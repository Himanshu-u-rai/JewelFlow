<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Eloquent\Model;

class MetalLot extends Model
{
    use BelongsToShop;

    public const SOURCE_OLD_GOLD_WEEKLY   = 'old_gold_weekly';
    public const SOURCE_OLD_SILVER_WEEKLY = 'old_silver_weekly';
    /** A per-karigar holding lot — metal physically with a karigar, not in the vault. */
    public const SOURCE_KARIGAR_HELD      = 'karigar_held';

    protected $fillable = [
        'vendor_id',
        'karigar_id',
        'metal_type',
        'source',
        'purity',
        'fine_weight_total',
        'fine_weight_remaining',
        'cost_per_fine_gram',
        'notes',
        'iso_year',
        'iso_week',
        'is_dispatched',
        'dispatched_at',
        'dispatch_notes',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function karigar()
    {
        return $this->belongsTo(Karigar::class);
    }

    /** Holding lots only — metal physically retained by a karigar. */
    public function scopeKarigarHeld($query)
    {
        return $query->where('source', self::SOURCE_KARIGAR_HELD);
    }

    /** Everything physically in the vault (excludes karigar-held metal). */
    public function scopeInVault($query)
    {
        return $query->where('source', '<>', self::SOURCE_KARIGAR_HELD);
    }

    protected $casts = [
        'lot_number'            => 'integer',
        'purity'                => 'decimal:2',
        'fine_weight_total'     => 'decimal:6',
        'fine_weight_remaining' => 'decimal:6',
        'cost_per_fine_gram'    => 'decimal:2',
        'iso_year'              => 'integer',
        'iso_week'              => 'integer',
        'is_dispatched'         => 'boolean',
        'dispatched_at'         => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $lot): void {
            if (empty($lot->lot_number) && !empty($lot->shop_id)) {
                $lot->lot_number = BusinessIdentifierService::nextCounter((int) $lot->shop_id, BusinessIdentifierService::KEY_LOT);
            }
        });
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function scopeWeekly($query)
    {
        return $query->whereIn('source', [self::SOURCE_OLD_GOLD_WEEKLY, self::SOURCE_OLD_SILVER_WEEKLY]);
    }

    public function scopeNotDispatched($query)
    {
        return $query->whereRaw('is_dispatched IS FALSE');
    }

    public function payments()
    {
        return $this->hasMany(\App\Models\InvoicePayment::class, 'weekly_lot_id');
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'metal_lot_id');
    }

    public function movements()
    {
        // orWhere on a hasMany leaks across tenants when eager-loaded.
        // Use a whereIn on both FK columns inside a grouped OR instead.
        return $this->hasMany(MetalMovement::class, 'from_lot_id')
            ->orWhere(fn ($q) => $q->where('to_lot_id', $this->id));
    }
}
