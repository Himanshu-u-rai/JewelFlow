<?php

namespace App\Rules\Inventory;

use App\Models\Item;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Barcode uniqueness inside one shop's inventory. Mirrors the previous
 * Rule::unique('items','barcode')->where('shop_id', $shopId) used in
 * both web and mobile item controllers. The optional $ignoreItemId
 * enables the same rule to be used on update flows.
 *
 * Uses Item::withoutTenant() so the rule works regardless of the
 * BelongsToShop global scope context.
 */
final class UniqueBarcodeForShop implements ValidationRule
{
    public function __construct(
        private int $shopId,
        private ?int $ignoreItemId = null,
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // 'required' / 'string' rules handle missing values.
        }

        $exists = Item::withoutTenant()
            ->where('shop_id', $this->shopId)
            ->where('barcode', $value)
            ->when($this->ignoreItemId, fn ($q) => $q->where('id', '!=', $this->ignoreItemId))
            ->exists();

        if ($exists) {
            $fail("Barcode '{$value}' is already used by another item in this shop.");
        }
    }
}
