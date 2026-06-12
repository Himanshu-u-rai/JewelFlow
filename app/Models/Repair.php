<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Repair extends Model
{
    use BelongsToShop;

    /** Metal types a repair item can be made of. */
    public const METAL_TYPES = ['gold', 'silver', 'platinum', 'other'];

    public const DEFAULT_METAL_TYPE = 'gold';

    /**
     * Metal-aware purity presets, keyed by metal type. Each entry is
     * { value, label } so clients (web form + mobile) can render the right
     * scale (gold karats vs silver/platinum fineness) without hardcoding.
     * 'other' carries no presets — purity is optional/free for those.
     *
     * @var array<string, array<int, array{value: string, label: string}>>
     */
    public const PURITY_OPTIONS = [
        'gold' => [
            ['value' => '24', 'label' => '24K'],
            ['value' => '22', 'label' => '22K'],
            ['value' => '21', 'label' => '21K'],
            ['value' => '18', 'label' => '18K'],
            ['value' => '14', 'label' => '14K'],
        ],
        'silver' => [
            ['value' => '999', 'label' => '999'],
            ['value' => '925', 'label' => '925'],
            ['value' => '900', 'label' => '900'],
        ],
        'platinum' => [
            ['value' => '999', 'label' => '999'],
            ['value' => '950', 'label' => '950'],
            ['value' => '900', 'label' => '900'],
        ],
        'other' => [],
    ];

    protected $fillable = [
        'customer_id',
        'item_description',
        'description',
        'image_path',
        'image',
        'due_date',
        'metal_type',
        'gross_weight',
        'purity',
        'estimated_cost',
        'final_cost',
        'status',
    ];

    protected $casts = [
        'gross_weight' => 'decimal:6',
        'purity' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'final_cost' => 'decimal:2',
        'due_date' => 'date',
    ];

    /**
     * Upper bound for a valid purity value given a metal type. Gold is karat
     * based (≤24); silver/platinum are fineness based (≤999). Used by both the
     * web and mobile controllers so the rule lives in one place. Unknown/other
     * metals accept the wider fineness range.
     */
    public static function maxPurityFor(?string $metalType): float
    {
        return $metalType === 'gold' ? 24 : 999;
    }

    /**
     * Human label for a stored purity given the item's metal type: gold shows a
     * karat suffix (22K), silver/platinum show fineness as-is (925), other shows
     * the raw value. Returns null when no purity is recorded.
     */
    public function purityLabel(): ?string
    {
        if ($this->purity === null || $this->purity === '') {
            return null;
        }

        $value = (float) $this->purity;

        if ($this->metal_type === 'gold') {
            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . 'K';
        }

        // Silver / platinum / other: fineness or plain number, no karat suffix.
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    protected static function booted(): void
    {
        static::creating(function (self $repair): void {
            if (empty($repair->repair_number) && !empty($repair->shop_id)) {
                $repair->repair_number = BusinessIdentifierService::nextCounter((int) $repair->shop_id, BusinessIdentifierService::KEY_REPAIR);
            }
        });

        static::saving(function (self $repair): void {
            if ($repair->status === 'delivered' && empty($repair->invoice_id)) {
                throw new \DomainException('A repair cannot be marked delivered without a linked invoice. Use the Bill flow.');
            }
        });
    }

    public function customer()
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }

    public function resolveImagePath(): ?string
    {
        $path = $this->image_path ?: $this->image;

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function resolveImageUrl(?string $disk = null): ?string
    {
        $path = $this->resolveImagePath();
        if ($path === null) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return $path;
        }

        $resolvedDisk = $disk ?: 'public';
        $url = Storage::disk($resolvedDisk)->url($path);

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);
    }
}
