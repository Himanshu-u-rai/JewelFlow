<?php

namespace App\Services\Dhiran;

/**
 * The pledged items of a loan, checked the same way on New Loan and in Old Loans: every loan has at least one item
 * (no loan without collateral); gold and silver are valued from a supported purity, gross weight and a rate per gram;
 * other pieces (diamonds, platinum, watches) carry an appraised value. The service stays the final authority.
 */
final class DhiranCollateral
{
    public const PURITIES = [
        'gold'   => [24, 22, 20, 18, 14],
        'silver' => [999, 958, 925, 900, 800],
    ];

    public static function rules(): array
    {
        return [
            'items'                           => 'required|array|min:1',
            'items.*.description'             => 'required|string|max:255',
            // gold / silver = metal-valued (purity → fine weight). other = manually appraised.
            'items.*.metal_type'              => 'nullable|in:gold,silver,other',
            'items.*.value_mode'              => 'nullable|in:metal,appraised',
            'items.*.category'                => 'nullable|string|max:100',
            'items.*.quantity'                => 'nullable|integer|min:1|max:1000',
            'items.*.purity'                  => 'nullable|numeric|min:1|max:1000',
            'items.*.gross_weight'            => 'nullable|numeric|min:0|max:9999.999999',
            'items.*.stone_weight'            => 'nullable|numeric|min:0|max:9999.999999',
            'items.*.rate_per_gram_at_pledge' => 'nullable|numeric|min:0|max:999999.9999',
            'items.*.market_value'            => 'nullable|numeric|min:0|max:999999999.99',
            'items.*.huid'                    => 'nullable|string|max:50',
        ];
    }

    /** Whether any metal-valued item of $metal needs that metal's rate on the loan. */
    public static function needsRate(array $items, string $metal): bool
    {
        return collect($items)->contains(fn ($i) => ($i['metal_type'] ?? 'gold') === $metal && (($i['value_mode'] ?? 'metal') !== 'appraised'));
    }

    /** Per-item problems the rules cannot express, keyed like validation errors; empty when every item can be valued. */
    public static function errors(array $items): array
    {
        $errors = [];
        foreach ($items as $idx => $row) {
            $metal = $row['metal_type'] ?? 'gold';
            if (! in_array($metal, ['gold', 'silver', 'other'], true)) {
                $errors["items.{$idx}.metal_type"] = 'This collateral type is not supported. Choose gold, silver, or other.';
                continue;
            }
            if (($metal === 'other' ? 'appraised' : ($row['value_mode'] ?? 'metal')) === 'appraised') {
                if ((float) ($row['market_value'] ?? 0) <= 0) {
                    $errors["items.{$idx}.market_value"] = 'Enter an appraised market value for this item.';
                }
                continue;
            }
            if (! in_array((int) round((float) ($row['purity'] ?? 0)), self::PURITIES[$metal], true)) {
                $errors["items.{$idx}.purity"] = "Selected purity is not supported for {$metal}.";
            }
            if ((float) ($row['gross_weight'] ?? 0) <= 0) {
                $errors["items.{$idx}.gross_weight"] = 'Gross weight is required for a metal-valued item.';
            }
            if (! isset($row['rate_per_gram_at_pledge']) || $row['rate_per_gram_at_pledge'] === '') {
                $errors["items.{$idx}.rate_per_gram_at_pledge"] = 'Rate per gram is required for a metal-valued item.';
            }
        }

        return $errors;
    }

    /** Items as the service takes them: metal and value mode settled ('other' is always appraised). */
    public static function normalise(array $items): array
    {
        return array_map(fn ($i) => array_merge($i, [
            'metal_type' => $i['metal_type'] ?? 'gold',
            'value_mode' => (($i['metal_type'] ?? 'gold') === 'other') ? 'appraised' : ($i['value_mode'] ?? 'metal'),
        ]), array_values($items));
    }
}
