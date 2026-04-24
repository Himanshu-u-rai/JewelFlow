<?php

namespace App\Models;

use LogicException;
use Illuminate\Database\Eloquent\Model;

class MetalRate extends Model
{
    protected $table = 'metal_rates';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'rate_per_gram' => 'decimal:4',
        'purity_value' => 'decimal:3',
        'business_date' => 'date',
        'is_override' => 'boolean',
        'fetched_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('metal_rates is append-only. Update is not allowed.');
        });

        static::deleting(function () {
            throw new LogicException('metal_rates is append-only. Delete is not allowed.');
        });
    }

    public static function record(array $attributes): self
    {
        $rate = new self();
        $rate->forceFill($attributes);
        $rate->save();

        return $rate;
    }

    public static function latestFor(string $metal, string $purity, ?int $shopId = null): ?self
    {
        $query = static::query()
            ->where('metal_type', $metal)
            ->where('purity', $purity);

        if ($shopId !== null) {
            $query->where(function ($q) use ($shopId): void {
                $q->where('shop_id', $shopId)
                    ->orWhereNull('shop_id');
            })->orderByRaw('CASE WHEN shop_id = ? THEN 0 ELSE 1 END', [$shopId]);
        }

        return $query
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();
    }

    public static function latestResolvedForDay(
        int $shopId,
        string $businessDate,
        string $metalType,
        float $purityValue
    ): ?self {
        return static::query()
            ->where('shop_id', $shopId)
            ->where('business_date', $businessDate)
            ->where('metal_type', $metalType)
            ->where('purity_value', (float) number_format($purityValue, 3, '.', ''))
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first();
    }
}
