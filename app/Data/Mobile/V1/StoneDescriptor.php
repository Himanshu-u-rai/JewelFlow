<?php

namespace App\Data\Mobile\V1;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Stone descriptor — Class C in the pricing-class taxonomy.
 *
 * Stones are NOT metals. They carry a flat stone_amount on the item and
 * never participate in fine-weight or daily-rate flows. This descriptor
 * is constant per the contract — every shop's snapshot includes it.
 */
#[TypeScript]
class StoneDescriptor extends Data
{
    public function __construct(
        public string $pricing_class = 'C',
        public string $value_field = 'stone_amount',
        public string $purity_selector_mode = 'hidden',
    ) {}
}
