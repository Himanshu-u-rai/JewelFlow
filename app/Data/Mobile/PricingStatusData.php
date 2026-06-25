<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Pricing readiness for the mobile daily-rate gate. Exposed to EVERY
 * authenticated mobile user (via /bootstrap) so staff/cashier roles can
 * learn whether the owner has set today's rates WITHOUT needing access to
 * the reports-gated /dashboard endpoint. Carries only rate-readiness — no
 * revenue, profit, KPI, or report data.
 */
#[TypeScript]
class PricingStatusData extends Data
{
    public function __construct(
        public bool $pricing_ready,
        public bool $rates_set_today,
        public ?float $gold_24k_rate_per_gram,
        public ?float $silver_999_rate_per_gram,
        public ?string $business_date,
    ) {}
}
