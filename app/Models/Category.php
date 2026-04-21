<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use BelongsToShop;

    protected static function booted()
    {
        static::creating(function ($category) {
            if (empty($category->slug)) {
                $category->slug = \Illuminate\Support\Str::slug($category->name);
            }

            if (empty($category->normalized_name)) {
                $category->normalized_name = static::normalizeName((string) $category->name);
            }
        });

        static::updating(function ($category) {
            if ($category->isDirty('name')) {
                $category->normalized_name = static::normalizeName((string) $category->name);
            }
        });
    }
    protected $fillable = [
        'name',
        'slug',
        'normalized_name',
    ];

    public function subCategories()
    {
        return $this->hasMany(SubCategory::class);
    }

    private static function normalizeName(string $value): string
    {
        $value = trim($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value);

        return $value ?? '';
    }
}
