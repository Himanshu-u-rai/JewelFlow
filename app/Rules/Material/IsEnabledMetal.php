<?php

namespace App\Rules\Material;

use App\Services\MetalRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates that a metal_type value is one of the shop's currently enabled
 * metals. The authoritative list is MetalRegistry::enabledMetalsForShop().
 *
 * MUST NEVER hardcode metal names. The list is shop-specific and capability
 * driven (Tier 1 auto-enabled, Tier 2 opt-in via Settings → Materials).
 *
 * @see CONSTITUTION.md Article XV — MetalRegistry Authority
 */
final class IsEnabledMetal implements ValidationRule
{
    public function __construct(private int $shopId)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $enabled = MetalRegistry::enabledMetalsForShop($this->shopId);

        if (! is_string($value) || $value === '') {
            $fail("The {$attribute} must be one of: " . implode(', ', $enabled) . '.');
            return;
        }

        $normalized = strtolower(trim($value));

        if (! in_array($normalized, $enabled, true)) {
            $fail(
                "'{$value}' is not enabled for this shop. Allowed metals: "
                . implode(', ', $enabled) . '. Enable more from Settings → Materials.'
            );
        }
    }
}
