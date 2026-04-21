<?php

namespace App\Models\Dhiran;

use App\Models\Concerns\BelongsToShop;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DhiranLoanItem extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'shop_id',
        'dhiran_loan_id',
        'description',
        'category',
        'metal_type',
        'quantity',
        'gross_weight',
        'stone_weight',
        'net_metal_weight',
        'purity',
        'fine_weight',
        'rate_per_gram_at_pledge',
        'market_value',
        'loan_value',
        'photo_path',
        'huid',
        'status',
        'released_at',
        'release_condition_note',
        'released_by',
        'forfeited_at',
    ];

    protected $casts = [
        'gross_weight'            => 'decimal:6',
        'stone_weight'            => 'decimal:6',
        'net_metal_weight'        => 'decimal:6',
        'purity'                  => 'decimal:2',
        'fine_weight'             => 'decimal:6',
        'rate_per_gram_at_pledge' => 'decimal:4',
        'market_value'            => 'decimal:2',
        'loan_value'              => 'decimal:2',
        'released_at'             => 'datetime',
        'forfeited_at'            => 'datetime',
        'quantity'                => 'integer',
    ];

    /* ── Relationships ─────────────────────────────────── */

    public function loan()
    {
        return $this->belongsTo(DhiranLoan::class, 'dhiran_loan_id');
    }

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function releasedBy()
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /* ── Scopes ────────────────────────────────────────── */

    public function scopePledged(Builder $query): Builder
    {
        return $query->where('status', 'pledged');
    }

    public function scopeReleased(Builder $query): Builder
    {
        return $query->where('status', 'released');
    }

    public function scopeForfeited(Builder $query): Builder
    {
        return $query->where('status', 'forfeited');
    }
}
