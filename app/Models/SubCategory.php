<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class SubCategory extends Model
{
    use BelongsToShop;

    protected static function booted()
    {
        static::creating(function ($subCategory) {
            if (empty($subCategory->slug)) {
                $subCategory->slug = \Illuminate\Support\Str::slug($subCategory->name);
            }

            if (empty($subCategory->normalized_name)) {
                $subCategory->normalized_name = static::normalizeName((string) $subCategory->name);
            }
        });

        static::updating(function ($subCategory) {
            if ($subCategory->isDirty('name')) {
                $subCategory->normalized_name = static::normalizeName((string) $subCategory->name);
            }
        });
    }
    protected $fillable = [
        'category_id',
        'name',
        'normalized_name',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    private static function normalizeName(string $value): string
    {
        $value = trim($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return $value ?? '';
    }
}
