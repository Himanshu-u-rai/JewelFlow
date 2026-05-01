<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use App\Services\BusinessIdentifierService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Repair extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'customer_id',
        'item_description',
        'description',
        'image_path',
        'image',
        'due_date',
        'gross_weight',
        'purity',
        'estimated_cost',
        'final_cost',
        'status',
        'gold_issued_fine',
        'gold_returned_fine'
    ];

    protected $casts = [
        'gross_weight' => 'decimal:6',
        'purity' => 'decimal:2',
        'estimated_cost' => 'decimal:2',
        'final_cost' => 'decimal:2',
        'due_date' => 'date',
    ];

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
