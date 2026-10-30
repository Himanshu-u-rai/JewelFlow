<?php

namespace App\Data\Mobile;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class BootstrapData extends Data
{
    public function __construct(
        public UserSummaryData $user,
        public ShopSummaryData $shop,
        public CapabilitiesData $capabilities,
        public AppConfigData $config,
        public PricingStatusData $pricing,
        public ShopAccessData $shop_access,
        /**
         * Why each capability that is off is off: {key: {reason: 'plan'|'permission'|'role'|'disabled'}}.
         * The app tells only the owner (plan, turned off); staff just see "Not available".
         * @var array<string, array<string, string>>
         */
        public array $capability_reasons = [],
    ) {}
}
