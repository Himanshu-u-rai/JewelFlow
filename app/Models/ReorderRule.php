<?php

namespace App\Models;

use App\Models\Concerns\BelongsToShop;
use Illuminate\Database\Eloquent\Model;

class ReorderRule extends Model
{
    use BelongsToShop;

    protected $fillable = [
        'category',
        'sub_category',
        'min_stock_threshold',
        'vendor_id',
        'is_active',
    ];

    protected $casts = [
        'min_stock_threshold' => 'integer',
        'is_active'           => 'boolean',
    ];

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function scopeActive($query)
    {
        return $query->whereRaw($query->qualifyColumn('is_active') . ' IS TRUE');
    }

    /**
     * Count in-stock items that match this rule's category filter,
     * scoped explicitly to this rule's shop so the result is correct
     * even when called from a console/scheduled-job context where the
     * BelongsToShop global scope may not be active.
     */
    public function checkStock(): array
    {
        // Bypass the BelongsToShop global scope and use the rule's own
        // shop_id directly — explicit is safer than relying on auth state.
        $query = Item::withoutTenant()
            ->where('shop_id', $this->shop_id)
            ->where('status', 'in_stock');

        if ($this->category) {
            $query->whereRaw(
                "LOWER(TRIM(COALESCE(category, ''))) = ?",
                [self::normalizeName((string) $this->category)]
            );
        }

        if ($this->sub_category) {
            $query->whereRaw(
                "LOWER(TRIM(COALESCE(sub_category, ''))) = ?",
                [self::normalizeName((string) $this->sub_category)]
            );
        }

        $currentStock = $query->count();

        return [
            'rule_id'         => $this->id,
            'category'        => $this->category,
            'sub_category'    => $this->sub_category,
            'current_stock'   => $currentStock,
            'threshold'       => $this->min_stock_threshold,
            'below_threshold' => $currentStock < $this->min_stock_threshold,
            'vendor'          => $this->vendor,
        ];
    }

    /**
     * Shared normalisation used by both this model and ReorderController.
     * Public so the controller can call ReorderRule::normalizeName() instead
     * of duplicating the logic.
     */
    public static function normalizeName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return trim($value);
    }
}
