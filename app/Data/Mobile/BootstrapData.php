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
    ) {}
}
