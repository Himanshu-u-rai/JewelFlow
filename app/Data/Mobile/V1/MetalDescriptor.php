<?php

namespace App\Data\Mobile\V1;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Per-metal capability descriptor exposed by GET /api/mobile/v1/registry/materials.
 *
 * This is a read-only snapshot of MetalRegistry capabilities for the
 * shop's enabled metals. Mobile clients consume this to drive UX
 * (purity-field rendering, fine-weight calculation, reference-price hints)
 * without ever hardcoding metal names or rules.
 */
#[TypeScript]
class MetalDescriptor extends Data
{
    public function __construct(
        public string $identity_class,
        public string $pricing_class,
        public string $purity_selector_mode,
        public string $purity_label,
        public bool $purity_is_accounting_truth,
        public bool $fine_weight_supported,
        public bool $reference_price_supported,
        /** @var array<int, float|int> */
        public array $active_purity_profiles,
    ) {}
}
