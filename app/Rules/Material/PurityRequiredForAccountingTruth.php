<?php

namespace App\Rules\Material;

use App\Services\MetalRegistry;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Purity is REQUIRED when the metal's purity is accounting truth
 * (gold/silver — purity drives fine-weight). It is OPTIONAL for metals
 * whose purity is not accounting truth (platinum spec / copper grade).
 *
 * Constructor accepts either a string metal_type or a Closure returning
 * the metal_type, so dependent fields (validated together with this one)
 * can be resolved lazily at validation time.
 *
 * @see MetalRegistry::purityIsAccountingTruth()
 */
final class PurityRequiredForAccountingTruth implements ValidationRule
{
    /**
     * Marks this rule as IMPLICIT so Laravel runs it even when the purity
     * field is absent/empty. Without this, Laravel skips custom rules on
     * empty values and a missing `purity` for a gold item would pass
     * silently — defeating the entire purpose of the rule.
     */
    public bool $implicit = true;

    /** @var string|\Closure */
    private $metalTypeSource;

    /**
     * @param string|\Closure(): ?string $metalTypeSource
     */
    public function __construct(string|Closure $metalTypeSource)
    {
        $this->metalTypeSource = $metalTypeSource;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $metal = $this->metalTypeSource instanceof Closure
            ? ($this->metalTypeSource)()
            : $this->metalTypeSource;

        $metal = is_string($metal) ? trim($metal) : '';

        // If metal_type isn't supported, IsEnabledMetal will fail separately;
        // this rule only governs the purity-required relationship.
        if ($metal === '' || ! MetalRegistry::isSupported($metal)) {
            return;
        }

        $accountingTruth = MetalRegistry::purityIsAccountingTruth($metal);

        $purityProvided = $value !== null && $value !== '';

        if ($accountingTruth && ! $purityProvided) {
            $fail("{$metal} items require a purity value; this is used to compute fine weight.");
            return;
        }

        // Non-accounting metals: purity is optional. Nothing to enforce here.
    }
}
